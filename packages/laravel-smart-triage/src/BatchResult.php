<?php

namespace Solarise\SmartTriage;

use Solarise\SmartTriage\Answer;

class BatchResult
{
    protected int $batchInputTokens = 0;

    /**
     * @param  array<string, Answer>  $answers
     */
    public function __construct(
        public readonly string $id,
        protected array $answers = [],
        public readonly string $model = 'unknown',
    ) {}

    public function put(string $name, Answer $answer): void
    {
        $this->answers[$name] = $answer;
    }

    public function get(string $name): ?Answer
    {
        return $this->answers[$name] ?? null;
    }

    /** @return array<string, Answer> */
    public function all(): array
    {
        return $this->answers;
    }

    public function batchInputTokens(?int $tokens = null): int
    {
        if ($tokens !== null) {
            $this->batchInputTokens = $tokens;
        }

        return $this->batchInputTokens;
    }
}
