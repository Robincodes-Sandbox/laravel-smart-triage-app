<?php

namespace Solarise\SmartTriage\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Solarise\SmartTriage\Answer;
use Solarise\SmartTriage\Models\Judgement;
use Solarise\SmartTriage\Questions\Question;
use Solarise\SmartTriage\Questions\Score;

class JudgementWriter
{
    /**
     * @param  array<string, Answer>  $answers
     * @param  array<string, Question>  $questions  used to label a score when the API returns no legend
     * @return Collection<string, Judgement>
     */
    public function write(
        Model $model,
        array $answers,
        string $jevModel,
        int $inputTokens,
        string $stateFingerprint,
        string $questionsFingerprint,
        array $questions = [],
    ): Collection {
        $written = collect();
        $now = now();

        foreach ($answers as $key => $answer) {
            $written[$key] = Judgement::updateOrCreate(
                [
                    'triageable_type' => $model->getMorphClass(),
                    'triageable_id' => $model->getKey(),
                    'key' => $key,
                ],
                array_merge($this->columns($answer, $questions[$key] ?? null), [
                    'key' => $key,
                    'state_fingerprint' => $stateFingerprint,
                    'questions_fingerprint' => $questionsFingerprint,
                    'stale' => false,
                    'model' => $jevModel,
                    'input_tokens' => $inputTokens,
                    'updated_at' => $now,
                ]),
            );
        }

        return $written;
    }

    protected function columns(Answer $answer, ?Question $question): array
    {
        return [
            'type' => $answer->type,
            'value' => match ($answer->type) {
                'choice' => $answer->value,
                // A score's number is the answer; the label is a convenience so
                // a row reads without consulting the legend.
                'score' => $this->nearestLevel($answer, $question),
                default => null,
            },
            'number' => is_float($answer->value) ? $answer->value : null,
            'probabilities' => $answer->probabilities ?: null,
            'confidence' => $answer->confidence,
            'legend' => $answer->legend ?: null,
        ];
    }

    protected function nearestLevel(Answer $answer, ?Question $question): ?string
    {
        if ($answer->value === null) {
            return null;
        }

        $index = (int) round((float) $answer->value);

        // The API returns a legend most of the time; when it does not, we still
        // hold the levels we sent, so the label is recoverable either way.
        if ($answer->legend !== []) {
            return $answer->legend[(string) $index] ?? null;
        }

        if ($question instanceof Score) {
            return $question->toPayload()['criteria'][$index] ?? null;
        }

        return null;
    }
}
