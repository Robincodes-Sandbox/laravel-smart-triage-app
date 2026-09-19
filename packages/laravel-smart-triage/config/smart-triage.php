<?php

use Solarise\SmartTriage\Drivers\JevClient;

return [
    /*
     * Which judge answers the questions.
     *
     * Jev is the one that ships. The package is written against the Judge
     * contract, so another model that returns typed answers with calibrated
     * probabilities can be added here without touching a triage declaration.
     */
    'driver' => env('SMART_TRIAGE_DRIVER', 'jev'),

    'drivers' => [
        'jev' => [
            'client' => JevClient::class,

            /*
             * Server-side only. This is a paid API: never let the key reach a
             * browser bundle, and never commit the value.
             */
            'api_key' => env('JEV_API_KEY'),
            'base_url' => env('JEV_BASE_URL', 'https://api.typesafe.ai/v1'),

            /*
             * "jev-latest" resolves to a concrete version. The resolved value is
             * stored on every row, so answers stay traceable after the alias moves.
             */
            'model' => env('JEV_MODEL', 'jev-latest'),
        ],
    ],

    'timeout' => (int) env('SMART_TRIAGE_TIMEOUT', 15),

    /* Only rate-limit and overload responses are retried; a rejected request is your bug. */
    'retries' => (int) env('SMART_TRIAGE_RETRIES', 3),

    /*
     * Requests in flight at once, and the ceiling they are paced against. Jev
     * documents 1,200 requests a minute; the default leaves room for whatever
     * else is using the key.
     */
    'concurrency' => (int) env('SMART_TRIAGE_CONCURRENCY', 20),
    'requests_per_minute' => (int) env('SMART_TRIAGE_REQUESTS_PER_MINUTE', 1000),

    'table' => 'judgements',

    /*
     * Confidence is how concentrated a distribution is, not the probability that
     * the answer is right. Measure your own threshold against your own data and
     * the cost of being wrong.
     */
    'confidence_threshold' => (float) env('SMART_TRIAGE_CONFIDENCE', 0.9),

    'queue' => [
        'connection' => env('SMART_TRIAGE_QUEUE_CONNECTION'),
        'queue' => env('SMART_TRIAGE_QUEUE', 'default'),
    ],
];
