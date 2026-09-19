<?php

namespace Solarise\SmartTriage\Questions;

/**
 * One typed judgement.
 *
 * Question ids are never sent to the model — "urgent" means nothing to the model.
 * Everything the model gets to read lives in instructions and criteria, which
 * is why every question here forces you to write an instruction.
 */
interface Question
{
    /** The request fragment for this question, exactly as the API wants it. */
    public function toPayload(): array;

    /** Changes here invalidate stored answers, so this feeds the questions fingerprint. */
    public function fingerprint(): string;
}
