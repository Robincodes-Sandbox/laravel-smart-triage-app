<?php

return [
    /*
     * The public demo is gated.
     *
     * It is a live, paid API behind a button. Left open on a public subdomain
     * it is somebody else's free credit, so this fails closed: with the lock
     * on and no password set, nothing gets through at all.
     */
    'lock' => [
        'enabled' => filter_var(env('DEMO_LOCK', true), FILTER_VALIDATE_BOOL),
        'password' => env('DEMO_LOCK_PASSWORD'),
    ],

    /*
     * Re-running the triage spends real tokens. Cheap ones, but a held-down
     * button is still a bill.
     */
    'triage_per_hour' => (int) env('DEMO_TRIAGE_PER_HOUR', 10),
];
