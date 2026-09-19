<?php

namespace Solarise\SmartTriage;

use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Solarise\SmartTriage\Exceptions\TriageException;
use Solarise\SmartTriage\Answer;
use Solarise\SmartTriage\Contracts\Judge;
use Solarise\SmartTriage\Questions\Question;

/**
 * Many small records, one shared situation, one request.
 *
 * The fan-out in Judge::askMany() sends one request per record, which is
 * right when each record is substantial — an article, a ticket thread. It is
 * the wrong shape for a firehose of one-line records, because every request
 * re-sends the context those records have in common.
 *
 * This inverts it. The shared context is sent once, the records ride along as
 * a numbered map, and each record gets its own question pointing at its own
 * path. 200 log lines become one request with 200 questions instead of 200
 * requests — and, more to the point, 200 lines from the same minute genuinely
 * do share a situation, so it is also the more truthful way to ask.
 */
class Batch
{
    protected array $context = [];

    /** @var array<string, string|array> */
    protected array $records = [];

    /** The originals behind those records, kept so results can be persisted. */
    protected array $sources = [];

    protected bool $persist = false;

    /** @var array<string, Question> */
    protected array $questions = [];

    protected int $chunk = 100;

    /**
     * State plus the longest single question must stay under 32k tokens. This
     * is the char-budget guard that keeps a chunk clear of it; roughly four
     * characters to the token, halved again for headroom.
     */
    protected int $charBudget = 48_000;

    public function __construct(protected Judge $client) {}

    public static function make(?Judge $client = null): self
    {
        return new self($client ?? app(Judge::class));
    }

