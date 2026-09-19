<?php

namespace Solarise\SmartTriage\Tests\Fixtures;

enum Channel: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Portal = 'portal';

    public function description(): string
    {
        return match ($this) {
            self::Email => 'Written in, expects a written reply',
            self::Phone => 'Rang up, spoke to someone',
            self::Portal => 'Submitted through the account area',
        };
    }
}
