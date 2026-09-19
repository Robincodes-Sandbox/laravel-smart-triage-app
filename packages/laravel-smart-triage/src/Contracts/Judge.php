<?php

namespace Solarise\SmartTriage\Contracts;

use Solarise\SmartTriage\Response;

/**
 * Something that answers typed questions about state.
 *
 * The package is written against this rather than against one vendor, because
 * the useful shape — send state and a set of typed questions, get back typed
 * answers with probabilities — is not unique to any one model. Jev is the
 * driver that exists today.
 *
 * A driver must not generate text, and must return calibrated probabilities.
 * Anything that only returns prose belongs behind a different abstraction.
 */
interface Judge
{
    /**
     * Ask every question in one request.
     *
     * Implementations are expected to score questions independently, so that
     * asking more costs their tokens and no extra round trip.
     *
     * @param  array<string, array>  $questions  keyed by the caller's own ids
     */
    public function ask(string|array $state, array $questions): Response;

    /**
     * Ask many independent requests, concurrently, within the account's limits.
     *
     * Failures come back in the result set rather than being thrown, so one bad
     * record cannot take down a run of thousands.
     *
     * @param  array<string|int, array{state: string|array, questions: array}>  $batches
     * @return array<string|int, Response|\Solarise\SmartTriage\Exceptions\TriageException>
     */
    public function askMany(array $batches): array;

    /** The model identifier stored against each judgement. */
    public function model(): string;

    /** How many requests this driver will keep in flight. */
    public function concurrency(): int;
}