    /**
     * The situation every record in this batch shares: what code has already
     * worked out and the model should not try to. Counts, rates, elapsed times,
     * deploy state, tier. The model cannot count and cannot order dates, so anything
     * numeric must arrive already computed.
     */
    public function context(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @param  iterable  $records
     * @param  Closure|null  $as  maps each record to the text the model will read
     * @param  Closure|null  $key  maps each record to its id; defaults to the model key
     */
    public function records(iterable $records, ?Closure $as = null, ?Closure $key = null): self
    {
        foreach ($records as $index => $record) {
            $id = $key
                ? (string) $key($record)
                : (string) (is_object($record) && method_exists($record, 'getKey') ? $record->getKey() : $index);

            $this->records[$id] = $as ? $as($record) : (string) $record;
            $this->sources[$id] = $record;
        }

        return $this;
    }

    /**
     * Also write the answers to the judgements table.
     *
     * Only applies to records that are Triageable models — a batch over plain
     * strings has nothing to attach a row to, and says so rather than guessing.
     */
    public function store(bool $persist = true): self
    {
        $this->persist = $persist;

        return $this;
    }

    /**
     * The questions asked of every record.
     *
     * Write the instruction with a `:record` placeholder — it is replaced with
     * a backticked path to that record's own entry in the state, which is how
     * a question points at one item in a shared payload.
     *
     *   Score::make('How urgently does the event at :record need a human tonight?')
     *
     * @param  array<string, Question>  $questions
     */
    public function ask(array $questions): self
    {
        $this->questions = $questions;

        return $this;
    }

    /** Records per request. Lower it if a chunk trips the state cap. */
    public function chunk(int $records): self
    {
        $this->chunk = max(1, $records);

        return $this;
    }

    /**
     * @return Collection<string, BatchResult>  keyed by record id
     */
    public function run(): Collection
    {
        if ($this->records === []) {
            return collect();
        }

        if ($this->questions === []) {
            throw new InvalidArgumentException('A batch needs at least one question.');
        }

        $requests = [];

        foreach ($this->chunks() as $chunkIndex => $records) {
            $questions = [];

            foreach ($records as $id => $text) {
                foreach ($this->questions as $name => $question) {
                    // The compound key is ours; the model never sees it. The record
                    // path inside the instruction is what does the pointing.
                    $questions[$this->questionKey($id, $name)] = $this->renderQuestion($question, $id);
                }
            }

            $requests[$chunkIndex] = [
                'state' => array_filter([
                    'context' => $this->context,
                    'records' => $records,
                ], fn ($part) => $part !== []),
                'questions' => $questions,
            ];
        }

        $results = $this->collect($this->client->askMany($requests));

        if ($this->persist) {
            $this->writeResults($results);
        }

        return $results;
    }

    /**
     * Split on record count and on the character budget, whichever bites first.
     */
    protected function chunks(): array
    {
        $chunks = [];
        $current = [];
        $currentChars = $this->weigh($this->context);

        foreach ($this->records as $id => $text) {
            $weight = strlen((string) (is_array($text) ? json_encode($text) : $text));

            $wouldOverflow = $current !== []
                && (count($current) >= $this->chunk || $currentChars + $weight > $this->charBudget);

            if ($wouldOverflow) {
                $chunks[] = $current;
                $current = [];
                $currentChars = $this->weigh($this->context);
            }

            $current[$id] = $text;
            $currentChars += $weight;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    protected function weigh(array $part): int
    {
        return $part === [] ? 0 : strlen((string) json_encode($part));
    }

    /**
     * Point a question at one record inside the shared state.
     *
     * A question written for a single-record request says "this event" and
     * means the whole state — which, in a batch, is every record at once. Left
     * alone it does not error, it just quietly answers a different question and
     * returns near-identical numbers for every line. So a question that does
     * not already point somewhere gets scoped here, explicitly.
     */
    protected function renderQuestion(Question $question, string $id): array
    {
        $payload = $question->toPayload();
        $path = "`records.{$id}`";
        $instructions = (string) $payload['instructions'];

        $payload['instructions'] = str_contains($instructions, ':record')
            ? str_replace(':record', $path, $instructions)
            : rtrim($instructions, ' ')." Judge only the record at {$path}, ignoring the others.";

        return $payload;
    }

    protected function questionKey(string $id, string $name): string
    {
        return $id.'::'.$name;
    }

    protected function writeResults(Collection $results): void
    {
        $writer = app(\Solarise\SmartTriage\Support\JudgementWriter::class);

        foreach ($results as $id => $result) {
            $source = $this->sources[$id] ?? null;

            if (! $source instanceof \Illuminate\Database\Eloquent\Model) {
                continue;
            }

            $writer->write(
                model: $source,
                answers: $result->all(),
                jevModel: $result->model,
                inputTokens: $result->batchInputTokens(),
                // A batch's state is the record plus the shared context, so both
                // belong in the fingerprint that decides when it goes stale.
                stateFingerprint: hash('sha256', (string) json_encode([
                    $this->records[$id] ?? null,
                    $this->context,
                ])),
                questionsFingerprint: $this->questionsFingerprint(),
                questions: $this->questions,
            );
        }
    }

    public function questionsFingerprint(): string
    {
        $fingerprints = array_map(fn (Question $q) => $q->fingerprint(), $this->questions);
        ksort($fingerprints);

        return hash('sha256', (string) json_encode($fingerprints));
    }

    /**
     * @param  array<int, mixed>  $responses
     * @return Collection<string, BatchResult>
     */
    protected function collect(array $responses): Collection
    {
        $results = [];
        $inputTokens = 0;

        foreach ($responses as $response) {
            if ($response instanceof TriageException) {
                // One failed chunk should not lose the others; the records in
                // it simply come back unanswered and can be retried.
                continue;
            }

            $inputTokens += $response->inputTokens;

            foreach ($response->answers as $key => $answer) {
                [$id, $name] = array_pad(explode('::', $key, 2), 2, null);

                $results[$id] ??= new BatchResult($id, [], $response->model);
                $results[$id]->put($name, $answer);
            }
        }

        return collect($results)->each(fn (BatchResult $result) => $result->batchInputTokens($inputTokens));
    }
}
