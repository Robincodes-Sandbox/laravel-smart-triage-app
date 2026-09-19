<?php

namespace Solarise\SmartTriage;

class Response
{
    /**
     * @param  array<string, Answer>  $answers
     */
    public function __construct(
        public readonly array $answers,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public static function fromArray(array $payload): self
    {
        $answers = [];

        foreach ($payload['answers'] ?? [] as $key => $answer) {
            $answers[$key] = Answer::fromArray($answer);
        }

        return new self(
            answers: $answers,
            // The resolved version, e.g. jev-1.13.0 — not the "jev-latest" we
            // asked for. Worth storing: it is what makes an old row identifiable
            // after the alias moves underneath you.
            model: $payload['model'] ?? 'unknown',
            inputTokens: (int) ($payload['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($payload['usage']['output_tokens'] ?? 0),
        );
    }

    public function get(string $key): ?Answer
    {
        return $this->answers[$key] ?? null;
    }

    /** Output is free; input is $0.042 per million. */
    public function estimatedCost(): float
    {
        return $this->inputTokens / 1_000_000 * 0.042;
    }
}
