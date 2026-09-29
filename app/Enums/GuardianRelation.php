<?php

namespace App\Enums;

enum GuardianRelation: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Father => 'أب',
            self::Mother => 'أم',
            self::Other => 'أخرى',
        };
    }
}
