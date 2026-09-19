<?php

namespace Solarise\SmartTriage\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use LogicException;
use Solarise\SmartTriage\Exceptions\TriageException;
use Solarise\SmartTriage\Contracts\Judge;
use Solarise\SmartTriage\Response;
use Solarise\SmartTriage\Models\Judgement;
use Solarise\SmartTriage\Outcome;
use Solarise\SmartTriage\Triage;
use Solarise\SmartTriage\Questions\Question;
use Solarise\SmartTriage\Support\JudgementWriter;

/**
 * Attach judgement to any model.
 *
 *   class Article extends Model
 *   {
 *       use Triageable;
 *
 *       protected array $triageState = ['title', 'description'];
 *
 *       protected function triageRules(): array
 *       {
 *           return [
 *               'section' => Choice::make('Which section does this belong in?')->among(Section::class),
 *               'paywall' => Noul::make('Does this rely on reporting a subscriber would pay for?'),
 *           ];
 *       }
 *   }
 *
 * One triage() is one request answering every question in that set. Adding a
 * third judgement costs its own question tokens and no extra round trip.
 */
trait Triageable
{
    /** Resolved question sets, memoised per class — see questionSet(). */
    protected static array $triageQuestionCache = [];

    public static function bootTriageable(): void
    {
        static::saved(function (Model $model) {
            // Only the whitelisted attributes can invalidate an answer, and
            // wasChanged() tells us for free — no hashing, no extra query
            // unless something that matters actually moved.
            if ($model->exists && $model->wasChanged($model->triageStateKeys())) {
                $model->markClassificationsStale();
            }
        });
    }

    public function judgements(): MorphMany
    {
        return $this->morphMany(Judgement::class, 'triageable');
    }

    /**
     * @return array<string, Question>
     */
    abstract protected function triageRules(): Triage|array;

    /**
     * The attributes the model is allowed to read.
     *
     * Explicit by design. Handing over the whole row is the tempting move and
     * the wrong one: large irrelevant state costs accuracy, not just money, and
     * the failure is silent — you get confident answers drawn from a created_at
     * column nobody meant to send.
     */
    public function triageStateKeys(): array
    {
        if (! property_exists($this, 'triageState')) {
            throw new LogicException(
                static::class.' uses Triageable but does not define $triageState. '
                .'Name the attributes the model may read; there is deliberately no default.'
            );
        }

        return $this->triageState;
    }

    /**
     * Facts about the situation this record sits in, which are not columns on it.
     *
     * This is where triage stops being a lookup table. The same log line
     * is routine on a quiet afternoon and urgent four minutes after a deploy;
     * the line does not change, the context does. Compute anything numeric here
     * — The model cannot count, and cannot order dates.
     */
    protected function triageContext(): array
    {
        return [];
    }

    public function toTriageState(): array
    {
        $state = [];

        foreach ($this->triageStateKeys() as $key) {
            $state[$key] = $this->getAttribute($key);
        }

        $context = $this->triageContext();

        return $context === [] ? $state : ['record' => $state, 'context' => $context];
    }

    /**
     * Ask every question about this record in one request.
     *
     * @return Collection<string, Judgement>
     */
    public function triage(bool $force = false): Collection
    {
        if (! $force && ! $this->needsTriage()) {
            return $this->judgements()->get()->keyBy('key');
        }

        $questions = static::questionSet();

        $response = app(Judge::class)->ask(
            $this->toTriageState(),
            array_map(fn (Question $question) => $question->toPayload(), $questions),
        );

        return $this->recordClassification($response);
    }

    /**
     * Classify many records of this type concurrently.
     *
     * Each record is its own state, so this is a pool of requests rather than
     * one big one — held under the account's requests-per-minute ceiling. For
     * records small enough to share a payload, reach for Batch instead: it puts
     * the shared context in once and asks one question per record.
     *
     * @param  iterable<Model>  $models
     * @return Collection<int|string, Collection<string, Judgement>|TriageException>
     */
    public static function triageMany(iterable $models): Collection
    {
        $models = collect($models)->keyBy(fn (Model $model) => $model->getKey());

        if ($models->isEmpty()) {
            return collect();
        }

        $questions = array_map(
            fn (Question $question) => $question->toPayload(),
            static::questionSet(),
        );

        $responses = app(Judge::class)->askMany(
            $models->map(fn (Model $model) => [
                'state' => $model->toTriageState(),
                'questions' => $questions,
            ])->all(),
        );

        return collect($responses)->map(function ($response, $key) use ($models) {
            if ($response instanceof TriageException) {
                return $response;
            }

            return $models[$key]->recordClassification($response);
        });
    }

    /**
     * @return Collection<string, Judgement>
     */
    public function recordClassification(Response $response): Collection
    {
        return app(JudgementWriter::class)->write(
            model: $this,
            answers: $response->answers,
            jevModel: $response->model,
            inputTokens: $response->inputTokens,
            stateFingerprint: $this->triageStateFingerprint(),
            questionsFingerprint: static::triageQuestionsFingerprint(),
            questions: static::questionSet(),
        );
    }

