<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

class DashboardController extends BaseController
{
    /**
     * GET /v1/dashboard/me
     * The student's own dashboard.
     */
    public function me(Request $request): Response
    {
        $user = $request->user();
        if ($user === null || $user['role'] !== 'student') {
            return $this->forbidden('Only students can access this endpoint.');
        }

        $db = Database::getInstance();
        $student = $db->query(
            'SELECT s.*, g.name as g_name FROM students s
             LEFT JOIN `groups` g ON g.id = s.group_id
             WHERE s.user_id = :uid LIMIT 1',
            ['uid' => (int) $user['id']]
        )->fetch();

        if (!$student) {
            return $this->ok(null);
        }

        $seasonRow = $db->query(
            'SELECT id FROM academic_seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1'
        )->fetch();
        $seasonId = $seasonRow ? (int) $seasonRow['id'] : (int) $db->query('SELECT MAX(id) FROM academic_seasons')->fetchColumn();

        $studentResource = [
            'id' => (int) $student['id'],
            'full_name' => $student['full_name'],
            'gender' => $student['gender'],
            'phone' => $student['phone'],
            'birth_date' => $student['birth_date'],
            'center_id' => (int) $student['center_id'],
            'level_id' => $student['level_id'] !== null ? (int) $student['level_id'] : null,
            'group_id' => $student['group_id'] !== null ? (int) $student['group_id'] : null,
            'user_id' => $student['user_id'] !== null ? (int) $student['user_id'] : null,
            'group' => $student['group_id'] ? [
                'id' => (int) $student['group_id'],
                'name' => (string) ($student['g_name'] ?? ''),
            ] : null,
        ];

        if (!$seasonId) {
            return $this->ok([
                'student' => $studentResource,
                'season' => null,
                'weekly' => [],
                'final' => null,
            ]);
        }

        $seasonData = $this->getSeasonRow((int) $student['id'], $seasonId);
        $weeklyData = $this->getWeeklyProgress((int) $student['id'], $seasonId);
        $finalScore = $this->calculateFinalAverage((int) $student['id'], $seasonId);

        return $this->ok([
            'student' => $studentResource,
            'season' => $seasonData,
            'weekly' => $weeklyData,
            'final' => $finalScore,
        ]);
    }

    /**
     * GET /v1/dashboard/season
     * The season summary row for one pupil.
     */
    public function season(Request $request): Response
    {
        $v = (new Validator($request->query()))
            ->required('student_id')->integer('student_id')
            ->required('season_id')->integer('season_id');

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $studentId = (int) $request->query('student_id');
        $seasonId = (int) $request->query('season_id');

        $row = $this->getSeasonRow($studentId, $seasonId);

        return $this->ok($row);
    }

    /**
     * GET /v1/dashboard/weekly
     * One pupil's per-week progress bars for a season.
     */
    public function weekly(Request $request): Response
    {
        $v = (new Validator($request->query()))
            ->required('student_id')->integer('student_id')
            ->required('season_id')->integer('season_id');

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $studentId = (int) $request->query('student_id');
        $seasonId = (int) $request->query('season_id');

        $rows = $this->getWeeklyProgress($studentId, $seasonId);

        return $this->ok($rows);
    }

