<?php

namespace Solarise\SmartTriage\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Solarise\SmartTriage\Band;
use Solarise\SmartTriage\Concerns\Triageable;
use Solarise\SmartTriage\Triage;

class Ticket extends Model
{
    use Triageable;

    protected $guarded = [];

    protected $casts = ['received_at' => 'datetime'];

    protected array $triageState = ['subject', 'body'];

    public static array $context = ['office_hours' => 'yes'];

    protected function triagedFrom(): ?\Carbon\CarbonInterface
    {
        return $this->received_at;
    }

    protected function triageContext(): array
    {
        return static::$context;
    }

    protected function triageRules(): Triage
    {
        return Triage::make()
            ->bands([
                Band::make('Low')->meaning('A question. Nothing is broken.')->within('5 days'),
                Band::make('Normal')->meaning('Works badly but there is a way round it.')->within('2 working days'),
                Band::make('High')->meaning('The customer cannot do what they came to do.')->within('4 hours'),
            ])
            ->urgency('How quickly does this need an answer?')
            ->route('team', 'Which team should answer it?', Team::class, noneOf: 'other')
            ->flag('refund_requested', 'Is the customer asking for money back?')
            ->flag('threatens_legal', 'Does the message threaten legal action?', escalates: true)
            ->reviewWhen(confidence: 0.80, margin: 0.20, bandEdge: 0.10);
    }
}
