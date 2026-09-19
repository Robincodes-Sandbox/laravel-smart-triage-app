<?php

namespace App\Support;

/**
 * Conditions the whole service is operating under right now.
 *
 * Shared by every report in a batch, because every report in a batch genuinely
 * does share them. Sending this once per chunk rather than once per report is
 * both the cheap shape and the honest one.
 */
class OperatingContext
{
    public const CONDITIONS = [
        'mild' => [
            'label' => 'Mild September week',
            'season' => 'Early autumn, overnight lows around 11C',
            'weather' => 'Settled and dry for the past fortnight',
            'heating_season' => 'no',
            'contractor_backlog' => 'Normal. Emergency slots available same day.',
            'notes' => 'No weather event. Full contractor availability.',
        ],
        'cold_snap' => [
            'label' => 'February cold snap, storm passing',
            'season' => 'Mid winter, overnight lows around -4C',
            'weather' => 'Named storm overnight, heavy rain and 60mph gusts',
            'heating_season' => 'yes',
            'contractor_backlog' => 'Stretched. 180 emergency jobs already open, heating engineers fully committed.',
            'notes' => 'Storm has generated a surge of roof and water ingress reports across the stock.',
        ],
    ];

    public static function current(): array
    {
        return static::for(session('conditions', 'mild'));
    }

    public static function for(string $key): array
    {
        $conditions = static::CONDITIONS[$key] ?? static::CONDITIONS['mild'];

        return collect($conditions)->except('label')->all();
    }
}
