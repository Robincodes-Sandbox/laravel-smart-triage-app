<?php

namespace Solarise\SmartTriage\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Solarise\SmartTriage\Exceptions\TriageException;

class TriageCommand extends Command
{
    protected $signature = 'triage:run
        {model : The Eloquent model class to triage}
        {--all : Include records that already have a current judgement}
        {--limit= : Stop after this many records}
        {--chunk=200 : Records loaded and dispatched per pass}
        {--dry : Report what would be sent without calling the API}';

    protected $description = 'Triage a table against its declared judgements';

    public function handle(): int
    {
        $class = $this->argument('model');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $this->error("[{$class}] is not an Eloquent model.");

            return self::FAILURE;
        }

        if (! method_exists($class, 'triageMany')) {
            $this->error("[{$class}] does not use the Triageable trait.");

            return self::FAILURE;
        }

        $query = $this->option('all')
            ? $class::query()
            : $class::query()->needingTriage();

        // chunkById applies its own limit, so a --limit has to be enforced as
        // we go rather than on the builder, where it would be overwritten.
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $total = (clone $query)->count();
        $total = $limit ? min($total, $limit) : $total;

        // A dry run is for reading the payload, so it reports even when there
        // is nothing outstanding — that is usually exactly when you want to
        // check what the questions currently look like.
        if ($this->option('dry')) {
            return $this->dryRun($class, $query, $total);
        }

        if ($total === 0) {
            $this->info('Nothing to triage.');

            return self::SUCCESS;
        }

        $this->info("Triaging {$total} record(s) of {$class}.");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $triaged = 0;
        $failed = 0;
        $tokens = 0;

        $query->chunkById((int) $this->option('chunk'), function ($records) use (&$triaged, &$failed, &$tokens, $class, $bar, $limit, $total) {
            if ($limit !== null) {
                $records = $records->take(max(0, $limit - $triaged - $failed));
            }

            if ($records->isEmpty()) {
                return false;
            }

            foreach ($class::triageMany($records) as $result) {
                if ($result instanceof TriageException) {
                    $failed++;
                } else {
                    $triaged++;
                    $tokens += (int) $result->first()?->input_tokens;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Output tokens are free; input is $0.042 per million.
        $cost = $tokens / 1_000_000 * 0.042;

        $this->table(['Triaged', 'Failed', 'Input tokens', 'Cost'], [[
            $triaged,
            $failed,
            number_format($tokens),
            '$'.number_format($cost, 4),
        ]]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function dryRun(string $class, $query, int $total): int
    {
        $sample = (clone $query)->first() ?? $class::query()->first();

        $this->info("{$total} record(s) of {$class} would be triaged.");
        $this->newLine();
        $this->line('<comment>Questions</comment> (one request answers all of them):');
        $this->line(json_encode(
            array_map(fn ($question) => $question->toPayload(), $class::triageQuestions()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        if ($sample) {
            $this->newLine();
            $this->line('<comment>State</comment> for the first record:');
            $this->line(json_encode($sample->toTriageState(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
