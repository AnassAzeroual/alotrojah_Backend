<?php

namespace App\Enums;

enum ExamType: string
{
    case Hizb_completion = 'hizb_completion';
    case Term_batch = 'term_batch';
    case Final_season = 'final_season';

    public function label(): string
    {
        return match ($this) {
            self::Hizb_completion => 'اكتمال الحزب',
            self::Term_batch => 'حصيلة الفصل',
            self::Final_season => 'النهائي',
        };
    }
}
