<?php

namespace Solarise\SmartTriage\Exceptions;

use RuntimeException;

class TriageException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly array $body = [])
    {
        parent::__construct($message, $status ?? 0);
    }

    public static function fromStatus(int $status, array $body): self
    {
        $detail = $body['error']['message'] ?? $body['message'] ?? null;

        $message = match ($status) {
            401 => 'The triage driver rejected the API key.',
            422 => 'The triage driver rejected the request as invalid.',
            429 => 'Triage driver rate limit exceeded.',
            529 => 'The triage driver is overloaded.',
            default => "The triage driver returned HTTP {$status}.",
        };

        return new self($detail ? "{$message} {$detail}" : $message, $status, $body);
    }

    /**
     * 429 and 529 are the two the docs tell you to back off and retry.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, [429, 529], true);
    }
}
