<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\SessionGenerator;
use App\Core\Validator;

final class GroupController extends BaseController
{
    private function formatGroup(array $row): array
    {
        $teacher = null;
        if (!empty($row['teacher_id']) || !empty($row['teacher_name'])) {
            $teacher = [
                'id' => (int) $row['teacher_id'],
                'full_name' => $row['teacher_name'] ?? '',
                'phone' => $row['teacher_phone'] ?? null,
                'teacher_type' => $row['teacher_type'] ?? null,
            ];
        }

        $capacity = isset($row['capacity']) && $row['capacity'] !== null ? (int) $row['capacity'] : null;
        $studentsCount = isset($row['students_count']) ? (int) $row['students_count'] : 0;
        $fillPct = $capacity && $capacity > 0 ? round(100 * $studentsCount / $capacity, 1) : null;
        $murajaa = Database::fetchOne(
            'SELECT u.id, u.full_name FROM `group_teachers` gt JOIN `users` u ON u.id = gt.user_id
             WHERE gt.group_id = :gid AND gt.role = :role LIMIT 1',
            ['gid' => (int) $row['id'], 'role' => 'murajaa'],
        );

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'center_id' => (int) $row['center_id'],
            'academic_year' => $row['academic_year'] ?? null,
            'capacity' => $capacity,
            'schedule_days' => $row['schedule_days'] ?? '',
            'is_active' => (bool) $row['is_active'],
            'teacher' => $teacher,
            'murajaa_teacher_id' => $murajaa ? (int) $murajaa['id'] : null,
            'murajaa_teacher_name' => $murajaa['full_name'] ?? null,
            'students_count' => $studentsCount,
            'fill_pct' => $fillPct,
        ];
    }

    public function index(Request $request): Response
    {
        $me = $request->getUser();
        $role = $me['role'] ?? 'teacher';

        $conditions = [];
        $params = [];

        if ($role === 'student') {
            $conditions[] = "g.id IN (SELECT group_id FROM `students` WHERE user_id = :uid AND group_id IS NOT NULL)";
            $params['uid'] = (int) $me['id'];
        } elseif ($role !== 'admin' && $role !== 'board') {
            $conditions[] = "g.center_id = :cid";
            $params['cid'] = (int) $me['center_id'];
        } elseif ($request->query('center_id')) {
            $conditions[] = "g.center_id = :cid";
            $params['cid'] = (int) $request->query('center_id');
        }

        if ($request->query('is_active') !== null && $request->query('is_active') !== '') {
            $conditions[] = "g.is_active = :is_act";
            $params['is_act'] = (int) filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT g.*,
                   u.full_name AS teacher_name, u.phone AS teacher_phone, u.teacher_type,
                   (SELECT COUNT(*) FROM `students` s WHERE s.group_id = g.id) AS students_count
            FROM `groups` g
            LEFT JOIN `users` u ON u.id = g.teacher_id
            {$whereClause}
            ORDER BY g.id ASC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatGroup'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function stats(Request $request): Response
    {
        $me = $request->getUser();
        $role = $me['role'] ?? 'teacher';

        $centerId = null;
        if ($role !== 'admin' && $role !== 'board') {
            $centerId = (int) $me['center_id'];
        } elseif ($request->query('center_id')) {
            $centerId = (int) $request->query('center_id');
        }

        $season = null;
        if ($centerId) {
            $season = Database::fetchOne("SELECT id, name FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $centerId])
                ?: Database::fetchOne("SELECT id, name FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
        } else {
            $season = Database::fetchOne("SELECT id, name FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
        }

        $seasonId = $season ? (int) $season['id'] : null;

        $conditions = [];
        $params = [];
        if ($centerId) {
            $conditions[] = "g.center_id = :cid";
            $params['cid'] = $centerId;
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $groups = Database::fetchAll("
            SELECT g.*,
                   u.full_name AS teacher_name, u.phone AS teacher_phone, u.teacher_type,
                   (SELECT COUNT(*) FROM `students` s WHERE s.group_id = g.id) AS students_count
            FROM `groups` g
            LEFT JOIN `users` u ON u.id = g.teacher_id
            {$whereClause}
            ORDER BY g.id ASC
        ", $params);

        $groupList = [];
        $totalStudents = 0;
        $totalCapacity = 0;

        foreach ($groups as $g) {
            $gid = (int) $g['id'];
            $stCount = (int) $g['students_count'];
            $cap = $g['capacity'] ? (int) $g['capacity'] : null;
            $totalStudents += $stCount;
            if ($cap) $totalCapacity += $cap;

            $avgScore = $seasonId ? (float) Database::fetchValue("
                SELECT ROUND(AVG(t.total_score), 2)
                FROM v_session_totals t
                JOIN `students` st ON st.id = t.student_id
                WHERE st.group_id = :gid AND t.season_id = :sid
            ", ['gid' => $gid, 'sid' => $seasonId]) : null;

            $attPct = $seasonId ? (float) Database::fetchValue("
                SELECT ROUND(100 * SUM(a.status IN ('present', 'late')) / COUNT(*), 1)
                FROM `attendance` a
                JOIN `students` st ON st.id = a.student_id
                WHERE st.group_id = :gid AND a.season_id = :sid
            ", ['gid' => $gid, 'sid' => $seasonId]) : null;

            $thumnTotal = $seasonId ? (float) Database::fetchValue("
                SELECT COALESCE(SUM(m.thumn_amount), 0)
                FROM `memorization_logs` m
                JOIN `students` st ON st.id = m.student_id
                WHERE st.group_id = :gid AND m.season_id = :sid
            ", ['gid' => $gid, 'sid' => $seasonId]) : 0.0;

            $groupList[] = array_merge($this->formatGroup($g), [
                'avg_score' => $avgScore ?: null,
                'attendance_pct' => $attPct ?: null,
                'thumn_total' => $thumnTotal,
            ]);
        }

        $overallAvg = $seasonId ? (float) Database::fetchValue("
            SELECT ROUND(AVG(t.total_score), 2)
            FROM v_session_totals t
            " . ($centerId ? "JOIN `students` st ON st.id = t.student_id WHERE st.center_id = {$centerId} AND t.season_id = {$seasonId}" : "WHERE t.season_id = {$seasonId}")
        ) : null;

        $overallAtt = $seasonId ? (float) Database::fetchValue("
            SELECT ROUND(100 * SUM(a.status IN ('present', 'late')) / COUNT(*), 1)
            FROM `attendance` a
            " . ($centerId ? "JOIN `students` st ON st.id = a.student_id WHERE st.center_id = {$centerId} AND a.season_id = {$seasonId}" : "WHERE a.season_id = {$seasonId}")
        ) : null;

        $kpis = [
            'groups' => count($groups),
            'students' => $totalStudents,
            'avg_score' => $overallAvg ?: null,
            'attendance_pct' => $overallAtt ?: null,
            'fill_pct' => $totalCapacity > 0 ? round(100 * $totalStudents / $totalCapacity, 1) : null,
        ];

        return $this->ok([
            'season' => $season ? ['id' => (int) $season['id']] : null,
            'season_name' => $season['name'] ?? null,
            'kpis' => $kpis,
            'breakdown' => [
                'status' => ['active' => $totalStudents],
                'gender' => ['male' => 0, 'female' => 0],
                'student_type' => ['child' => 0, 'adult' => 0],
                'memorization_mode' => ['thumn' => 0, 'surah' => 0],
            ],
            'groups' => $groupList,
        ]);
    }

    public function detail(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['group'] ?? 0);
        $group = Database::fetchOne("
            SELECT g.*,
                   u.full_name AS teacher_name, u.phone AS teacher_phone, u.teacher_type,
                   (SELECT COUNT(*) FROM `students` s WHERE s.group_id = g.id) AS students_count
            FROM `groups` g
            LEFT JOIN `users` u ON u.id = g.teacher_id
            WHERE g.id = :id
        ", ['id' => $id]);

        if (!$group) {
            return $this->fail('Group not found.', 404);
        }

        $centerId = (int) $group['center_id'];
        $season = Database::fetchOne("SELECT id, name FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $centerId])
            ?: Database::fetchOne("SELECT id, name FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
        $seasonId = $season ? (int) $season['id'] : null;

        $students = Database::fetchAll("
            SELECT s.*,
                   (SELECT ROUND(AVG(t.total_score), 2) FROM v_session_totals t WHERE t.student_id = s.id AND t.season_id = :sid) AS avg_score,
                   (SELECT ROUND(100 * SUM(a.status IN ('present', 'late')) / COUNT(*), 1) FROM `attendance` a WHERE a.student_id = s.id AND a.season_id = :sid2) AS attendance_pct,
                   (SELECT COALESCE(SUM(m.thumn_amount), 0) FROM `memorization_logs` m WHERE m.student_id = s.id AND m.season_id = :sid3) AS thumn_total
            FROM `students` s
            WHERE s.group_id = :gid
            ORDER BY s.id ASC
        ", [
            'gid' => $id,
            'sid' => $seasonId,
            'sid2' => $seasonId,
            'sid3' => $seasonId,
        ]);

        $trend = [];
        if ($seasonId) {
            $trend = Database::fetchAll("
                SELECT w.week_number_global AS week, ROUND(AVG(t.total_score), 2) AS avg_score
                FROM v_session_totals t
                JOIN `students` st ON st.id = t.student_id
                JOIN `weeks` w ON w.id = t.week_id
                WHERE st.group_id = :gid AND t.season_id = :sid
                GROUP BY w.week_number_global
                ORDER BY w.week_number_global ASC
            ", ['gid' => $id, 'sid' => $seasonId]);
        }

        return $this->ok([
            'season_name' => $season['name'] ?? null,
            'group' => $this->formatGroup($group),
            'trend' => $trend,
            'students' => array_map(function ($s) {
                return [
                    'id' => (int) $s['id'],
                    'full_name' => $s['full_name'],
                    'gender' => $s['gender'] ?? null,
                    'status' => $s['status'],
                    'student_type' => $s['student_type'] ?? null,
                    'memorization_mode' => $s['memorization_mode'],
                    'start_hizb' => $s['start_hizb'] ? (float) $s['start_hizb'] : null,
                    'birth_date' => $s['birth_date'] ?? null,
                    'enrollment_date' => $s['enrollment_date'] ?? null,
                    'level_id' => $s['level_id'] ? (int) $s['level_id'] : null,
                    'notes' => $s['notes'] ?? null,
                    'avg_score' => $s['avg_score'] ? (float) $s['avg_score'] : null,
                    'attendance_pct' => $s['attendance_pct'] ? (float) $s['attendance_pct'] : null,
                    'thumn_total' => (float) ($s['thumn_total'] ?? 0),
                ];
            }, $students),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);

        $v = Validator::make($request->all(), [
            'name' => 'required|string',
            'center_id' => 'nullable|integer',
            'teacher_id' => 'nullable|integer',
            'murajaa_teacher_id' => 'nullable|integer',
            'academic_year' => 'nullable|string',
            'capacity' => 'nullable|integer',
            'schedule_days' => 'nullable',
            'is_active' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $me = $request->getUser();
        $centerId = $me['role'] === 'admin'
            ? ($request->input('center_id') ? (int) $request->input('center_id') : (int) $me['center_id'])
            : (int) $me['center_id'];

        $teacherId = $request->input('teacher_id') ? (int) $request->input('teacher_id') : null;
        $teacherType = 'both';
        if ($teacherId) {
            $teacher = Database::fetchOne("SELECT id, role, center_id, teacher_type FROM `users` WHERE id = :id", ['id' => $teacherId]);
            if (!$teacher || $teacher['role'] !== 'teacher' || (int) $teacher['center_id'] !== $centerId) {
                return $this->fail('Teacher must belong to the same center.', 422);
            }
            $teacherType = in_array($teacher['teacher_type'], ['hifz', 'murajaa', 'both'], true)
                ? (string) $teacher['teacher_type']
                : 'both';
        }

        $murajaaTeacherId = $request->input('murajaa_teacher_id') ? (int) $request->input('murajaa_teacher_id') : null;
        if ($murajaaTeacherId) {
            $murajaaTeacher = Database::fetchOne("SELECT id, role, center_id FROM `users` WHERE id = :id", ['id' => $murajaaTeacherId]);
            if (!$murajaaTeacher || $murajaaTeacher['role'] !== 'teacher' || (int) $murajaaTeacher['center_id'] !== $centerId) {
                return $this->fail('Murajaa teacher must belong to the same center.', 422);
            }
        }

        $days = $request->input('schedule_days');
        $scheduleStr = is_array($days) ? implode(',', $days) : (string) ($days ?? 'Mon,Wed,Fri');

        $newId = Database::transaction(function () use ($request, $centerId, $teacherId, $teacherType, $murajaaTeacherId, $scheduleStr, $days) {
            $gid = Database::insert('groups', [
                'center_id' => $centerId,
                'teacher_id' => $teacherId,
                'name' => trim((string) $request->input('name')),
                'academic_year' => $request->input('academic_year'),
                'capacity' => $request->input('capacity') ? (int) $request->input('capacity') : 20,
                'schedule_days' => $scheduleStr,
                'is_active' => $request->input('is_active') !== null ? (int) filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN) : 1,
            ]);

            if ($teacherId) {
                $this->syncGroupTeacher($gid, $teacherId, $teacherType);
            }
            if ($murajaaTeacherId) {
                $this->syncGroupTeacher(
                    $gid,
                    $murajaaTeacherId,
                    $murajaaTeacherId === $teacherId ? 'both' : 'murajaa',
                );
            }

            if (is_array($days)) {
                foreach (array_unique($days) as $d) {
                    Database::insert('group_weekdays', [
                        'group_id' => $gid,
                        'weekday' => $d,
                    ]);
                }
            }

            return $gid;
        });

        $this->generateGroupSessions($newId, $centerId);

        $created = Database::fetchOne("
            SELECT g.*,
                   u.full_name AS teacher_name, u.phone AS teacher_phone, u.teacher_type,
                   0 AS students_count
            FROM `groups` g
            LEFT JOIN `users` u ON u.id = g.teacher_id
            WHERE g.id = :id
        ", ['id' => $newId]);

        return $this->created($this->formatGroup($created));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['group'] ?? 0);
        $group = Database::fetchOne("
            SELECT g.*,
                   u.full_name AS teacher_name, u.phone AS teacher_phone, u.teacher_type,
                   (SELECT COUNT(*) FROM `students` s WHERE s.group_id = g.id) AS students_count
            FROM `groups` g
            LEFT JOIN `users` u ON u.id = g.teacher_id
            WHERE g.id = :id
        ", ['id' => $id]);

        if (!$group) {
            return $this->fail('Group not found.', 404);
        }

        return $this->ok($this->formatGroup($group));
    }

    public function update(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['group'] ?? 0);

        $group = Database::fetchOne("SELECT * FROM `groups` WHERE id = :id", ['id' => $id]);
        if (!$group) {
            return $this->fail('Group not found.', 404);
        }

        $input = $request->all();
        $update = [];

        if (isset($input['name'])) {
            $update['name'] = trim((string) $input['name']);
        }
        if (isset($input['academic_year'])) {
            $update['academic_year'] = $input['academic_year'];
        }
        if (isset($input['capacity'])) {
            $update['capacity'] = (int) $input['capacity'];
        }
        if (isset($input['is_active'])) {
            $update['is_active'] = (int) filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        $primaryTeacherType = 'both';
        if (array_key_exists('teacher_id', $input)) {
            $tid = $input['teacher_id'] ? (int) $input['teacher_id'] : null;
            if ($tid) {
                $teacher = Database::fetchOne("SELECT id, role, center_id, teacher_type FROM `users` WHERE id = :id", ['id' => $tid]);
                if (!$teacher || $teacher['role'] !== 'teacher' || (int) $teacher['center_id'] !== (int) $group['center_id']) {
                    return $this->fail('Teacher must belong to the same center.', 422);
                }
                $primaryTeacherType = in_array($teacher['teacher_type'], ['hifz', 'murajaa', 'both'], true)
                    ? (string) $teacher['teacher_type']
                    : 'both';
            }
            $update['teacher_id'] = $tid;
        }

        $murajaaKeyPresent = array_key_exists('murajaa_teacher_id', $input);
        $murajaaTeacherId = $input['murajaa_teacher_id'] ? (int) $input['murajaa_teacher_id'] : null;
        if ($murajaaTeacherId) {
            $murajaaTeacher = Database::fetchOne("SELECT id, role, center_id FROM `users` WHERE id = :id", ['id' => $murajaaTeacherId]);
            if (!$murajaaTeacher || $murajaaTeacher['role'] !== 'teacher' || (int) $murajaaTeacher['center_id'] !== (int) $group['center_id']) {
                return $this->fail('Murajaa teacher must belong to the same center.', 422);
            }
        }

        $days = $request->input('schedule_days');
        if ($days !== null) {
            $update['schedule_days'] = is_array($days) ? implode(',', $days) : (string) $days;
        }

        $oldPrimaryId = (int) ($group['teacher_id'] ?? 0);
        Database::transaction(function () use ($id, $update, $days, $primaryTeacherType, $murajaaKeyPresent, $murajaaTeacherId, $oldPrimaryId) {
            if (!empty($update)) {
                Database::update('groups', $update, 'id = :id', ['id' => $id]);
            }
            $primaryId = isset($update['teacher_id'])
                ? (int) $update['teacher_id']
                : (int) Database::fetchValue('SELECT teacher_id FROM `groups` WHERE id = :id', ['id' => $id]);
            if (isset($update['teacher_id']) && $update['teacher_id']) {
                if ($oldPrimaryId > 0 && $oldPrimaryId !== $primaryId) {
                    Database::delete(
                        'group_teachers',
                        'group_id = :gid AND user_id = :old',
                        ['gid' => $id, 'old' => $oldPrimaryId],
                    );
                }
                $this->syncGroupTeacher($id, $primaryId, $primaryTeacherType);
            }
            if ($murajaaKeyPresent) {
                if ($murajaaTeacherId) {
                    Database::delete(
                        'group_teachers',
                        'group_id = :gid AND role = :role AND user_id <> :primary AND user_id <> :murajaa',
                        ['gid' => $id, 'role' => 'murajaa', 'primary' => $primaryId, 'murajaa' => $murajaaTeacherId],
                    );
                    $this->syncGroupTeacher(
                        $id,
                        $murajaaTeacherId,
                        $murajaaTeacherId === $primaryId ? 'both' : 'murajaa',
                    );
                } else {
                    Database::delete(
                        'group_teachers',
                        'group_id = :gid AND role = :role AND user_id <> :primary',
                        ['gid' => $id, 'role' => 'murajaa', 'primary' => $primaryId],
                    );
                }
            }
            if (is_array($days)) {
                Database::delete('group_weekdays', 'group_id = :gid', ['gid' => $id]);
                foreach (array_unique($days) as $d) {
                    Database::insert('group_weekdays', [
                        'group_id' => $id,
                        'weekday' => $d,
                    ]);
                }
            }
        });

        if (is_array($days)) {
            $this->generateGroupSessions($id, (int) $group['center_id']);
        }

        $fresh = Database::fetchOne("
            SELECT g.*,
                   u.full_name AS teacher_name, u.phone AS teacher_phone, u.teacher_type,
                   (SELECT COUNT(*) FROM `students` s WHERE s.group_id = g.id) AS students_count
            FROM `groups` g
            LEFT JOIN `users` u ON u.id = g.teacher_id
            WHERE g.id = :id
        ", ['id' => $id]);

        return $this->ok($this->formatGroup($fresh));
    }

    public function destroy(Request $request, array $params): Response
    {
        return $this->fail('Groups cannot be deleted.', 422);
    }

    private function generateGroupSessions(int $groupId, int $centerId): void
    {
        $seasonId = (int) Database::fetchValue(
            'SELECT id FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 ORDER BY id DESC LIMIT 1',
            ['cid' => $centerId],
        );
        if ($seasonId > 0) {
            SessionGenerator::forGroup($groupId, $seasonId);
        }
    }

    private function syncGroupTeacher(int $groupId, int $userId, string $role): void
    {
        Database::getPdo()->prepare(
            'INSERT INTO `group_teachers` (`group_id`, `user_id`, `role`)
             VALUES (:gid, :uid, :role)
             ON DUPLICATE KEY UPDATE
                `role` = CASE WHEN `role` = :existing_both OR VALUES(`role`) = :new_both
                              THEN :both_value ELSE VALUES(`role`) END',
        )->execute([
            'gid' => $groupId,
            'uid' => $userId,
            'role' => $role,
            'existing_both' => 'both',
            'new_both' => 'both',
            'both_value' => 'both',
        ]);
    }
}
