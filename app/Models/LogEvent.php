<?php

namespace App\Models;

use App\Support\SystemContext;
use Illuminate\Database\Eloquent\Model;
use Solarise\SmartTriage\Concerns\Triageable;
use Solarise\SmartTriage\Questions\Choice;
use Solarise\SmartTriage\Questions\Noul;
use Solarise\SmartTriage\Questions\Score;

class LogEvent extends Model
{
    use Triageable;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime'];

    /**
     * Three attributes, named. The level and service are what an ops person
     * reads first, so Jev gets them too.
     */
    protected array $triageState = ['level', 'service', 'message', 'occurrences'];

    /**
     * The situation the line landed in.
     *
     * This is the whole argument for classifying a log at all. `level` is a
     * column and sorting by it is free; what no column holds is that a payment
     * warning four minutes after a deploy, mid-sale, is a different event from
     * the same warning on a quiet Tuesday.
     */
    protected function triageContext(): array
    {
        return SystemContext::current();
    }

    protected function triageRules(): array
    {
        return [
            'urgency' => Score::make('How urgently does this event need a human, given the context?')
                ->levels([
                    'Routine. Belongs in a dashboard nobody reads.',
                    'Worth a look tomorrow morning during normal hours.',
                    'Someone should look today, before it compounds.',
                    'Wake a human now — money or data is actively being lost.',
                ]),

            'owner' => Choice::make('Which team should pick this up?')
                ->among([
                    'payments' => 'Charges, gateways, webhooks, refunds, anything touching money',
                    'platform' => 'Infrastructure, caches, queues, deploys, capacity',
                    'product' => 'Application logic, user-facing features, content handling',
                    'security' => 'Authentication, authorisation, abuse, suspicious access',
                    'nobody' => 'Expected noise that needs no owner',
                ])
                ->noneOf('nobody'),

            // Separate nouls rather than one compound question: they can all be
            // true at once, they cost nothing extra in the same request, and a
            // threshold can move without re-asking anything.
            'customer_facing' => Noul::make('Would a customer notice the effect of this event?'),

            'recurring' => Noul::make('Does this read as a known, repeating condition rather than something new?')
                ->criteria(
                    true: 'A familiar operational event — retries, scaling, routine failures with a count above one.',
                    false: 'Something that reads as new or one-off in this system.',
                ),
        ];
    }
}
