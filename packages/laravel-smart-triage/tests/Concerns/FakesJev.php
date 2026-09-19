<?php

namespace Solarise\SmartTriage\Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesJev
{
    protected function fakeJev(array $answers, int $inputTokens = 450): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => $answers,
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => 60],
        ])]);
    }

    protected function choice(string $winner, float $confidence, array $probabilities): array
    {
        return ['type' => 'choice', 'choice' => $winner, 'confidence' => $confidence, 'probabilities' => $probabilities];
    }

    protected function score(float $value, array $legend = []): array
    {
        return ['type' => 'score', 'score' => $value, 'confidence' => 0.9, 'probabilities' => [], 'legend' => $legend];
    }

    protected function noul(float $probability): array
    {
        return ['type' => 'noul', 'noul' => $probability];
    }

    /** A complete, confident answer set for the Ticket fixture. */
    protected function ticketAnswers(float $urgency = 2.0, string $team = 'Billing', float $confidence = 0.96, array $flags = []): array
    {
        $runnerUp = round(1 - $confidence - 0.01, 4);

        return [
            'urgency' => $this->score($urgency),
            'team' => $this->choice($team, $confidence, [
                $team => $confidence,
                $team === 'Billing' ? 'Technical' : 'Billing' => $runnerUp,
                'other' => 0.01,
            ]),
            'refund_requested' => $this->noul($flags['refund_requested'] ?? 0.02),
            'threatens_legal' => $this->noul($flags['threatens_legal'] ?? 0.02),
        ];
    }
}
