<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class SeasonController extends BaseController
{
    private const TERMS_PER_SEASON = 6;
    private const WEEKS_PER_TERM = 7;
    private const DEFAULT_SESSIONS_PER_WEEK = 3;

    private function formatSeason(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'center_id' => $row['center_id'] !== null ? (int) $row['center_id'] : null,
            'hijri_year' => $row['hijri_year'] ?? null,
            'start_date' => $row['start_date'] ?? null,
            'end_date' => $row['end_date'] ?? null,
            'total_weeks' => (int) $row['total_weeks'],
            'sessions_per_week' => isset($row['sessions_per_week']) ? (int) $row['sessions_per_week'] : self::DEFAULT_SESSIONS_PER_WEEK,
            'total_sessions' => (int) $row['total_sessions'],
            'is_current' => (bool) $row['is_current'],
            'terms_count' => isset($row['terms_count']) ? (int) $row['terms_count'] : 0,
            'first_term_id' => isset($row['first_term_id']) && $row['first_term_id'] !== null ? (int) $row['first_term_id'] : null,
        ];
    }

    public function index(Request $request): Response
    {
        $me = $request->getUser();
        $role = $me['role'] ?? 'teacher';

        $conditions = [];
        $params = [];

        if ($role !== 'admin') {
            // A center's seasons are its own. Global/shared rows are an admin
            // concept and must not make a brand-new center look configured.
            $conditions[] = "s.center_id = :cid";
            $params['cid'] = (int) $me['center_id'];
        } elseif ($request->query('center_id')) {
            $conditions[] = "(s.center_id IS NULL OR s.center_id = :cid)";
            $params['cid'] = (int) $request->query('center_id');
        }

        if ($request->query('current')) {
            $conditions[] = "s.is_current = 1";
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT s.*,
                   (SELECT COUNT(*) FROM `terms` t WHERE t.season_id = s.id) AS terms_count,
                   (SELECT MIN(t.id) FROM `terms` t WHERE t.season_id = s.id) AS first_term_id
            FROM `academic_seasons` s
            {$whereClause}
            ORDER BY s.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatSeason'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);

        $name = trim((string) $request->input('name'));
        if (Database::fetchValue("SELECT 1 FROM `academic_seasons` WHERE name = :name", ['name' => $name])) {
            return $this->fail('A season with this name already exists.', 422, ['code' => 'SEASON_EXISTS']);
        }

        $me = $request->getUser();
        $centerId = $me['role'] === 'admin'
            ? ($request->input('center_id') ? (int) $request->input('center_id') : null)
            : (int) $me['center_id'];

        $startDate = $request->input('start_date') ?: date('Y-m-d');
        $hijriYear = $request->input('hijri_year');
        $sessionsPerWeek = filter_var(
            $request->input('sessions_per_week', self::DEFAULT_SESSIONS_PER_WEEK),
            FILTER_VALIDATE_INT,
        );
        if ($sessionsPerWeek === false || $sessionsPerWeek < 1 || $sessionsPerWeek > 7) {
            return $this->fail('Sessions per week must be between 1 and 7.', 422);
        }

        $seasonId = Database::transaction(function () use ($name, $centerId, $startDate, $hijriYear, $sessionsPerWeek) {
            $sid = Database::insert('academic_seasons', [
                'name' => $name,
                'center_id' => $centerId,
                'hijri_year' => $hijriYear,
                'start_date' => $startDate,
                'total_weeks' => self::TERMS_PER_SEASON * self::WEEKS_PER_TERM,
                'sessions_per_week' => $sessionsPerWeek,
                'total_sessions' => self::TERMS_PER_SEASON * self::WEEKS_PER_TERM * $sessionsPerWeek,
                'is_current' => 0,
            ]);

            // This is the season plan used by term metadata. SessionGenerator
            // creates actual per-group rows from each group's schedule_days.
            $globalWeek = 1;
            $globalSession = 1;
            $curDate = new \DateTime($startDate);

            for ($t = 1; $t <= self::TERMS_PER_SEASON; $t++) {
                $termStartWeek = $globalWeek;
                $termEndWeek = $globalWeek + self::WEEKS_PER_TERM - 1;
                $termStartSession = $globalSession;
                $termEndSession = $globalSession + (self::WEEKS_PER_TERM * $sessionsPerWeek) - 1;

                $termNames = [
                    1 => 'الفصل الأول',
                    2 => 'الفصل الثاني',
                    3 => 'الفصل الثالث',
                    4 => 'الفصل الرابع',
                    5 => 'الفصل الخامس',
                    6 => 'الفصل السادس',
                ];

                $termId = Database::insert('terms', [
                    'season_id' => $sid,
                    'term_number' => $t,
                    'name_ar' => $termNames[$t] ?? "الفصل {$t}",
                    'start_week' => $termStartWeek,
                    'end_week' => $termEndWeek,
                    'start_session_no' => $termStartSession,
                    'end_session_no' => $termEndSession,
                ]);

                for ($w = 1; $w <= self::WEEKS_PER_TERM; $w++) {
                    $weekType = $w === 7 ? 'review' : 'study';
                    $weekStartDate = $curDate->format('Y-m-d');
                    $weekEndDate = (clone $curDate)->modify('+6 days')->format('Y-m-d');

                    $weekId = Database::insert('weeks', [
                        'season_id' => $sid,
                        'term_id' => $termId,
                        'week_number_global' => $globalWeek,
                        'week_number_in_term' => $w,
                        'week_type' => $weekType,
                        'start_date' => $weekStartDate,
                        'end_date' => $weekEndDate,
                    ]);

                    // Sessions are generated per group by SessionGenerator after
                    // the season transaction: they require group_id and times.
                    $globalSession += $sessionsPerWeek;
                    $globalWeek++;
                    $curDate->modify('+7 days');
                }
            }

            Database::update('academic_seasons', [
                'end_date' => (clone $curDate)->modify('-1 day')->format('Y-m-d'),
            ], 'id = :id', ['id' => $sid]);

            return $sid;
        });

        \App\Core\SessionGenerator::forSeason((int) $seasonId, $centerId);

        $created = Database::fetchOne("
            SELECT s.*,
                   (SELECT COUNT(*) FROM `terms` t WHERE t.season_id = s.id) AS terms_count,
                   (SELECT MIN(t.id) FROM `terms` t WHERE t.season_id = s.id) AS first_term_id
            FROM `academic_seasons` s
            WHERE s.id = :id
        ", ['id' => $seasonId]);

        return $this->created($this->formatSeason($created));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['season'] ?? 0);
        $row = Database::fetchOne("
            SELECT s.*,
                   (SELECT COUNT(*) FROM `terms` t WHERE t.season_id = s.id) AS terms_count,
                   (SELECT MIN(t.id) FROM `terms` t WHERE t.season_id = s.id) AS first_term_id
            FROM `academic_seasons` s
            WHERE s.id = :id
        ", ['id' => $id]);

        if (!$row) {
            return $this->fail('Season not found.', 404);
        }

        return $this->ok($this->formatSeason($row));
    }

    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['season'] ?? 0);
        $season = Database::fetchOne("SELECT * FROM `academic_seasons` WHERE id = :id", ['id' => $id]);
        if (!$season) {
            return $this->fail('Season not found.', 404);
        }

        $me = $request->getUser();
        if ($me['role'] !== 'admin' && ($season['center_id'] === null || (int) $season['center_id'] !== (int) $me['center_id'])) {
            return $this->fail('Season belongs to another center.', 403);
        }

        $name = $request->input('name');
        if ($name !== null) {
            $name = trim((string) $name);
            if ($name !== $season['name'] && Database::fetchValue("SELECT 1 FROM `academic_seasons` WHERE name = :name AND id != :id", ['name' => $name, 'id' => $id])) {
                return $this->fail('A season with this name already exists.', 422, ['code' => 'SEASON_EXISTS']);
            }
        }

        $update = [];
        foreach (['name', 'hijri_year', 'start_date', 'end_date'] as $f) {
            if ($request->input($f) !== null) {
                $update[$f] = $request->input($f);
            }
        }

        if (!empty($update)) {
            Database::update('academic_seasons', $update, 'id = :id', ['id' => $id]);
        }

        $fresh = Database::fetchOne("
            SELECT s.*,
                   (SELECT COUNT(*) FROM `terms` t WHERE t.season_id = s.id) AS terms_count,
                   (SELECT MIN(t.id) FROM `terms` t WHERE t.season_id = s.id) AS first_term_id
            FROM `academic_seasons` s
            WHERE s.id = :id
        ", ['id' => $id]);

        return $this->ok($this->formatSeason($fresh));
    }

    public function activate(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['season'] ?? 0);
        $season = Database::fetchOne("SELECT * FROM `academic_seasons` WHERE id = :id", ['id' => $id]);
        if (!$season) {
            return $this->fail('Season not found.', 404);
        }

        $me = $request->getUser();
        if ($me['role'] !== 'admin' && ($season['center_id'] === null || (int) $season['center_id'] !== (int) $me['center_id'])) {
            return $this->fail('Season belongs to another center.', 403);
        }

        Database::transaction(function () use ($season, $id) {
            if ($season['center_id'] === null) {
                Database::execute("UPDATE `academic_seasons` SET is_current = 0 WHERE center_id IS NULL");
            } else {
                Database::execute("UPDATE `academic_seasons` SET is_current = 0 WHERE center_id = :cid", ['cid' => $season['center_id']]);
            }
            Database::update('academic_seasons', ['is_current' => 1], 'id = :id', ['id' => $id]);
        });

        $fresh = Database::fetchOne("
            SELECT s.*,
                   (SELECT COUNT(*) FROM `terms` t WHERE t.season_id = s.id) AS terms_count,
                   (SELECT MIN(t.id) FROM `terms` t WHERE t.season_id = s.id) AS first_term_id
            FROM `academic_seasons` s
            WHERE s.id = :id
        ", ['id' => $id]);

        return $this->ok($this->formatSeason($fresh));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['season'] ?? 0);

        $season = Database::fetchOne("SELECT * FROM `academic_seasons` WHERE id = :id", ['id' => $id]);
        if (!$season) {
            return $this->fail('Season not found.', 404);
        }

        $hasFacts = Database::fetchValue("SELECT 1 FROM `memorization_logs` WHERE season_id = :id LIMIT 1", ['id' => $id])
            || Database::fetchValue("SELECT 1 FROM `session_scores` WHERE season_id = :id LIMIT 1", ['id' => $id])
            || Database::fetchValue("SELECT 1 FROM `attendance` WHERE season_id = :id LIMIT 1", ['id' => $id])
            || Database::fetchValue("SELECT 1 FROM `exams` WHERE season_id = :id LIMIT 1", ['id' => $id])
            || Database::fetchValue("SELECT 1 FROM `student_levels` WHERE season_id = :id LIMIT 1", ['id' => $id]);

        if ($hasFacts) {
            return $this->fail('Season has recorded facts and cannot be deleted.', 422, ['code' => 'SEASON_HAS_FACTS']);
        }

        Database::delete('academic_seasons', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }
}
