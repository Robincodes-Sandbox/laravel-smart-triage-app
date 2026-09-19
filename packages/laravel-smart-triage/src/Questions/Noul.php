<?php

namespace Solarise\SmartTriage\Questions;

/**
 * Whether something holds, as a probability.
 *
 * 0.5 means "as likely as not", never "medium amount" — if you want an amount,
 * you want a Score. When several labels can apply at once, ask one noul each
 * rather than forcing a single choice; they all run in the same request anyway.
 */
class Noul implements Question
{
    protected ?string $whenTrue = null;

    protected ?string $whenFalse = null;

    public function __construct(protected string $instructions) {}

    public static function make(string $instructions): self
    {
        return new self($instructions);
    }

    /** Draw the line explicitly where yes and no are a fine distinction. */
    public function criteria(string $true, string $false): self
    {
        $this->whenTrue = $true;
        $this->whenFalse = $false;

        return $this;
    }

    public function toPayload(): array
    {
        $payload = [
            'type' => 'noul',
            'instructions' => $this->instructions,
        ];

        if ($this->whenTrue !== null) {
            $payload['criteria'] = ['true' => $this->whenTrue, 'false' => $this->whenFalse];
        }

        return $payload;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toPayload()));
    }
}
