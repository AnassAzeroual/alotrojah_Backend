<?php

namespace App\Enums;

enum QuestionModel: string
{
    case Model1 = 'model1';
    case Model2_full = 'model2_full';
    case Single = 'single';

    public function label(): string
    {
        return match ($this) {
            self::Model1 => 'النموذج 1',
            self::Model2_full => 'السرد الكامل',
            self::Single => 'منفرد',
        };
    }
}
