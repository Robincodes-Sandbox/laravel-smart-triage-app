<?php

namespace Solarise\SmartTriage\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Solarise\SmartTriage\Concerns\Triageable;
use Solarise\SmartTriage\Questions\Noul;
use Solarise\SmartTriage\Questions\Score;

/**
 * The lower-level door: a plain question set rather than a declared Triage.
 * Still supported, and worth a test so it stays that way.
 */
class PlainTicket extends Model
{
    use Triageable;

    protected $table = 'tickets';

    protected $guarded = [];

    protected array $triageState = ['subject', 'body'];

    protected function triageRules(): array
    {
        return [
            'severity' => Score::make('How severe is this?')->levels(['Minor', 'Serious']),
            'angry' => Noul::make('Is the sender angry?'),
        ];
    }
}
