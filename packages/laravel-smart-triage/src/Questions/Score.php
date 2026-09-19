<?php

namespace Solarise\SmartTriage\Questions;

use InvalidArgumentException;

/**
 * Where something sits on a described scale.
 *
 * Levels must read as concrete situations, low to high. "Broken but there is a
 * workaround" is a level; "moderately severe" is not — an adverb gives the model
 * nothing to match against and the distribution goes flat.
 */
class Score implements Question
{
    protected array $levels = [];

    public function __construct(protected string $instructions) {}

    public static function make(string $instructions): self
    {
        return new self($instructions);
    }

    public function levels(array $levels): self
    {
        $this->levels = array_values($levels);

        return $this;
    }

    public function toPayload(): array
    {
        $count = count($this->levels);

        if ($count < 2 || $count > 10) {
            throw new InvalidArgumentException(
                "Score [{$this->instructions}] needs between 2 and 10 levels; got {$count}."
            );
        }

        return [
            'type' => 'score',
            'instructions' => $this->instructions,
            'criteria' => $this->levels,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toPayload()));
    }
}
