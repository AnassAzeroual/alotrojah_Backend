<?php

declare(strict_types=1);

namespace App\Controllers;

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
     * GET /v1/dashboard/center
     * Center-level dashboard for a season: KPI cards + honor distribution + attendance breakdown.
     */
    public function center(Request $request): Response
    {
        $v = (new Validator($request->query()))
            ->required('center_id')->integer('center_id')
            ->required('season_id')->integer('season_id');

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $centerId = (int) $request->query('center_id');
        $seasonId = (int) $request->query('season_id');
        $db = Database::getInstance();

        // Active students in season
        $activeStudentsSql = '
            SELECT DISTINCT s.id FROM students s
            WHERE s.center_id = :cid
            AND (
                EXISTS (SELECT 1 FROM term_plans WHERE student_id = s.id AND season_id = :sid1)
                OR EXISTS (SELECT 1 FROM memorization_logs WHERE student_id = s.id AND season_id = :sid2)
                OR EXISTS (SELECT 1 FROM attendance_totals WHERE student_id = s.id AND season_id = :sid3)
            )
        ';
        $studentRows = $db->query($activeStudentsSql, [
            'cid' => $centerId,
            'sid1' => $seasonId,
            'sid2' => $seasonId,
            'sid3' => $seasonId,
        ])->fetchAll();
        $studentCount = count($studentRows);

        $avgScore = null;
        $avgSarraj = null;
        $avgAttendance = null;

        if ($studentCount > 0) {
            // Averages from views for active students
            $kpi = $db->query(
                "SELECT
                    ROUND(AVG(season_avg_score), 2) as avg_score,
                    ROUND(AVG(season_avg_sarraj), 2) as avg_sarraj,
                    ROUND(AVG(season_attendance_pct), 1) as avg_attendance
                 FROM v_season_dashboard v
                 JOIN students s ON s.id = v.student_id
                 WHERE s.center_id = :cid AND v.season_id = :sid",
                ['cid' => $centerId, 'sid' => $seasonId]
            )->fetch();

            if ($kpi) {
                $avgScore = $kpi['avg_score'] !== null ? (float) $kpi['avg_score'] : null;
                $avgSarraj = $kpi['avg_sarraj'] !== null ? (float) $kpi['avg_sarraj'] : null;
                $avgAttendance = $kpi['avg_attendance'] !== null ? (float) $kpi['avg_attendance'] : null;
            }
        }

        // Honors distribution
        $honors = $db->query(
            "SELECT r.honor_flag, COUNT(*) as n
             FROM term_results r
             JOIN students s ON s.id = r.student_id
             WHERE s.center_id = :cid AND r.season_id = :sid
             GROUP BY r.honor_flag",
            ['cid' => $centerId, 'sid' => $seasonId]
        )->fetchAll();

        $formattedHonors = array_map(function (array $r): array {
            return [
                'honor_flag' => (string) $r['honor_flag'],
                'n' => (int) $r['n'],
            ];
        }, $honors);

        // Attendance breakdown
        $attendance = $db->query(
            "SELECT a.status, COUNT(*) as n
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             WHERE s.center_id = :cid AND a.season_id = :sid
             GROUP BY a.status",
            ['cid' => $centerId, 'sid' => $seasonId]
        )->fetchAll();

        $formattedAttendance = array_map(function (array $r): array {
            return [
                'status' => (string) $r['status'],
                'n' => (int) $r['n'],
            ];
        }, $attendance);

        return $this->ok([
            'cards' => [
                'students' => $studentCount,
                'avg_score' => $avgScore,
                'avg_sarraj' => $avgSarraj,
                'avg_attendance' => $avgAttendance,
            ],
            'honors' => $formattedHonors,
            'attendance' => $formattedAttendance,
        ]);
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
