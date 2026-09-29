<?php

namespace App\Enums;

enum MemorizationMode: string
{
    case Surah = 'surah';
    case Thumn = 'thumn';

    public function label(): string
    {
        return match ($this) {
            self::Surah => 'بالسورة',
            self::Thumn => 'بالثمن',
        };
    }
}
