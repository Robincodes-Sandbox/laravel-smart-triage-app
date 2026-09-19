<?php

namespace App\Models;

use App\Support\OperatingContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Solarise\SmartTriage\Band;
use Solarise\SmartTriage\Concerns\Triageable;
use Solarise\SmartTriage\Triage;

/**
 * A repair reported against one of 50,000 homes.
 *
 * The whole triage is the method below. Nothing else in the application has to
 * know how urgency is decided, what the deadline is, or when a person should
 * look — those all fall out of the declaration.
 */
class RepairReport extends Model
{
    use Triageable;

    protected $guarded = [];

    protected $casts = ['reported_at' => 'datetime'];

    /** Only these reach Jev. The address and UPRN are identifying noise. */
    protected array $triageState = ['summary', 'room', 'channel'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** The SLA clock starts when the tenant reported it, not when we stored it. */
    protected function triagedFrom(): ?\Carbon\CarbonInterface
    {
        return $this->reported_at;
    }

    /**
     * What the report cannot tell you on its own.
     *
     * "Black spots coming back in the bedroom corner" is a routine callout in a
     * dry new-build in June and a statutory clock in a block with damp history
     * in January with a child on the vulnerability register. The words are the
     * same. Everything that changes the answer is here, already computed —
     * counted, dated and measured by ordinary code, because Jev does neither.
     */
    protected function triageContext(): array
    {
        $property = $this->property;

        return array_merge(OperatingContext::current(), [
            'property_archetype' => $property?->archetype,
            'built' => $property?->built,
            'floor' => $property?->floor,
            'block_has_damp_history' => $property?->damp_history ? 'yes' : 'no',
            'household_flagged_vulnerable' => $property?->household_vulnerable ? 'yes' : 'no',
            'open_reports_at_this_property' => $property?->reports()->where('status', 'open')->count() ?? 0,
        ]);
    }

    /**
     * Bands follow published social-housing practice: Awaab's Law sets 24 hours
     * to investigate and make safe an emergency hazard, and Scotland's Right to
     * Repair runs qualifying repairs at 1, 3 or 7 working days. This is a demo
     * composite of the two, not legal advice — a real landlord's own policy
     * goes here, and the wording is what the model reads.
     */
    protected function triageRules(): Triage
    {
        return Triage::make()
            ->bands([
                Band::make('Routine')
                    ->meaning('Cosmetic or minor. Nothing is unsafe, nothing essential has stopped working, and leaving it a few weeks changes nothing.')
                    ->within('28 days'),

                Band::make('Qualifying')
                    ->meaning('One essential fixture has stopped working — a toilet, a light circuit, an extractor fan — but the home is safe and usable meanwhile.')
                    ->within('7 working days'),

                Band::make('Urgent')
                    ->meaning('Loss of an essential service such as heat, hot water or power, or damage that will get materially worse if it waits.')
                    ->within('3 working days'),

                Band::make('Emergency')
                    ->meaning('Immediate risk to health, safety or the structure, tonight. Uncontrolled water entering the building or flooding a room, exposed live electrics, a gas smell, no heat in freezing weather, structural movement. A fixture that merely drips or runs is not this, however the report is worded.')
                    ->within('24 hours'),
            ])
            ->urgency('How fast must someone attend this repair, given the property and the conditions described in the context?')
            ->route(
                key: 'trade',
                instruction: 'Which trade should attend first? Choose the one that can make it safe, not the one that finishes the job.',
                among: Trade::class,
                noneOf: 'surveyor',
            )
            // Separate nouls, not one compound question. Several can hold at
            // once, they ride in the same request for nothing, and a threshold
            // can move later without re-asking anything.
            ->flag('damp_mould', 'Does this describe damp, mould, condensation or a persistent water ingress problem?', escalates: true)
            ->flag('vulnerability_risk', 'Does the report suggest this poses particular risk to a child, an older person or someone with a health condition?', escalates: true)
            ->flag('repeat_visit', 'Does this read as the same problem coming back rather than something new?')
            ->flag('access_difficulty', 'Does the report mention any difficulty getting access to the property?')
            // Below these, a person looks first. The band-edge rule catches the
            // 1.48-versus-1.52 case, where the same judgement lands either side
            // of a deadline that differs by three weeks.
            ->reviewWhen(confidence: 0.80, margin: 0.20, bandEdge: 0.10);
    }
}
