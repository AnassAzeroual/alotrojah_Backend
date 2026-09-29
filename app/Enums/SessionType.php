<?php

namespace App\Enums;

enum SessionType: string
{
    case Memorization = 'memorization';
    case Revision = 'revision';
    case Exam = 'exam';

    public function label(): string
    {
        return match ($this) {
            self::Memorization => 'حفظ',
            self::Revision => 'مراجعة',
            self::Exam => 'اختبار',
        };
    }
}
