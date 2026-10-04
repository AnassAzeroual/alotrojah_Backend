<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Item 8 prep (2026-10-04): reference data lives in migrations now —
// `php artisan migrate` alone yields a working empty DB (levels, scoring
// modules, 114 surahs, 60 hizb rows). Seasons/terms/weeks come from the
// app's season generator; centers/users from the UI. upsert/insertOrIgnore
// so DBs seeded the old way simply no-op.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('levels')->upsert(
[
            ['code' => 'L1', 'name_ar' => 'المستوى الأول — نصف في الأسبوع', 'sessions_per_week' => 3, 'thumn_per_session_label' => 'ثمن ويزيد', 'thumn_per_session_value' => 1.25, 'thumn_per_week_value' => 4.0, 'ahzab_per_term' => 2.0, 'ahzab_per_dawra' => 15.0, 'duration_label' => '4 سنوات (أربع دورات)'],
            ['code' => 'L2', 'name_ar' => 'المستوى الثاني — 3 أثمان في الأسبوع', 'sessions_per_week' => 3, 'thumn_per_session_label' => 'ثمن', 'thumn_per_session_value' => 1.0, 'thumn_per_week_value' => 3.0, 'ahzab_per_term' => 2.25, 'ahzab_per_dawra' => 11.5, 'duration_label' => '5 سنوات وشهرين (خمس دورات)'],
            ['code' => 'L3', 'name_ar' => 'المستوى الثالث — ربع في الأسبوع', 'sessions_per_week' => 3, 'thumn_per_session_label' => 'أقل من ثمن', 'thumn_per_session_value' => 0.66, 'thumn_per_week_value' => 2.0, 'ahzab_per_term' => 1.5, 'ahzab_per_dawra' => 7.5, 'duration_label' => '8 سنوات (ثمان دورات)'],
        ],
            ['code'],
            ['name_ar', 'sessions_per_week', 'thumn_per_session_label', 'thumn_per_session_value', 'thumn_per_week_value', 'ahzab_per_term', 'ahzab_per_dawra', 'duration_label']
        );

        DB::table('scoring_modules')->upsert(
[
            ['id' => 1, 'code' => 'hifz', 'name_ar' => 'الحفظ', 'max_points' => 14.0, 'scope' => 'weekly', 'is_active' => 1, 'is_in_weekly_total' => 1, 'sort_order' => 1],
            ['id' => 2, 'code' => 'mowathaba', 'name_ar' => 'المواظبة / الحضور', 'max_points' => 4.0, 'scope' => 'weekly', 'is_active' => 1, 'is_in_weekly_total' => 1, 'sort_order' => 2],
            ['id' => 3, 'code' => 'tajwid', 'name_ar' => 'التجويد', 'max_points' => 2.0, 'scope' => 'weekly', 'is_active' => 1, 'is_in_weekly_total' => 1, 'sort_order' => 3],
            ['id' => 4, 'code' => 'murajaa', 'name_ar' => 'المراجعة', 'max_points' => 20.0, 'scope' => 'murajaa', 'is_active' => 1, 'is_in_weekly_total' => 1, 'sort_order' => 4],
            ['id' => 5, 'code' => 'sarraj', 'name_ar' => 'السراج في بيان غريب القرآن', 'max_points' => 20.0, 'scope' => 'weekly', 'is_active' => 1, 'is_in_weekly_total' => 0, 'sort_order' => 5],
        ],
            ['id'],
            ['name_ar', 'max_points', 'is_active', 'is_in_weekly_total']
        );

        DB::table('surahs')->insertOrIgnore(
[
            ['id' => 1, 'name_ar' => 'الفاتحة', 'ayahs_count' => 7],
            ['id' => 2, 'name_ar' => 'البقرة', 'ayahs_count' => 286],
            ['id' => 3, 'name_ar' => 'آل عمران', 'ayahs_count' => 200],
            ['id' => 4, 'name_ar' => 'النساء', 'ayahs_count' => 176],
            ['id' => 5, 'name_ar' => 'المائدة', 'ayahs_count' => 120],
            ['id' => 6, 'name_ar' => 'الأنعام', 'ayahs_count' => 165],
            ['id' => 7, 'name_ar' => 'الأعراف', 'ayahs_count' => 206],
            ['id' => 8, 'name_ar' => 'الأنفال', 'ayahs_count' => 75],
            ['id' => 9, 'name_ar' => 'التوبة', 'ayahs_count' => 129],
            ['id' => 10, 'name_ar' => 'يونس', 'ayahs_count' => 109],
            ['id' => 11, 'name_ar' => 'هود', 'ayahs_count' => 123],
            ['id' => 12, 'name_ar' => 'يوسف', 'ayahs_count' => 111],
            ['id' => 13, 'name_ar' => 'الرعد', 'ayahs_count' => 43],
            ['id' => 14, 'name_ar' => 'إبراهيم', 'ayahs_count' => 52],
            ['id' => 15, 'name_ar' => 'الحجر', 'ayahs_count' => 99],
            ['id' => 16, 'name_ar' => 'النحل', 'ayahs_count' => 128],
            ['id' => 17, 'name_ar' => 'الإسراء', 'ayahs_count' => 111],
            ['id' => 18, 'name_ar' => 'الكهف', 'ayahs_count' => 110],
            ['id' => 19, 'name_ar' => 'مريم', 'ayahs_count' => 98],
            ['id' => 20, 'name_ar' => 'طه', 'ayahs_count' => 135],
            ['id' => 21, 'name_ar' => 'الأنبياء', 'ayahs_count' => 112],
            ['id' => 22, 'name_ar' => 'الحج', 'ayahs_count' => 78],
            ['id' => 23, 'name_ar' => 'المؤمنون', 'ayahs_count' => 118],
            ['id' => 24, 'name_ar' => 'النور', 'ayahs_count' => 64],
            ['id' => 25, 'name_ar' => 'الفرقان', 'ayahs_count' => 77],
            ['id' => 26, 'name_ar' => 'الشعراء', 'ayahs_count' => 227],
            ['id' => 27, 'name_ar' => 'النمل', 'ayahs_count' => 93],
            ['id' => 28, 'name_ar' => 'القصص', 'ayahs_count' => 88],
            ['id' => 29, 'name_ar' => 'العنكبوت', 'ayahs_count' => 69],
            ['id' => 30, 'name_ar' => 'الروم', 'ayahs_count' => 60],
            ['id' => 31, 'name_ar' => 'لقمان', 'ayahs_count' => 34],
            ['id' => 32, 'name_ar' => 'السجدة', 'ayahs_count' => 30],
            ['id' => 33, 'name_ar' => 'الأحزاب', 'ayahs_count' => 73],
            ['id' => 34, 'name_ar' => 'سبأ', 'ayahs_count' => 54],
            ['id' => 35, 'name_ar' => 'فاطر', 'ayahs_count' => 45],
            ['id' => 36, 'name_ar' => 'يس', 'ayahs_count' => 83],
            ['id' => 37, 'name_ar' => 'الصافات', 'ayahs_count' => 182],
            ['id' => 38, 'name_ar' => 'ص', 'ayahs_count' => 88],
            ['id' => 39, 'name_ar' => 'الزمر', 'ayahs_count' => 75],
            ['id' => 40, 'name_ar' => 'غافر', 'ayahs_count' => 85],
            ['id' => 41, 'name_ar' => 'فصلت', 'ayahs_count' => 54],
            ['id' => 42, 'name_ar' => 'الشورى', 'ayahs_count' => 53],
            ['id' => 43, 'name_ar' => 'الزخرف', 'ayahs_count' => 89],
            ['id' => 44, 'name_ar' => 'الدخان', 'ayahs_count' => 59],
            ['id' => 45, 'name_ar' => 'الجاثية', 'ayahs_count' => 37],
            ['id' => 46, 'name_ar' => 'الأحقاف', 'ayahs_count' => 35],
            ['id' => 47, 'name_ar' => 'محمد', 'ayahs_count' => 38],
            ['id' => 48, 'name_ar' => 'الفتح', 'ayahs_count' => 29],
            ['id' => 49, 'name_ar' => 'الحجرات', 'ayahs_count' => 18],
            ['id' => 50, 'name_ar' => 'ق', 'ayahs_count' => 45],
            ['id' => 51, 'name_ar' => 'الذاريات', 'ayahs_count' => 60],
            ['id' => 52, 'name_ar' => 'الطور', 'ayahs_count' => 49],
            ['id' => 53, 'name_ar' => 'النجم', 'ayahs_count' => 62],
            ['id' => 54, 'name_ar' => 'القمر', 'ayahs_count' => 55],
            ['id' => 55, 'name_ar' => 'الرحمن', 'ayahs_count' => 78],
            ['id' => 56, 'name_ar' => 'الواقعة', 'ayahs_count' => 96],
            ['id' => 57, 'name_ar' => 'الحديد', 'ayahs_count' => 29],
            ['id' => 58, 'name_ar' => 'المجادلة', 'ayahs_count' => 22],
            ['id' => 59, 'name_ar' => 'الحشر', 'ayahs_count' => 24],
            ['id' => 60, 'name_ar' => 'الممتحنة', 'ayahs_count' => 13],
            ['id' => 61, 'name_ar' => 'الصف', 'ayahs_count' => 14],
            ['id' => 62, 'name_ar' => 'الجمعة', 'ayahs_count' => 11],
            ['id' => 63, 'name_ar' => 'المنافقون', 'ayahs_count' => 11],
            ['id' => 64, 'name_ar' => 'التغابن', 'ayahs_count' => 18],
            ['id' => 65, 'name_ar' => 'الطلاق', 'ayahs_count' => 12],
            ['id' => 66, 'name_ar' => 'التحريم', 'ayahs_count' => 12],
            ['id' => 67, 'name_ar' => 'الملك', 'ayahs_count' => 30],
            ['id' => 68, 'name_ar' => 'القلم', 'ayahs_count' => 52],
            ['id' => 69, 'name_ar' => 'الحاقة', 'ayahs_count' => 52],
            ['id' => 70, 'name_ar' => 'المعارج', 'ayahs_count' => 44],
            ['id' => 71, 'name_ar' => 'نوح', 'ayahs_count' => 28],
            ['id' => 72, 'name_ar' => 'الجن', 'ayahs_count' => 28],
            ['id' => 73, 'name_ar' => 'المزمل', 'ayahs_count' => 20],
            ['id' => 74, 'name_ar' => 'المدثر', 'ayahs_count' => 56],
            ['id' => 75, 'name_ar' => 'القيامة', 'ayahs_count' => 40],
            ['id' => 76, 'name_ar' => 'الإنسان', 'ayahs_count' => 31],
            ['id' => 77, 'name_ar' => 'المرسلات', 'ayahs_count' => 50],
            ['id' => 78, 'name_ar' => 'النبأ', 'ayahs_count' => 40],
            ['id' => 79, 'name_ar' => 'النازعات', 'ayahs_count' => 46],
            ['id' => 80, 'name_ar' => 'عبس', 'ayahs_count' => 42],
            ['id' => 81, 'name_ar' => 'التكوير', 'ayahs_count' => 29],
            ['id' => 82, 'name_ar' => 'الانفطار', 'ayahs_count' => 19],
            ['id' => 83, 'name_ar' => 'المطففين', 'ayahs_count' => 36],
            ['id' => 84, 'name_ar' => 'الانشقاق', 'ayahs_count' => 25],
            ['id' => 85, 'name_ar' => 'البروج', 'ayahs_count' => 22],
            ['id' => 86, 'name_ar' => 'الطارق', 'ayahs_count' => 17],
            ['id' => 87, 'name_ar' => 'الأعلى', 'ayahs_count' => 19],
            ['id' => 88, 'name_ar' => 'الغاشية', 'ayahs_count' => 26],
            ['id' => 89, 'name_ar' => 'الفجر', 'ayahs_count' => 30],
            ['id' => 90, 'name_ar' => 'البلد', 'ayahs_count' => 20],
            ['id' => 91, 'name_ar' => 'الشمس', 'ayahs_count' => 15],
            ['id' => 92, 'name_ar' => 'الليل', 'ayahs_count' => 21],
            ['id' => 93, 'name_ar' => 'الضحى', 'ayahs_count' => 11],
            ['id' => 94, 'name_ar' => 'الشرح', 'ayahs_count' => 8],
            ['id' => 95, 'name_ar' => 'التين', 'ayahs_count' => 8],
            ['id' => 96, 'name_ar' => 'العلق', 'ayahs_count' => 19],
            ['id' => 97, 'name_ar' => 'القدر', 'ayahs_count' => 5],
            ['id' => 98, 'name_ar' => 'البينة', 'ayahs_count' => 8],
            ['id' => 99, 'name_ar' => 'الزلزلة', 'ayahs_count' => 8],
            ['id' => 100, 'name_ar' => 'العاديات', 'ayahs_count' => 11],
            ['id' => 101, 'name_ar' => 'القارعة', 'ayahs_count' => 11],
            ['id' => 102, 'name_ar' => 'التكاثر', 'ayahs_count' => 8],
            ['id' => 103, 'name_ar' => 'العصر', 'ayahs_count' => 3],
            ['id' => 104, 'name_ar' => 'الهمزة', 'ayahs_count' => 9],
            ['id' => 105, 'name_ar' => 'الفيل', 'ayahs_count' => 5],
            ['id' => 106, 'name_ar' => 'قريش', 'ayahs_count' => 4],
            ['id' => 107, 'name_ar' => 'الماعون', 'ayahs_count' => 7],
            ['id' => 108, 'name_ar' => 'الكوثر', 'ayahs_count' => 3],
            ['id' => 109, 'name_ar' => 'الكافرون', 'ayahs_count' => 6],
            ['id' => 110, 'name_ar' => 'النصر', 'ayahs_count' => 3],
            ['id' => 111, 'name_ar' => 'المسد', 'ayahs_count' => 5],
            ['id' => 112, 'name_ar' => 'الإخلاص', 'ayahs_count' => 4],
            ['id' => 113, 'name_ar' => 'الفلق', 'ayahs_count' => 5],
            ['id' => 114, 'name_ar' => 'الناس', 'ayahs_count' => 6],
        ]
        );

        for ($n = 1; $n <= 60; $n++) {
            DB::table('quran_hizb_reference')->insertOrIgnore([
                'hizb_no' => $n,
                'juz_no' => (int) ceil($n / 2),
                'label_ar' => 'الحزب ' . $n,
            ]);
        }
    }

    public function down(): void
    {
        // Reference data is never removed (FKs point at it everywhere).
    }
};