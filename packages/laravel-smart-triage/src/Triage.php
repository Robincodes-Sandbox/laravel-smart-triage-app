<?php

namespace Solarise\SmartTriage;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Solarise\SmartTriage\Questions\Choice;
use Solarise\SmartTriage\Questions\Noul;
use Solarise\SmartTriage\Questions\Question;
use Solarise\SmartTriage\Questions\Score;
use Solarise\SmartTriage\Taxonomies\Taxonomy;

/**
 * A triage, declared once.
 *
 * Nearly every triage is the same four moves: how urgent is it, who handles it,
 * which conditions apply, and when should a person look instead. Writing those
 * as loose questions works, but it leaves the interesting part — the deadline,
 * the escalation, the decision to escalate to a human — scattered across the
 * application. This holds them together.
 *
 *   Triage::make()
 *       ->bands([
 *           Band::make('Routine')->meaning('...')->within('28 days'),
 *           Band::make('Emergency')->meaning('...')->within('24 hours'),
 *       ])
 *       ->urgency('How fast must someone attend, given the property and season?')
 *       ->route('trade', 'Which trade attends first?', Trade::class, noneOf: 'survey')
 *       ->flag('statutory', 'Does this describe a hazard with a legal deadline?', escalates: true)
 *       ->reviewWhen(confidence: 0.85, margin: 0.15, bandEdge: 0.25);
 */
class Triage
{
    /** @var array<int, Band> */
    protected array $bands = [];

    protected string $urgencyInstruction = 'How urgently does this need a human?';

    protected ?Choice $route = null;

    protected string $routeKey = 'owner';

    /** @var array<string, array{question: Noul, escalates: bool, at: float}> */
    protected array $flags = [];

    protected float $confidenceFloor = 0.85;

    protected float $marginFloor = 0.15;

    protected float $bandEdge = 0.1;

    public static function make(): self
    {
        return new self;
    }

    /**
     * The urgency scale, low to high, each rung carrying its own deadline.
     *
     * @param  array<int, Band>  $bands
     */
    public function bands(array $bands): self
    {
        $count = count($bands);

        if ($count < 2 || $count > 10) {
            throw new InvalidArgumentException("A triage needs between 2 and 10 bands; got {$count}.");
        }

        $this->bands = array_values($bands);

        return $this;
    }

    public function urgency(string $instruction): self
    {
        $this->urgencyInstruction = $instruction;

        return $this;
    }

    /**
     * Who picks this up. Any taxonomy source the package accepts.
     *
     * @param  array|class-string|Taxonomy  $among
     */
    public function route(string $key, string $instruction, array|string|Taxonomy $among, ?string $noneOf = null): self
    {
        $this->routeKey = $key;
        $this->route = Choice::make($instruction)->among($among);

        if ($noneOf !== null) {
            $this->route->noneOf($noneOf);
        }

        return $this;
    }

    /**
     * A yes/no condition worth recording alongside the decision.
     *
     * Separate nouls rather than one compound question: several can hold at
     * once, they cost nothing extra in the same request, and a threshold can
     * move later without re-asking anything.
     *
     * @param  bool  $escalates  whether firing this alone sends the record to a person
     */
    public function flag(string $key, string $instruction, bool $escalates = false, float $at = 0.7): self
    {
        $this->flags[$key] = [
            'question' => Noul::make($instruction),
            'escalates' => $escalates,
            'at' => $at,
        ];

        return $this;
    }

    /**
     * When a machine answer is not good enough on its own.
     *
     * @param  float  $confidence  route confidence below this goes to a person
     * @param  float  $margin      winner and runner-up closer than this go to a person
     * @param  float  $bandEdge    how close to a band boundary counts as a coin toss. 0.1 means
     *                              a score within 0.1 of the .5 line between two bands — keep it
     *                              small, or most scores qualify and the queue drowns in reviews
     */
    public function reviewWhen(?float $confidence = null, ?float $margin = null, ?float $bandEdge = null): self
    {
        $this->confidenceFloor = $confidence ?? $this->confidenceFloor;
        $this->marginFloor = $margin ?? $this->marginFloor;
        $this->bandEdge = $bandEdge ?? $this->bandEdge;

        return $this;
    }

    /**
     * Every judgement this triage needs, as one question set.
     *
     * They travel in a single request and are scored in parallel, so the cost
     * of a fourth flag is its own question tokens and no extra round trip.
     *
     * @return array<string, Question>
     */
    public function questions(): array
    {
        if ($this->bands === []) {
            throw new InvalidArgumentException('A triage needs bands before it can ask anything.');
        }

        $questions = [
            'urgency' => Score::make($this->urgencyInstruction)
                ->levels(array_map(fn (Band $band) => $band->describe(), $this->bands)),
        ];

        if ($this->route) {
            $questions[$this->routeKey] = $this->route;
        }

        foreach ($this->flags as $key => $flag) {
            $questions[$key] = $flag['question'];
        }

        return $questions;
    }

    /** @return array<int, Band> */
    public function bandList(): array
    {
        return $this->bands;
    }

    /** The band a score falls in, by nearest rung. */
    public function bandFor(?float $score): ?Band
    {
        if ($score === null || $this->bands === []) {
            return null;
        }

        $index = max(0, min(count($this->bands) - 1, (int) round($score)));

        return $this->bands[$index];
    }

    /**
     * How close a score sits to the boundary between two bands.
     *
     * A 1.48 and a 1.52 land in different bands on a rounding rule but are the
     * same judgement. That gap is the most useful "ask a person" signal there
     * is, and it costs nothing to read.
     */
    public function distanceFromEdge(?float $score): ?float
    {
        if ($score === null) {
            return null;
        }

        return abs($score - round($score));
    }

    public function routeKey(): string
    {
        return $this->routeKey;
    }

    /** @return array<string, array{escalates: bool, at: float}> */
    public function flagRules(): array
    {
        return array_map(
            fn (array $flag) => ['escalates' => $flag['escalates'], 'at' => $flag['at']],
            $this->flags,
        );
    }

    public function thresholds(): array
    {
        return [
            'confidence' => $this->confidenceFloor,
            'margin' => $this->marginFloor,
            'bandEdge' => $this->bandEdge,
        ];
    }
}
