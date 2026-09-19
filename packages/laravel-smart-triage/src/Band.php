<?php

namespace Solarise\SmartTriage;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One rung of an urgency scale, and the deadline that comes with it.
 *
 * The description does double duty: it is the level the model reads when scoring, and
 * it is the row of the SLA table a human reads afterwards. Keeping them as one
 * object is the point — a band whose wording drifts from its deadline is how
 * triage quietly stops matching policy.
 */
class Band
{
    protected ?string $meaning = null;

    protected ?string $within = null;

    public function __construct(public readonly string $label) {}

    public static function make(string $label): self
    {
        return new self($label);
    }

    /**
     * What this band actually covers, as a concrete situation.
     *
     * Write a situation, not an adverb. "Water is entering the property and
     * cannot be stopped" gives the model something to match; "very urgent"
     * gives it nothing, and the distribution goes flat.
     */
    public function meaning(string $meaning): self
    {
        $this->meaning = $meaning;

        return $this;
    }

    /** "24 hours", "3 working days", "28 days". */
    public function within(string $spec): self
    {
        $this->within = $spec;

        return $this;
    }

    public function describe(): string
    {
        $meaning = $this->meaning ?? $this->label;

        // The deadline is deliberately not shown to the model. It is a
        // consequence of the band, not evidence for it, and putting a number in
        // front of something that cannot order quantities invites trouble.
        return $meaning;
    }

    public function deadline(): ?string
    {
        return $this->within;
    }

    /**
     * Resolve the deadline from a starting point.
     *
     * Working days are counted as weekdays. Real housing policy also excludes
     * public holidays; wire that in at the application level, where the
     * relevant calendar actually lives.
     */
    public function respondBy(CarbonInterface $from): ?CarbonInterface
    {
        if ($this->within === null) {
            return null;
        }

        $from = Carbon::instance($from)->copy();

        if (preg_match('/^(\d+)\s*hours?$/i', trim($this->within), $m)) {
            return $from->addHours((int) $m[1]);
        }

        if (preg_match('/^(\d+)\s*working\s*days?$/i', trim($this->within), $m)) {
            return $from->addWeekdays((int) $m[1])->endOfDay();
        }

        if (preg_match('/^(\d+)\s*days?$/i', trim($this->within), $m)) {
            return $from->addDays((int) $m[1])->endOfDay();
        }

        throw new InvalidArgumentException(
            "Cannot read the deadline [{$this->within}]. Use \"24 hours\", \"3 working days\" or \"28 days\"."
        );
    }

    public function toArray(): array
    {
        return ['label' => $this->label, 'meaning' => $this->describe(), 'within' => $this->within];
    }
}
