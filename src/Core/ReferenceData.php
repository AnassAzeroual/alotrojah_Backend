<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application reference data that every installation needs, independent of
 * demo/seed data. Inserts are idempotent by module code.
 */
final class ReferenceData
{
    /** @var array<int, array{code:string,name_ar:string,max_points:float,scope:string,weekly:int,sort:int}> */
    private const SCORING_MODULES = [
        ['code' => 'hifz', 'name_ar' => 'الحفظ', 'max_points' => 14.0, 'scope' => 'weekly', 'weekly' => 1, 'sort' => 1],
        ['code' => 'mowathaba', 'name_ar' => 'المواظبة / الحضور', 'max_points' => 4.0, 'scope' => 'weekly', 'weekly' => 1, 'sort' => 2],
        ['code' => 'tajwid', 'name_ar' => 'التجويد', 'max_points' => 2.0, 'scope' => 'weekly', 'weekly' => 1, 'sort' => 3],
        ['code' => 'murajaa', 'name_ar' => 'المراجعة', 'max_points' => 20.0, 'scope' => 'murajaa', 'weekly' => 1, 'sort' => 4],
        ['code' => 'sarraj', 'name_ar' => 'السراج في بيان غريب القرآن', 'max_points' => 20.0, 'scope' => 'weekly', 'weekly' => 0, 'sort' => 5],
    ];

    /**
     * Check the base tables/columns that the native API expects. This catches
     * a schema-only or partial production import before users hit a 500.
     *
     * @return array{ok:bool, missing_tables:array<int,string>, missing_columns:array<int,string>, missing_modules:array<int,string>, quran_verses:int}
     */
    public static function audit(): array
    {
        $tables = [
            'academic_seasons', 'announcements', 'appearance_settings', 'attendance',
            'centers', 'delegation_tokens', 'exam_questions', 'exams', 'group_teachers',
            'group_weekdays', 'groups', 'levels', 'memorization_logs', 'murajaa_reviews',
            'notifications_log', 'quran_verses', 'registration_requests', 'revision_logs',
            'scoring_modules', 'season_results', 'session_notes', 'session_scores', 'sessions',
            'student_levels', 'students', 'term_plans', 'term_results', 'terms', 'users',
            'weekly_goals', 'weeks',
        ];
        $columns = [
            'groups' => ['schedule_days'],
            'academic_seasons' => ['sessions_per_week'],
            'session_notes' => ['module_code', 'tone', 'visibility'],
            'group_teachers' => ['role'],
            'scoring_modules' => ['code', 'max_points', 'scope', 'is_in_weekly_total'],
            'quran_verses' => ['sura_no', 'surah_name', 'ayah_no', 'text'],
        ];

        $missingTables = [];
        $missingColumns = [];
        $existing = [];
        foreach (Database::fetchAll('SHOW TABLES') as $row) {
            $existing[(string) reset($row)] = true;
        }
        foreach ($tables as $table) {
            if (! isset($existing[$table])) {
                $missingTables[] = $table;
                continue;
            }
            $available = [];
            foreach (Database::fetchAll('SHOW COLUMNS FROM `'.$table.'`') as $column) {
                $available[(string) $column['Field']] = true;
            }
            foreach ($columns[$table] ?? [] as $column) {
                if (! isset($available[$column])) {
                    $missingColumns[] = "{$table}.{$column}";
                }
            }
        }

        $moduleCodes = [];
        if (isset($existing['scoring_modules'])) {
            foreach (Database::fetchAll('SELECT code FROM `scoring_modules`') as $row) {
                $moduleCodes[(string) $row['code']] = true;
            }
        }
        $missingModules = [];
        foreach (self::SCORING_MODULES as $module) {
            if (! isset($moduleCodes[$module['code']])) {
                $missingModules[] = $module['code'];
            }
        }

        $quranVerses = 0;
        if (isset($existing['quran_verses'])) {
            $quranVerses = (int) Database::fetchValue('SELECT COUNT(*) FROM `quran_verses`');
        }

        return [
            'ok' => $missingTables === [] && $missingColumns === [] && $missingModules === [] && $quranVerses > 0,
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'missing_modules' => $missingModules,
            'quran_verses' => $quranVerses,
        ];
    }

    public static function seedScoringModules(): int
    {
        $created = 0;
        foreach (self::SCORING_MODULES as $module) {
            $exists = Database::fetchValue(
                'SELECT 1 FROM `scoring_modules` WHERE code = :code LIMIT 1',
                ['code' => $module['code']],
            );
            if ($exists !== null) {
                continue;
            }

            Database::insert('scoring_modules', [
                'center_id' => null,
                'code' => $module['code'],
                'name_ar' => $module['name_ar'],
                'max_points' => $module['max_points'],
                'scope' => $module['scope'],
                'is_active' => 1,
                'is_in_weekly_total' => $module['weekly'],
                'sort_order' => $module['sort'],
                'created_by' => null,
            ]);
            $created++;
        }

        return $created;
    }
}
