<?php

namespace App\Support;

/**
 * The ambient facts a log line does not carry.
 *
 * Everything here is computed by ordinary code. Jev cannot count and cannot
 * order dates, so "four minutes ago" and "2,100 orders" arrive already worked
 * out — the judgement left over is what those facts mean together, which is
 * the only part worth paying for.
 */
class SystemContext
{
    public const SITUATIONS = [
        'quiet' => [
            'label' => 'Quiet afternoon',
            'clock' => 'Tuesday 15:04',
            'last_deploy' => 'No deploy in the last 6 hours',
            'open_incidents' => 0,
            'orders_last_hour' => 40,
            'on_call_note' => 'Normal working hours, full team available.',
        ],
        'sale' => [
            'label' => 'Mid-sale, 4 minutes after a deploy',
            'clock' => 'Friday 20:12',
            'last_deploy' => 'Deploy finished 4 minutes ago',
            'open_incidents' => 1,
            'orders_last_hour' => 2100,
            'on_call_note' => 'Black Friday sale is live. One engineer on call.',
        ],
    ];

    public static function current(): array
    {
        return static::for(session('situation', 'quiet'));
    }

    public static function for(string $key): array
    {
        $situation = static::SITUATIONS[$key] ?? static::SITUATIONS['quiet'];

        return collect($situation)->except('label')->all();
    }
}
