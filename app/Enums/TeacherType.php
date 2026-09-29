<?php

namespace App\Enums;

enum TeacherType: string
{
    case Hifz = 'hifz';
    case Murajaa = 'murajaa';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Hifz => 'تحفيظ',
            self::Murajaa => 'مراجعة',
            self::Both => 'كلاهما',
        };
    }
}
