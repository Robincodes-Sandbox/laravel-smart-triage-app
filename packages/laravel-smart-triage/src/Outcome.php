<?php

namespace Solarise\SmartTriage;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Solarise\SmartTriage\Models\Judgement;

/**
 * What a triage decided about one record, and how much to trust it.
 *
 * Every number here came out of the same single request. Nothing below costs
 * another call — it is all composition over answers already held, which is why
 * changing a threshold is free and does not invalidate anything.
 */
class Outcome
{
    /**
     * @param  Collection<string, Judgement>  $judgements
     */
    public function __construct(
        protected Triage $triage,
        protected Collection $judgements,
        protected ?CarbonInterface $from = null,
    ) {}

    public function urgency(): ?float
    {
        return $this->judgements->get('urgency')?->number;
    }

    public function band(): ?Band
    {
        return $this->triage->bandFor($this->urgency());
    }

    public function bandLabel(): ?string
    {
        return $this->band()?->label;
    }

    public function owner(): ?string
    {
        return $this->judgements->get($this->triage->routeKey())?->value;
    }

    public function respondBy(): ?CarbonInterface
    {
        if ($this->from === null) {
            return null;
        }

        return $this->band()?->respondBy($this->from);
    }

    public function overdue(): bool
    {
        $deadline = $this->respondBy();

        return $deadline !== null && $deadline->isPast();
    }

    /** Hours left against the band's deadline; negative once breached. */
    public function hoursRemaining(): ?float
    {
        $deadline = $this->respondBy();

        return $deadline === null ? null : round(now()->diffInMinutes($deadline, false) / 60, 1);
    }

    /**
     * The flags that actually fired, at their own thresholds.
     *
     * @return array<string, float>
     */
    public function flags(): array
    {
        $fired = [];

        foreach ($this->triage->flagRules() as $key => $rule) {
            $probability = $this->judgements->get($key)?->number;

            if ($probability !== null && $probability >= $rule['at']) {
                $fired[$key] = $probability;
            }
        }

        return $fired;
    }

    public function flagged(string $key): bool
    {
        return array_key_exists($key, $this->flags());
    }

    /**
     * Reasons a person should look because the model was not sure enough.
     *
     * Three independent signals, all read from answers already held. The
     * band-edge one earns its keep most often: a 1.48 and a 1.52 are the same
     * judgement but land either side of a deadline weeks apart, and that is
     * exactly the case a queue should not resolve on a rounding rule.
     *
     * @return array<int, string>
     */
    public function uncertainties(): array
    {
        $reasons = [];
        $thresholds = $this->triage->thresholds();
        $route = $this->judgements->get($this->triage->routeKey());

        if ($route && ($route->confidence ?? 1.0) < $thresholds['confidence']) {
            $reasons[] = sprintf('routing confidence %.2f', $route->confidence ?? 0);
        }

        if ($route && ($margin = $route->margin()) !== null && $margin < $thresholds['margin']) {
            $runnerUp = $route->runnerUp();
            $reasons[] = sprintf('%s and %s nearly tied', $route->value, $runnerUp['option'] ?? '?');
        }

        $edge = $this->triage->distanceFromEdge($this->urgency());

        if ($edge !== null && (0.5 - $edge) < $thresholds['bandEdge']) {
            $reasons[] = sprintf('urgency %.2f sits between bands', $this->urgency());
        }

        return $reasons;
    }

    /**
     * Reasons a person should look because policy says so, however confident
     * the model was.
     *
     * A damp and mould report under Awaab's Law is the clear case: it carries a
     * statutory clock whether or not the routing was obvious. Keeping these
     * apart from uncertainty matters — they are a different queue, worked by
     * different people, and counting them together makes a confident system
     * look like an unreliable one.
     *
     * @return array<int, string>
     */
    public function escalations(): array
    {
        $escalations = [];

        foreach ($this->triage->flagRules() as $key => $rule) {
            if ($rule['escalates'] && $this->flagged($key)) {
                $escalations[] = str_replace('_', ' ', $key);
            }
        }

        return $escalations;
    }

    /** @return array<int, string> */
    public function reasons(): array
    {
        return array_merge($this->escalations(), $this->uncertainties());
    }

    public function uncertain(): bool
    {
        return $this->uncertainties() !== [];
    }

    public function escalated(): bool
    {
        return $this->escalations() !== [];
    }

    public function needsHuman(): bool
    {
        return $this->reasons() !== [];
    }

    public function judgement(string $key): ?Judgement
    {
        return $this->judgements->get($key);
    }

    public function toArray(): array
    {
        return [
            'urgency' => $this->urgency(),
            'band' => $this->bandLabel(),
            'owner' => $this->owner(),
            'respond_by' => $this->respondBy()?->toIso8601String(),
            'overdue' => $this->overdue(),
            'flags' => array_keys($this->flags()),
            'needs_human' => $this->needsHuman(),
            'escalations' => $this->escalations(),
            'uncertainties' => $this->uncertainties(),
        ];
    }
}
