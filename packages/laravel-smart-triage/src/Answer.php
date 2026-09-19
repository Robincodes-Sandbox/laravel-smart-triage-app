<?php

namespace Solarise\SmartTriage;

/**
 * One typed answer from a judge, normalised across the three primitives.
 *
 * The probability distribution is the useful part and is always kept: a choice
 * that came back "billing" at 0.34 against "shipping" at 0.31 is a different
 * fact from one at 0.98, and only the distribution tells you which you have.
 */
class Answer
{
    public function __construct(
        public readonly string $type,
        public readonly string|float|null $value,
        public readonly array $probabilities = [],
        public readonly ?float $confidence = null,
        public readonly array $legend = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return match ($payload['type'] ?? null) {
            'choice' => new self(
                type: 'choice',
                value: $payload['choice'] ?? null,
                probabilities: $payload['probabilities'] ?? [],
                confidence: isset($payload['confidence']) ? (float) $payload['confidence'] : null,
            ),
            // A noul carries no separate confidence: the probability is the answer.
            'noul' => new self(
                type: 'noul',
                value: isset($payload['noul']) ? (float) $payload['noul'] : null,
            ),
            'score' => new self(
                type: 'score',
                value: isset($payload['score']) ? (float) $payload['score'] : null,
                probabilities: $payload['probabilities'] ?? [],
                confidence: isset($payload['confidence']) ? (float) $payload['confidence'] : null,
                legend: $payload['legend'] ?? [],
            ),
            default => throw new \InvalidArgumentException(
                'Unknown answer type: '.json_encode($payload['type'] ?? null)
            ),
        };
    }

    /**
     * The runner-up and its probability, which is what you actually want when
     * deciding whether a low-confidence choice is worth a human's attention.
     */
    public function runnerUp(): ?array
    {
        if (count($this->probabilities) < 2) {
            return null;
        }

        $sorted = $this->probabilities;
        arsort($sorted);
        $sorted = array_slice($sorted, 1, 1, true);

        return [array_key_first($sorted) => reset($sorted)];
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
            'probabilities' => $this->probabilities,
            'confidence' => $this->confidence,
            'legend' => $this->legend,
        ];
    }
}
