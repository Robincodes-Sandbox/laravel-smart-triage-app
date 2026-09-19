<?php

namespace Solarise\SmartTriage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $key
 * @property string $type
 * @property string|null $value
 * @property float|null $number
 * @property array|null $probabilities
 * @property float|null $confidence
 */
class Judgement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'probabilities' => 'array',
        'legend' => 'array',
        'number' => 'float',
        'confidence' => 'float',
        'stale' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('smart-triage.table', 'judgements');
    }

    public function triageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeStale(Builder $query): Builder
    {
        return $query->where('stale', true);
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    /**
     * Confidence measures how concentrated the distribution is — nothing more.
     * It is not the probability that the answer is right, so the threshold that
     * matters is yours, set against your data and the cost of being wrong.
     */
    public function isConfident(?float $threshold = null): bool
    {
        $threshold ??= (float) config('smart-triage.confidence_threshold', 0.9);

        // A noul has no separate confidence: certainty lives in how far the
        // probability sits from 0.5.
        if ($this->type === 'noul') {
            return abs(($this->number ?? 0.5) - 0.5) * 2 >= $threshold;
        }

        return ($this->confidence ?? 0.0) >= $threshold;
    }

    /** The option that came second, and its probability. */
    public function runnerUp(): ?array
    {
        $probabilities = $this->probabilities ?? [];

        if (count($probabilities) < 2) {
            return null;
        }

        arsort($probabilities);
        $second = array_slice($probabilities, 1, 1, true);

        return ['option' => array_key_first($second), 'probability' => reset($second)];
    }

    /**
     * How far clear the winner is. Near zero means two options are effectively
     * tied, which is the case worth showing a person.
     */
    public function margin(): ?float
    {
        $runnerUp = $this->runnerUp();

        if ($runnerUp === null) {
            return null;
        }

        $probabilities = $this->probabilities ?? [];

        return max($probabilities) - $runnerUp['probability'];
    }
}
