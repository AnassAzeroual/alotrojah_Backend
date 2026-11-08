<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class ScoreController extends BaseController
{
    private function formatSessionScore(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'season_id' => (int) $row['season_id'],
            'term_id' => (int) $row['term_id'],
            'week_id' => (int) $row['week_id'],
            'session_id' => (int) $row['session_id'],
            'log_date' => $row['log_date'],
            'module_id' => (int) $row['module_id'],
            'module_code' => $row['module_code'] ?? null,
            'module_name' => $row['module_name'] ?? null,
            'score' => (float) $row['score'],
            'entered_by' => $row['entered_by'] !== null ? (int) $row['entered_by'] : null,
            'module' => !empty($row['module_id']) ? [
                'id' => (int) $row['module_id'],
                'code' => $row['module_code'] ?? '',
                'name_ar' => $row['module_name'] ?? '',
                'max_points' => isset($row['max_points']) ? (float) $row['max_points'] : 20.0,
            ] : null,
        ];
    }

    public function index(Request $request): Response
    {
        $me = $request->getUser();
        $role = $me['role'] ?? 'teacher';

        $conditions = [];
        $params = [];

        if ($role === 'student') {
            $conditions[] = "s.user_id = :my_uid";
            $params['my_uid'] = (int) $me['id'];
        } elseif ($role !== 'admin' && $role !== 'board') {
            $conditions[] = "s.center_id = :my_cid";
            $params['my_cid'] = (int) $me['center_id'];
        } elseif ($request->query('center_id')) {
            $conditions[] = "s.center_id = :cid";
            $params['cid'] = (int) $request->query('center_id');
        }

        foreach (['student_id', 'session_id', 'week_id', 'module_id'] as $f) {
            if ($request->query($f) !== null && $request->query($f) !== '') {
                $conditions[] = "sc.{$f} = :{$f}";
                $params[$f] = (int) $request->query($f);
            }
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT sc.*,
                   m.code AS module_code, m.name_ar AS module_name, m.max_points
            FROM `session_scores` sc
            JOIN `students` s ON s.id = sc.student_id
            JOIN `scoring_modules` m ON m.id = sc.module_id
            {$whereClause}
            ORDER BY sc.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 100), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatSessionScore'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function bulk(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        if ($me['role'] !== 'admin' && ($me['teacher_type'] ?? '') === 'murajaa') {
            return $this->fail('Review teachers enter murajaa cycles, not weekly scores.', 403);
        }

        $v = Validator::make($request->all(), [
            'session_id' => 'required|integer',
            'records' => 'required',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $sessionId = (int) $request->input('session_id');
        $session = Database::fetchOne("SELECT * FROM `sessions` WHERE id = :id", ['id' => $sessionId]);
        if (!$session) {
            return $this->fail('Session not found.', 404);
        }
        if (($session['status'] ?? '') === 'cancelled') {
            return $this->fail('Cancelled sessions cannot be edited.', 422);
        }

        $rows = (array) $request->input('records');
        $memorizations = (array) ($request->input('memorizations') ?? []);

        $allowedStudents = [];
        foreach (Database::fetchAll(
            'SELECT id FROM `students` WHERE group_id = :gid',
            ['gid' => (int) $session['group_id']],
        ) as $studentRow) {
            $allowedStudents[(int) $studentRow['id']] = true;
        }
        foreach ($rows as $row) {
            if (! isset($allowedStudents[(int) ($row['student_id'] ?? 0)])) {
                return $this->fail('A score row references a student outside this group.', 422);
            }
        }
        foreach ($memorizations as $row) {
            if (! isset($allowedStudents[(int) ($row['student_id'] ?? 0)])) {
                return $this->fail('A memorization row references a student outside this group.', 422);
            }
        }

        // Preload active weekly modules
        $modules = Database::fetchAll("SELECT * FROM `scoring_modules` WHERE is_active = 1 AND scope = 'weekly'");
        $modulesByCode = [];
        $modulesById = [];
        foreach ($modules as $m) {
            $modulesByCode[$m['code']] = $m;
            $modulesById[(int) $m['id']] = $m;
        }

        $teacherId = (int) $me['id'];
        $logDate = $session['planned_date'] ?: date('Y-m-d');
        $seasonId = (int) $session['season_id'];
        $termId = (int) $session['term_id'];
        $weekId = (int) $session['week_id'];

        $weeklyTotals = [];
        $memSaved = 0;

        Database::transaction(function () use ($rows, $memorizations, $sessionId, $seasonId, $termId, $weekId, $logDate, $teacherId, $modulesByCode, $modulesById, &$weeklyTotals, &$memSaved) {
            $stmt = Database::getPdo()->prepare("
                INSERT INTO `session_scores`
                    (`student_id`, `season_id`, `term_id`, `week_id`, `session_id`, `log_date`, `module_id`, `score`, `entered_by`, `created_at`)
                VALUES
                    (:student_id, :season_id, :term_id, :week_id, :session_id, :log_date, :module_id, :score, :entered_by, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                    `score` = VALUES(`score`),
                    `entered_by` = VALUES(`entered_by`),
                    `log_date` = VALUES(`log_date`)
            ");

            $studentIds = [];

            foreach ($rows as $r) {
                $studentId = (int) ($r['student_id'] ?? 0);
                if (!$studentId) continue;

                $module = null;
                if (!empty($r['module_id']) && isset($modulesById[(int) $r['module_id']])) {
                    $module = $modulesById[(int) $r['module_id']];
                } elseif (!empty($r['module_code']) && isset($modulesByCode[$r['module_code']])) {
                    $module = $modulesByCode[$r['module_code']];
                }

                if (!$module) continue;

                $score = (float) ($r['score'] ?? 0);
                $stmt->execute([
                    'student_id' => $studentId,
                    'season_id' => $seasonId,
                    'term_id' => $termId,
                    'week_id' => $weekId,
                    'session_id' => $sessionId,
                    'log_date' => $logDate,
                    'module_id' => (int) $module['id'],
                    'score' => $score,
                    'entered_by' => $teacherId,
                ]);

                $studentIds[$studentId] = true;
            }

            // Memorization logs
            foreach ($memorizations as $m) {
                $studentId = (int) ($m['student_id'] ?? 0);
                if (!$studentId) continue;

                Database::delete('memorization_logs', 'student_id = :sid AND session_id = :seid', [
                    'sid' => $studentId,
                    'seid' => $sessionId,
                ]);

                $amount = $m['thumn_amount'] ?? null;
                if ($amount !== null && (float) $amount > 0) {
                    Database::insert('memorization_logs', [
                        'student_id' => $studentId,
                        'season_id' => $seasonId,
                        'term_id' => $termId,
                        'week_id' => $weekId,
                        'session_id' => $sessionId,
                        'log_date' => $logDate,
                        'log_mode' => $m['log_mode'] ?? 'thumn',
                        'hizb_no' => $m['hizb_no'] ?? null,
                        'thumn_no' => $m['thumn_no'] ?? null,
                        'thumn_amount' => (float) $amount,
                        'hizb_from' => $m['hizb_from'] ?? null,
                        'hizb_to' => $m['hizb_to'] ?? null,
                        'surah_from' => $m['surah_from'] ?? null,
                        'ayah_from' => $m['ayah_from'] ?? null,
                        'surah_to' => $m['surah_to'] ?? null,
                        'ayah_to' => $m['ayah_to'] ?? null,
                        'created_by' => $teacherId,
                    ]);
                    $memSaved++;
                }
            }

            // Compute weekly totals
            foreach (array_keys($studentIds) as $sid) {
                $total = (float) Database::fetchValue("
                    SELECT ROUND(SUM(sc.score), 1)
                    FROM `session_scores` sc
                    JOIN `scoring_modules` sm ON sm.id = sc.module_id
                    WHERE sc.student_id = :sid AND sc.session_id = :seid
                      AND sm.is_active = 1 AND sm.is_in_weekly_total = 1
                ", ['sid' => $sid, 'seid' => $sessionId]);

                $weeklyTotals[(string) $sid] = $total;
            }
        });

        return $this->created([
            'weekly_totals' => $weeklyTotals,
            'memorizations_saved' => $memSaved,
        ], 'Scores saved.');
    }

    public function bySession(Request $request, array $params): Response
    {
        $session = (int) ($params['session'] ?? $params['id'] ?? 0);
        $rows = Database::fetchAll("
            SELECT sc.*,
                   m.code AS module_code, m.name_ar AS module_name, m.max_points
            FROM `session_scores` sc
            JOIN `scoring_modules` m ON m.id = sc.module_id
            WHERE sc.session_id = :sid
            ORDER BY sc.student_id ASC, m.sort_order ASC
        ", ['sid' => $session]);

        $grouped = [];
        foreach ($rows as $r) {
            $sid = (int) $r['student_id'];
            $grouped[$sid][] = $this->formatSessionScore($r);
        }

        $out = [];
        foreach ($grouped as $sid => $scores) {
            $total = (float) Database::fetchValue("
                SELECT ROUND(SUM(sc.score), 1)
                FROM `session_scores` sc
                JOIN `scoring_modules` sm ON sm.id = sc.module_id
                WHERE sc.student_id = :sid AND sc.session_id = :seid
                  AND sm.is_active = 1 AND sm.is_in_weekly_total = 1
            ", ['sid' => $sid, 'seid' => $session]);

            $out[] = [
                'student_id' => $sid,
                'scores' => $scores,
                'weekly_total' => $total,
            ];
        }

        return $this->ok($out);
    }

    public function followup(Request $request, array $params): Response
    {
        $studentId = (int) ($params['student'] ?? 0);
        $weekId = (int) ($params['week'] ?? 0);

        $student = Database::fetchOne("SELECT id, full_name, memorization_mode FROM `students` WHERE id = :id", ['id' => $studentId]);
        if (!$student) {
            return $this->fail('Student not found.', 404);
        }

        $week = Database::fetchOne("SELECT id, week_number_global, week_type FROM `weeks` WHERE id = :id", ['id' => $weekId]);
        if (!$week) {
            return $this->fail('Week not found.', 404);
        }

        $logs = Database::fetchAll("SELECT * FROM `memorization_logs` WHERE student_id = :sid AND week_id = :wid ORDER BY session_id ASC", [
            'sid' => $studentId, 'wid' => $weekId,
        ]);

        $scoreRows = Database::fetchAll("
            SELECT sc.*, m.code AS module_code, m.name_ar AS module_name, m.max_points
            FROM `session_scores` sc
            JOIN `scoring_modules` m ON m.id = sc.module_id
            WHERE sc.student_id = :sid AND sc.week_id = :wid
            ORDER BY sc.session_id ASC, m.sort_order ASC
        ", ['sid' => $studentId, 'wid' => $weekId]);

        $scoresBySession = [];
        foreach ($scoreRows as $sr) {
            $scoresBySession[(int) $sr['session_id']][] = $this->formatSessionScore($sr);
        }

        $revision = Database::fetchAll("SELECT * FROM `revision_logs` WHERE student_id = :sid AND week_id = :wid ORDER BY session_id ASC", [
            'sid' => $studentId, 'wid' => $weekId,
        ]);

        $attendance = Database::fetchAll("SELECT * FROM `attendance` WHERE student_id = :sid AND week_id = :wid ORDER BY session_id ASC", [
            'sid' => $studentId, 'wid' => $weekId,
        ]);

        $goal = Database::fetchOne("SELECT * FROM `weekly_goals` WHERE student_id = :sid AND week_id = :wid LIMIT 1", [
            'sid' => $studentId, 'wid' => $weekId,
        ]);

        return $this->ok([
            'student' => [
                'id' => (int) $student['id'],
                'full_name' => $student['full_name'],
                'memorization_mode' => $student['memorization_mode'],
            ],
            'week' => [
                'id' => (int) $week['id'],
                'week_number_global' => (int) $week['week_number_global'],
                'week_type' => $week['week_type'],
            ],
            'logs' => $logs,
            'scores_by_session' => $scoresBySession,
            'revision' => $revision,
            'attendance' => $attendance,
            'goal' => $goal ? [
                'id' => (int) $goal['id'],
                'student_id' => (int) $goal['student_id'],
                'season_id' => (int) $goal['season_id'],
                'week_id' => (int) $goal['week_id'],
                'target_text' => $goal['target_text'],
                'is_completed' => $goal['is_completed'] !== null ? (bool) $goal['is_completed'] : null,
                'checked_by' => $goal['checked_by'] !== null ? (int) $goal['checked_by'] : null,
                'checked_at' => $goal['checked_at'] ?? null,
            ] : null,
        ]);
    }
}