    /**
     * GET /v1/dashboard/overview
     * One role-scoped request for the statistics-only staff dashboard.
     */
    public function overview(Request $request): Response
    {
        $user = $request->user();
        if ($user === null || ! in_array($user['role'], ['admin', 'supervisor', 'teacher'], true)) {
            return $this->forbidden('Staff access only.');
        }

        $db = Database::getInstance();
        $role = (string) $user['role'];
        $seasonId = (int) $request->query('season_id', 0);
        $centerId = $role === 'supervisor'
            ? ($user['center_id'] !== null ? (int) $user['center_id'] : null)
            : ($role === 'admin' && $request->query('center_id') !== null && $request->query('center_id') !== ''
                ? (int) $request->query('center_id')
                : null);

        if ($role === 'admin' && $centerId !== null && ! Database::fetchValue(
            'SELECT 1 FROM centers WHERE id = :id',
            ['id' => $centerId],
        )) {
            return $this->unprocessable(['center_id' => ['The selected center is invalid.']]);
        }

        if ($seasonId === 0) {
            $seasonSql = $centerId !== null
                ? 'SELECT id, name, start_date, end_date FROM academic_seasons WHERE center_id = :cid OR center_id IS NULL ORDER BY is_current DESC, id DESC LIMIT 1'
                : 'SELECT id, name, start_date, end_date FROM academic_seasons ORDER BY is_current DESC, id DESC LIMIT 1';
            $season = $db->query($seasonSql, $centerId !== null ? ['cid' => $centerId] : [])->fetch();
            $seasonId = $season ? (int) $season['id'] : 0;
        } else {
            $season = $db->query(
                'SELECT id, name, start_date, end_date FROM academic_seasons WHERE id = :id LIMIT 1',
                ['id' => $seasonId],
            )->fetch();
        }

        if (! $season) {
            return $this->ok($this->emptyOverview($role, $centerId));
        }
        $seasonId = (int) $season['id'];

        $cacheDir = (string) Config::get('storage_path') . '/cache/dashboard';
        $cacheFile = $cacheDir . '/' . md5($role . '|' . ($role === 'teacher' ? (int) $user['id'] : 0) . '|' . ($centerId ?? 'all') . '|' . $seasonId) . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 30) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $this->ok($cached);
            }
        }

        $scopeCenterId = $centerId ?? ($user['center_id'] !== null ? (int) $user['center_id'] : null);
        $centerName = $scopeCenterId !== null
            ? Database::fetchValue('SELECT name FROM centers WHERE id = :id', ['id' => $scopeCenterId])
            : null;

        $studentWhere = '1 = 1';
        $studentParams = [];
        if ($role === 'teacher') {
            $studentWhere = 's.group_id IN (SELECT g.id FROM `groups` g WHERE g.teacher_id = :uid)';
            $studentParams['uid'] = (int) $user['id'];
        } elseif ($centerId !== null) {
            $studentWhere = 's.center_id = :cid';
            $studentParams['cid'] = $centerId;
        }

        $studentScope = "SELECT s.id, s.full_name, s.group_id, s.center_id, s.level_id, s.status, s.gender
            FROM students s WHERE {$studentWhere}";
        $groupWhere = $role === 'teacher'
            ? 'g.teacher_id = :uid'
            : ($centerId !== null ? 'g.center_id = :cid' : '1 = 1');
        $groupParams = $role === 'teacher' ? ['uid' => (int) $user['id']] : ($centerId !== null ? ['cid' => $centerId] : []);
        $groupScope = "SELECT g.id, g.name, g.center_id, g.teacher_id FROM `groups` g WHERE {$groupWhere}";

        $counts = $db->query(
            "SELECT COUNT(*) AS students, COALESCE(SUM(status = 'active'), 0) AS active_students,
                    COUNT(DISTINCT group_id) AS group_count
             FROM ({$studentScope}) ss",
            $studentParams,
        )->fetch() ?: [];

        $teacherCount = (int) $db->query(
            "SELECT COUNT(DISTINCT teacher_id) FROM ({$groupScope}) gs",
            $groupParams,
        )->fetchColumn();

        $viewParams = ['sid' => $seasonId] + $studentParams;
        $kpi = $db->query(
            "SELECT ROUND(AVG(v.season_avg_score), 2) AS avg_score,
                    ROUND(AVG(v.season_avg_sarraj), 2) AS avg_sarraj,
                    ROUND(AVG(v.season_attendance_pct), 1) AS attendance_pct
             FROM v_season_dashboard v
             JOIN ({$studentScope}) ss ON ss.id = v.student_id
             WHERE v.season_id = :sid",
            $viewParams,
        )->fetch() ?: [];

        $thumn = (float) $db->query(
            "SELECT COALESCE(SUM(m.thumn_amount), 0)
             FROM memorization_logs m
             JOIN ({$studentScope}) ss ON ss.id = m.student_id
             WHERE m.season_id = :sid",
            $viewParams,
        )->fetchColumn();

        $honorRows = $db->query(
            "SELECT r.honor_flag, COUNT(*) AS n
             FROM season_results r
             JOIN ({$studentScope}) ss ON ss.id = r.student_id
             WHERE r.season_id = :sid
             GROUP BY r.honor_flag",
            $viewParams,
        )->fetchAll();
        $honors = ['tashji3' => 0, 'intibah' => 0, 'none' => 0];
        foreach ($honorRows as $row) {
            $honors[(string) $row['honor_flag']] = (int) $row['n'];
        }

        $attendanceRows = $db->query(
            "SELECT a.status, COUNT(*) AS n
             FROM attendance a
             JOIN ({$studentScope}) ss ON ss.id = a.student_id
             WHERE a.season_id = :sid
             GROUP BY a.status",
            $viewParams,
        )->fetchAll();

        $scoreBands = $db->query(
            "SELECT CASE
                WHEN r.overall_avg >= 18 THEN 'excellent'
                WHEN r.overall_avg >= 15 THEN 'good'
                WHEN r.overall_avg >= 12 THEN 'average'
                ELSE 'support'
             END AS band, COUNT(*) AS n
             FROM season_results r
             JOIN ({$studentScope}) ss ON ss.id = r.student_id
             WHERE r.season_id = :sid
             GROUP BY band",
            $viewParams,
        )->fetchAll();

        $genderRows = $db->query(
            "SELECT gender, COUNT(*) AS n FROM ({$studentScope}) ss GROUP BY gender",
            $studentParams,
        )->fetchAll();

        $levelRows = $db->query(
            "SELECT l.name_ar AS label, COUNT(*) AS n
             FROM ({$studentScope}) ss
             LEFT JOIN levels l ON l.id = ss.level_id
             GROUP BY l.id, l.name_ar",
            $studentParams,
        )->fetchAll();

        $weeklyRows = $db->query(
            "SELECT v.week_id, ROUND(AVG(v.avg_score), 2) AS avg_score,
                    ROUND(SUM(v.total_thumn), 1) AS thumn
             FROM v_weekly_progress v
             JOIN ({$studentScope}) ss ON ss.id = v.student_id
             WHERE v.season_id = :sid
             GROUP BY v.week_id ORDER BY v.week_id",
            $viewParams,
        )->fetchAll();
        $weeklyAttendance = $db->query(
            "SELECT a.week_id,
                    ROUND(100 * SUM(a.status IN ('present','late')) / COUNT(*), 1) AS attendance_pct
             FROM attendance a
             JOIN ({$studentScope}) ss ON ss.id = a.student_id
             WHERE a.season_id = :sid
             GROUP BY a.week_id ORDER BY a.week_id",
            $viewParams,
        )->fetchAll();
        $attendanceByWeek = [];
        foreach ($weeklyAttendance as $row) {
            $attendanceByWeek[(int) $row['week_id']] = (float) $row['attendance_pct'];
        }
        $weekly = array_map(static fn (array $row): array => [
            'week' => (int) $row['week_id'],
            'avg_score' => $row['avg_score'] !== null ? (float) $row['avg_score'] : null,
            'attendance_pct' => $attendanceByWeek[(int) $row['week_id']] ?? null,
            'thumn' => (float) $row['thumn'],
        ], $weeklyRows);

        $groups = $db->query(
            "SELECT g.id, g.name, COUNT(DISTINCT s.id) AS students,
                    ROUND(AVG(v.season_avg_score), 2) AS avg_score,
                    ROUND(AVG(v.season_attendance_pct), 1) AS attendance_pct
             FROM ({$groupScope}) g
             LEFT JOIN students s ON s.group_id = g.id
             LEFT JOIN v_season_dashboard v ON v.student_id = s.id AND v.season_id = :sid
             GROUP BY g.id, g.name ORDER BY avg_score DESC, g.name",
            ['sid' => $seasonId] + $groupParams,
        )->fetchAll();

        $centers = [];
        if ($role !== 'teacher') {
            $centerWhere = $centerId !== null ? 'WHERE c.id = :cid' : '';
            $centerParams = $centerId !== null ? ['cid' => $centerId] : [];
            $centers = $db->query(
                "SELECT c.id, c.name, COUNT(DISTINCT s.id) AS students,
                        ROUND(AVG(v.season_avg_score), 2) AS avg_score,
                        ROUND(AVG(v.season_attendance_pct), 1) AS attendance_pct
                 FROM centers c
                 LEFT JOIN students s ON s.center_id = c.id
                 LEFT JOIN v_season_dashboard v ON v.student_id = s.id AND v.season_id = :sid
                 {$centerWhere}
                 GROUP BY c.id, c.name ORDER BY c.name",
                ['sid' => $seasonId] + $centerParams,
            )->fetchAll();
        }

        $scopeCenters = $role === 'admin'
            ? $db->query('SELECT id, name FROM centers ORDER BY name')->fetchAll()
            : $centers;
        $scopeCenters = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ], $scopeCenters);

        $topStudents = $db->query(
            "SELECT ss.id, ss.full_name, g.name AS group_name,
                    v.season_avg_score AS avg_score, v.season_attendance_pct AS attendance_pct
             FROM ({$studentScope}) ss
             LEFT JOIN `groups` g ON g.id = ss.group_id
             LEFT JOIN v_season_dashboard v ON v.student_id = ss.id AND v.season_id = :sid
             WHERE v.season_avg_score IS NOT NULL
             ORDER BY v.season_avg_score DESC LIMIT 5",
            $viewParams,
        )->fetchAll();

        $payload = [
            'scope' => [
                'role' => $role,
                'center_id' => $centerId,
                'center_name' => $centerName,
                'can_filter_center' => $role === 'admin',
                'season' => [
                    'id' => $seasonId,
                    'name' => (string) $season['name'],
                    'start_date' => $season['start_date'],
                    'end_date' => $season['end_date'],
                ],
                'centers' => $role === 'admin' ? $scopeCenters : [],
            ],
            'kpis' => [
                'students' => (int) ($counts['students'] ?? 0),
                'active_students' => (int) ($counts['active_students'] ?? 0),
                'groups' => (int) ($counts['group_count'] ?? 0),
                'teachers' => $teacherCount,
                'avg_score' => isset($kpi['avg_score']) ? (float) $kpi['avg_score'] : null,
                'avg_sarraj' => isset($kpi['avg_sarraj']) ? (float) $kpi['avg_sarraj'] : null,
                'attendance_pct' => isset($kpi['attendance_pct']) ? (float) $kpi['attendance_pct'] : null,
                'total_ahzab' => round($thumn / 8, 1),
                'honors' => $honors,
            ],
            'trends' => ['weekly' => $weekly],
            'distributions' => [
                'attendance' => array_map(static fn (array $r): array => [
                    'status' => (string) $r['status'], 'n' => (int) $r['n'],
                ], $attendanceRows),
                'scores' => array_map(static fn (array $r): array => [
                    'band' => (string) $r['band'], 'n' => (int) $r['n'],
                ], $scoreBands),
                'genders' => array_map(static fn (array $r): array => [
                    'gender' => (string) ($r['gender'] ?? 'unknown'), 'n' => (int) $r['n'],
                ], $genderRows),
                'levels' => array_map(static fn (array $r): array => [
                    'label' => (string) ($r['label'] ?? 'Unassigned'), 'n' => (int) $r['n'],
                ], $levelRows),
            ],
            'groups' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'name' => (string) $r['name'],
                'students' => (int) $r['students'],
                'avg_score' => $r['avg_score'] !== null ? (float) $r['avg_score'] : null,
                'attendance_pct' => $r['attendance_pct'] !== null ? (float) $r['attendance_pct'] : null,
            ], $groups),
            'centers' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'name' => (string) $r['name'],
                'students' => (int) $r['students'],
                'avg_score' => $r['avg_score'] !== null ? (float) $r['avg_score'] : null,
                'attendance_pct' => $r['attendance_pct'] !== null ? (float) $r['attendance_pct'] : null,
            ], $centers),
            'top_students' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'full_name' => (string) $r['full_name'],
                'group_name' => $r['group_name'] !== null ? (string) $r['group_name'] : null,
                'avg_score' => $r['avg_score'] !== null ? (float) $r['avg_score'] : null,
                'attendance_pct' => $r['attendance_pct'] !== null ? (float) $r['attendance_pct'] : null,
            ], $topStudents),
        ];

        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($payload), LOCK_EX);

        return $this->ok($payload);
    }

    private function emptyOverview(string $role, ?int $centerId): array
    {
        return [
            'scope' => [
                'role' => $role, 'center_id' => $centerId, 'center_name' => null,
                'can_filter_center' => $role === 'admin', 'season' => null, 'centers' => [],
            ],
            'kpis' => [
                'students' => 0, 'active_students' => 0, 'groups' => 0, 'teachers' => 0,
                'avg_score' => null, 'avg_sarraj' => null, 'attendance_pct' => null,
                'total_ahzab' => 0, 'honors' => ['tashji3' => 0, 'intibah' => 0, 'none' => 0],
            ],
            'trends' => ['weekly' => []],
            'distributions' => ['attendance' => [], 'scores' => [], 'genders' => [], 'levels' => []],
            'groups' => [], 'centers' => [], 'top_students' => [],
        ];
    }

    /**
     * GET /v1/dashboard/final
     * Transparent final average: components + divisor + result.
     */
    public function final(Request $request): Response
    {
        $v = (new Validator($request->query()))
            ->required('student_id')->integer('student_id')
            ->required('season_id')->integer('season_id');

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $studentId = (int) $request->query('student_id');
        $seasonId = (int) $request->query('season_id');
        $db = Database::getInstance();

        $avgs = $this->getSeasonAvgs($studentId, $seasonId);

        $quizzes = $db->query(
            "SELECT * FROM v_term_quiz_avgs
             WHERE student_id = :stuid AND season_id = :sid
             AND exam_type IN ('term_batch', 'final_season')
             ORDER BY term_id",
            ['stuid' => $studentId, 'sid' => $seasonId]
        )->fetchAll();

        $formattedQuizzes = array_map(function (array $r): array {
            return [
                'student_id' => (int) $r['student_id'],
                'season_id' => (int) $r['season_id'],
                'term_id' => (int) $r['term_id'],
                'exam_type' => (string) $r['exam_type'],
                'avg_score' => (float) $r['avg_score'],
                'questions_count' => (int) $r['questions_count'],
            ];
        }, $quizzes);

        $divisor = 2 + count($quizzes);
        $finalScore = $this->calculateFinalAverage($studentId, $seasonId);

        return $this->ok([
            'inputs' => $avgs,
            'quizzes' => $formattedQuizzes,
            'divisor' => $divisor,
            'final' => $finalScore,
        ]);
    }

    private function getSeasonRow(int $studentId, int $seasonId): ?array
    {
        $db = Database::getInstance();
        $r = $db->query(
            'SELECT * FROM v_season_dashboard WHERE student_id = :stuid AND season_id = :sid LIMIT 1',
            ['stuid' => $studentId, 'sid' => $seasonId]
        )->fetch();

        if (!$r) {
            return null;
        }

        return [
            'student_id' => (int) $r['student_id'],
            'full_name' => (string) $r['full_name'],
            'level_id' => $r['level_id'] !== null ? (int) $r['level_id'] : null,
            'group_id' => $r['group_id'] !== null ? (int) $r['group_id'] : null,
            'season_id' => (int) $r['season_id'],
            'season_thumn' => (float) $r['season_thumn'],
            'season_avg_score' => $r['season_avg_score'] !== null ? (float) $r['season_avg_score'] : null,
            'season_avg_sarraj' => $r['season_avg_sarraj'] !== null ? (float) $r['season_avg_sarraj'] : null,
            'season_attendance_pct' => $r['season_attendance_pct'] !== null ? (float) $r['season_attendance_pct'] : null,
            'season_overall_avg' => $r['season_overall_avg'] !== null ? (float) $r['season_overall_avg'] : null,
            'honor_flag' => $r['honor_flag'],
        ];
    }

    private function getWeeklyProgress(int $studentId, int $seasonId, ?int $termId = null): array
    {
        $db = Database::getInstance();
        $sql = 'SELECT * FROM v_weekly_progress WHERE student_id = :stuid AND season_id = :sid';
        $params = ['stuid' => $studentId, 'sid' => $seasonId];

        if ($termId !== null) {
            $sql .= ' AND term_id = :tid';
            $params['tid'] = $termId;
        }

        $sql .= ' ORDER BY week_id';
        $rows = $db->query($sql, $params)->fetchAll();

        return array_map(function (array $r): array {
            return [
                'student_id' => (int) $r['student_id'],
                'season_id' => (int) $r['season_id'],
                'term_id' => (int) $r['term_id'],
                'week_id' => (int) $r['week_id'],
                'total_thumn' => (float) $r['total_thumn'],
                'total_ahzab' => (float) $r['total_ahzab'],
                'avg_score' => $r['avg_score'] !== null ? (float) $r['avg_score'] : null,
                'sessions_logged' => (int) $r['sessions_logged'],
            ];
        }, $rows);
    }

    private function getSeasonAvgs(int $studentId, int $seasonId): array
    {
        $db = Database::getInstance();
        $r = $db->query(
            'SELECT * FROM v_student_season_avgs WHERE student_id = :stuid AND season_id = :sid LIMIT 1',
            ['stuid' => $studentId, 'sid' => $seasonId]
        )->fetch();

        if (!$r) {
            return [
                'avg_murajaa' => null,
                'avg_weekly' => null,
                'avg_sarraj' => null,
            ];
        }

        return [
            'avg_murajaa' => $r['avg_murajaa'] !== null ? (float) $r['avg_murajaa'] : null,
            'avg_weekly' => $r['avg_weekly'] !== null ? (float) $r['avg_weekly'] : null,
            'avg_sarraj' => $r['avg_sarraj'] !== null ? (float) $r['avg_sarraj'] : null,
        ];
    }

    private function calculateFinalAverage(int $studentId, int $seasonId): ?float
    {
        $avgs = $this->getSeasonAvgs($studentId, $seasonId);
        if ($avgs['avg_murajaa'] === null || $avgs['avg_weekly'] === null) {
            return null;
        }

        $db = Database::getInstance();
        $quizzes = $db->query(
            "SELECT overall_avg FROM exams
             WHERE student_id = :stuid AND season_id = :sid
             AND exam_type IN ('term_batch', 'final_season')
             AND overall_avg IS NOT NULL",
            ['stuid' => $studentId, 'sid' => $seasonId]
        )->fetchAll();

        if (empty($quizzes)) {
            return null;
        }

        $sumQuizzes = 0.0;
        foreach ($quizzes as $q) {
            $sumQuizzes += (float) $q['overall_avg'];
        }

        $divisor = 2 + count($quizzes);

        return round(($avgs['avg_murajaa'] + $avgs['avg_weekly'] + $sumQuizzes) / $divisor, 2);
    }
}
