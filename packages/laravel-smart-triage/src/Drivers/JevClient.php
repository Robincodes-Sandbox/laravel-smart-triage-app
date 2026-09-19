<?php

namespace Solarise\SmartTriage\Drivers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Solarise\SmartTriage\Contracts\Judge;
use Solarise\SmartTriage\Exceptions\TriageException;
use Solarise\SmartTriage\Response;

/**
 * The whole TypeSafe System One surface: one endpoint, one request shape.
 *
 * There is no official PHP SDK, so this is it. Deliberately thin — it knows
 * about HTTP and nothing about Eloquent.
 */
class JevClient implements Judge
{
    public function __construct(
        protected HttpFactory $http,
        protected string $apiKey,
        protected string $baseUrl = 'https://api.typesafe.ai/v1',
        protected string $model = 'jev-latest',
        protected int $timeout = 15,
        protected int $retries = 3,
        protected int $concurrency = 20,
        protected int $requestsPerMinute = 1_000,
    ) {}

    /**
     * Ask every question in one request.
     *
     * Questions are scored independently and in parallel, so the marginal cost
     * of another question is its own tokens and no extra latency. The docs'
     * own measurement: 13 questions against a 54KB document came out 12.2x
     * cheaper and 10x faster batched than as 13 requests. Batch greedily.
     *
     * @param  array<string, array>  $questions  keyed by your own id; keys are never sent to the model
     */
    public function ask(string|array $state, array $questions): Response
    {
        if ($questions === []) {
            throw new TriageException('The judge was asked nothing: the question set is empty.');
        }

        $response = $this->request()->post($this->endpoint(), $this->payload($state, $questions));

        if ($response->failed()) {
            throw TriageException::fromStatus($response->status(), $response->json() ?? []);
        }

        return Response::fromArray($response->json());
    }

    /**
     * Fire many independent requests concurrently.
     *
     * This is the other half of going fast. Batching questions handles "many
     * judgements about one thing"; this handles "one judgement about many
     * things", where each thing is its own state and cannot share a request.
     * Held under the account's requests-per-minute ceiling, which is the real
     * budget — at $0.042 per million input tokens the money never is.
     *
     * @param  array<string|int, array{state: string|array, questions: array}>  $batches
     * @return array<string|int, Response|TriageException>  keyed as you passed them in
     */
    public function askMany(array $batches): array
    {
        $results = [];
        $minChunkSeconds = $this->concurrency / max($this->requestsPerMinute, 1) * 60;

        foreach (array_chunk($batches, $this->concurrency, true) as $chunk) {
            $startedAt = microtime(true);

            $responses = $this->http->pool(fn (Pool $pool) => array_map(
                fn ($key) => $pool->as((string) $key)
                    ->withToken($this->apiKey)
                    ->acceptJson()
                    ->asJson()
                    ->timeout($this->timeout)
                    ->post($this->endpoint(), $this->payload(
                        $chunk[$key]['state'],
                        $chunk[$key]['questions'],
                    )),
                array_keys($chunk),
            ));

            foreach ($chunk as $key => $batch) {
                $results[$key] = $this->interpret($responses[(string) $key] ?? null);
            }

            // Pace the next chunk rather than collecting 429s. Failing slowly
            // beats failing fast when the ceiling is the only real constraint.
            $elapsed = microtime(true) - $startedAt;

            if ($elapsed < $minChunkSeconds && count($batches) > count($chunk)) {
                usleep((int) (($minChunkSeconds - $elapsed) * 1_000_000));
            }
        }

        return $results;
    }

    /**
     * A pooled response is a Response, or the exception that stopped it. Neither
     * should take down the other 199 records in the run, so failures come back
     * in the result set rather than being thrown.
     */
    protected function interpret(mixed $response): Response|TriageException
    {
        if ($response instanceof HttpResponse) {
            return $response->failed()
                ? TriageException::fromStatus($response->status(), $response->json() ?? [])
                : Response::fromArray($response->json());
        }

        if ($response instanceof \Throwable) {
            return new TriageException('Triage request failed: '.$response->getMessage());
        }

        return new TriageException('The triage driver returned no response.');
    }

    protected function payload(string|array $state, array $questions): array
    {
        return [
            'model' => $this->model,
            'state' => $state,
            'questions' => $questions,
        ];
    }

    protected function endpoint(): string
    {
        return $this->baseUrl.'/systemone';
    }

    protected function request(): PendingRequest
    {
        return $this->http
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            // 429 and 529 are the documented back-off cases. 401 and 422 are
            // our fault and retrying them just burns the rate limit.
            ->retry($this->retries, 500, function ($exception) {
                $status = $exception->response?->status();

                return in_array($status, [429, 529], true);
            }, throw: false);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }
}
