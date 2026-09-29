<?php

namespace App\Enums;

enum HonorFlag: string
{
    case None = 'none';
    case Tashji3 = 'tashji3';
    case Intibah = 'intibah';

    public function label(): string
    {
        return match ($this) {
            self::None => 'لا شيء',
            self::Tashji3 => 'تشجيع',
            self::Intibah => 'انتبه',
        };
    }
}