    public function judgement(string $key): ?Judgement
    {
        return $this->judgements->firstWhere('key', $key);
    }

    /** The plain answer, for when you do not need the distribution. */
    public function judgedAs(string $key): string|float|null
    {
        $judgement = $this->judgement($key);

        return $judgement?->value ?? $judgement?->number;
    }

    public function needsTriage(): bool
    {
        $existing = $this->judgements()->get();

        if ($existing->isEmpty()) {
            return true;
        }

        return $existing->contains(
            fn (Judgement $row) => $row->stale
                || $row->state_fingerprint !== $this->triageStateFingerprint()
                || $row->questions_fingerprint !== static::triageQuestionsFingerprint()
        );
    }

    public function markClassificationsStale(): int
    {
        return $this->judgements()->update(['stale' => true]);
    }

    public function triageStateFingerprint(): string
    {
        return hash('sha256', (string) json_encode($this->toTriageState()));
    }

    /**
     * Changing a taxonomy or rewording a level invalidates every answer that
     * question ever produced — no record changed, but the question did, and an
     * answer to a different question is not an answer.
     */
    public static function triageQuestionsFingerprint(): string
    {
        $fingerprints = array_map(
            fn (Question $question) => $question->fingerprint(),
            static::questionSet(),
        );

        ksort($fingerprints);

        return hash('sha256', (string) json_encode($fingerprints));
    }

    /**
     * Resolve triageRules() once per class.
     *
     * An Eloquent-backed taxonomy hits the database to list its options, and
     * doing that per record would turn a bulk run into N queries. The tradeoff:
     * triageRules() must not depend on the individual record — put anything
     * record-specific in triageContext(), which is evaluated every time.
     *
     * @return array<string, Question>
     */
    protected static function questionSet(): array
    {
        $rules = static::triageDefinition();

        return $rules instanceof Triage ? $rules->questions() : $rules;
    }

    /**
     * The declared triage, resolved once per class.
     *
     * An Eloquent-backed taxonomy hits the database to list its options, and
     * doing that per record would turn a bulk run into N queries. The tradeoff:
     * triageRules() must not depend on the individual record — anything
     * record-specific belongs in triageContext(), which is evaluated every time.
     */
    public static function triageDefinition(): Triage|array
    {
        return static::$triageQuestionCache[static::class] ??= (new static)->triageRules();
    }

    /**
     * The resolved question set, for inspection — what a dry run prints and
     * what a UI shows when explaining why a record was labelled as it was.
     *
     * @return array<string, Question>
     */
    public static function triageQuestions(): array
    {
        return static::questionSet();
    }

    public static function forgetTriageQuestions(): void
    {
        unset(static::$triageQuestionCache[static::class]);
    }

    /** Records with no judgement at all, or a judgement marked stale. */
    public function scopeNeedingTriage(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('judgements')
            ->orWhereHas('judgements', fn (Builder $inner) => $inner->where('stale', true));
    }

    public function scopeClassifiedAs(Builder $query, string $key, string $value): Builder
    {
        return $query->whereHas(
            'judgements',
            fn (Builder $inner) => $inner->where('key', $key)->where('value', $value)
        );
    }

    /**
     * The decision this record's judgements add up to.
     *
     * Composition over answers already held — the band, the deadline, the
     * flags that fired and whether a person should look. No further requests,
     * so moving a threshold is free.
     */
    public function outcome(): Outcome
    {
        $rules = static::triageDefinition();

        if (! $rules instanceof Triage) {
            throw new LogicException(
                static::class.'::triageRules() returns a plain question set, which has no bands '
                .'or thresholds to resolve. Return a Triage to use outcome().'
            );
        }

        return new Outcome(
            triage: $rules,
            judgements: $this->judgements->keyBy('key'),
            from: $this->triagedFrom(),
        );
    }

    /**
     * When the clock started for SLA purposes. Override where the record has a
     * reported_at, received_at or similar; created_at is only a stand-in.
     */
    protected function triagedFrom(): ?\Carbon\CarbonInterface
    {
        return $this->reported_at ?? $this->created_at;
    }

    /** Most urgent first — the queue as a person would want to work it. */
    public function scopeByUrgency(Builder $query, string $direction = 'desc'): Builder
    {
        $table = (new Judgement)->getTable();

        return $query
            ->leftJoin($table, function ($join) use ($table, $query) {
                $join->on($table.'.triageable_id', '=', $query->getModel()->getTable().'.'.$query->getModel()->getKeyName())
                    ->where($table.'.triageable_type', '=', $query->getModel()->getMorphClass())
                    ->where($table.'.key', '=', 'urgency');
            })
            ->orderBy($table.'.number', $direction)
            ->select($query->getModel()->getTable().'.*');
    }
}
