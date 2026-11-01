<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class AttendanceController extends BaseController
{
    private function formatAttendance(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'student_name' => $row['student_name'] ?? null,
            'season_id' => (int) $row['season_id'],
            'term_id' => (int) $row['term_id'],
            'week_id' => (int) $row['week_id'],
            'session_id' => (int) $row['session_id'],
            'status' => $row['status'],
            'marked_by' => $row['marked_by'] !== null ? (int) $row['marked_by'] : null,
            'marked_at' => $row['marked_at'] ?? null,
            'notes' => $row['notes'] ?? null,
            'student' => !empty($row['student_id']) ? [
                'id' => (int) $row['student_id'],
                'full_name' => $row['student_name'] ?? '',
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

        foreach (['student_id', 'week_id', 'session_id', 'term_id', 'season_id', 'status'] as $f) {
            if ($request->query($f) !== null && $request->query($f) !== '') {
                $conditions[] = "a.{$f} = :{$f}";
                $params[$f] = $request->query($f);
            }
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT a.*, s.full_name AS student_name
            FROM `attendance` a
            JOIN `students` s ON s.id = a.student_id
            {$whereClause}
            ORDER BY a.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatAttendance'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function bulk(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);

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

        $records = (array) $request->input('records');
        if (empty($records)) {
            return $this->fail('Records cannot be empty.', 422);
        }

        $me = $request->getUser();
        $markedBy = (int) $me['id'];
        $seasonId = (int) $session['season_id'];
        $termId = (int) $session['term_id'];
        $weekId = (int) $session['week_id'];

        $attendanceIds = [];

        Database::transaction(function () use ($records, $sessionId, $seasonId, $termId, $weekId, $markedBy, &$attendanceIds) {
            $stmt = Database::getPdo()->prepare("
                INSERT INTO `attendance`
                    (`student_id`, `season_id`, `term_id`, `week_id`, `session_id`, `status`, `marked_by`, `marked_at`, `notes`)
                VALUES
                    (:student_id, :season_id, :term_id, :week_id, :session_id, :status, :marked_by, CURRENT_TIMESTAMP, :notes)
                ON DUPLICATE KEY UPDATE
                    `status` = VALUES(`status`),
                    `marked_by` = VALUES(`marked_by`),
                    `marked_at` = CURRENT_TIMESTAMP,
                    `notes` = VALUES(`notes`)
            ");

            foreach ($records as $r) {
                if (empty($r['student_id'])) {
                    continue;
                }
                $studentId = (int) $r['student_id'];
                $status = in_array($r['status'] ?? '', ['present', 'absent', 'late', 'excused'], true) ? $r['status'] : 'present';
                $notes = $r['notes'] ?? null;

                $stmt->execute([
                    'student_id' => $studentId,
                    'season_id' => $seasonId,
                    'term_id' => $termId,
                    'week_id' => $weekId,
                    'session_id' => $sessionId,
                    'status' => $status,
                    'marked_by' => $markedBy,
                    'notes' => $notes,
                ]);

                $attId = Database::fetchValue("
                    SELECT id FROM `attendance` WHERE student_id = :sid AND session_id = :seid
                ", ['sid' => $studentId, 'seid' => $sessionId]);
                if ($attId) {
                    $attendanceIds[] = (int) $attId;
                }
            }
        });

        return $this->created(['attendance_ids' => $attendanceIds], 'Attendance saved.');
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['attendance'] ?? 0);
        $row = Database::fetchOne("
            SELECT a.*, s.full_name AS student_name
            FROM `attendance` a
            JOIN `students` s ON s.id = a.student_id
            WHERE a.id = :id
        ", ['id' => $id]);

        if (!$row) {
            return $this->fail('Attendance record not found.', 404);
        }

        return $this->ok($this->formatAttendance($row));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['attendance'] ?? 0);

        Database::delete('attendance', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }
}
