<?php

namespace Solarise\SmartTriage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Solarise\SmartTriage\Exceptions\TriageException;

/**
 * Classify one record off the request cycle.
 *
 * A save hook marks a record stale; it deliberately does not call the API.
 * Re-triaging inline on every save is how a bulk import quietly turns into
 * fifty thousand requests. Dispatch this when you want it done now, or let
 * triage:run sweep the stale ones on a schedule.
 */
class TriageRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Model $record, public bool $force = false)
    {
        $this->onConnection(config('smart-triage.queue.connection'));
        $this->onQueue(config('smart-triage.queue.queue', 'default'));
    }

    public function handle(): void
    {
        $this->record->triage($this->force);
    }

    /** Back off on the two statuses the API asks you to back off on. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function failed(TriageException $exception): void
    {
        report($exception);
    }
}
