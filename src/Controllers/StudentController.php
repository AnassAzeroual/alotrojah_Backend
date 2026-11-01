<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Validator;

final class StudentController extends BaseController
{
    private function formatStudent(array $row): array
    {
        $group = null;
        if (!empty($row['group_id'])) {
            $group = [
                'id' => (int) $row['group_id'],
                'name' => $row['group_name'] ?? '',
            ];
        }

        $user = null;
        if (!empty($row['user_id'])) {
            $user = [
                'id' => (int) $row['user_id'],
                'full_name' => $row['user_name'] ?? '',
                'email' => $row['user_email'] ?? '',
            ];
        }

        $level = null;
        if (!empty($row['level_id']) || !empty($row['level_code'])) {
            $level = [
                'id' => (int) $row['level_id'],
                'code' => $row['level_code'] ?? '',
                'name_ar' => $row['level_name_ar'] ?? '',
            ];
        }

        return [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'],
            'center_id' => $row['center_id'] !== null ? (int) $row['center_id'] : null,
            'group' => $group,
            'user' => $user,
            'level' => $level,
            'level_id' => $row['level_id'] !== null ? (int) $row['level_id'] : null,
            'birth_date' => $row['birth_date'] ?? null,
            'enrollment_date' => $row['enrollment_date'] ?? null,
            'notes' => $row['notes'] ?? null,
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'gender' => $row['gender'] ?? null,
            'status' => $row['status'] ?? 'active',
            'student_type' => $row['student_type'] ?? null,
            'memorization_mode' => $row['memorization_mode'] ?? 'thumn',
            'start_hizb' => $row['start_hizb'] !== null ? (float) $row['start_hizb'] : null,
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

        if ($request->query('group_id')) {
            $conditions[] = "s.group_id = :gid";
            $params['gid'] = (int) $request->query('group_id');
        }

        if ($request->query('level_id')) {
            $lvlCode = Database::fetchValue("SELECT code FROM `levels` WHERE id = :lid", ['lid' => (int) $request->query('level_id')]);
            if ($lvlCode) {
                $conditions[] = "l.code = :lcode";
                $params['lcode'] = $lvlCode;
            } else {
                $conditions[] = "1 = 0";
            }
        }

        if ($request->query('status')) {
            $conditions[] = "s.status = :status";
            $params['status'] = $request->query('status');
        }

        if ($request->query('memorization_mode')) {
            $conditions[] = "s.memorization_mode = :mm";
            $params['mm'] = $request->query('memorization_mode');
        }

        if ($request->query('q')) {
            $conditions[] = "s.full_name LIKE :q";
            $params['q'] = '%' . $request->query('q') . '%';
        }

        if ($request->query('unassigned')) {
            $conditions[] = "s.group_id IS NULL";
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT s.*,
                   g.name AS group_name,
                   u.full_name AS user_name, u.email AS user_email,
                   l.code AS level_code, l.name_ar AS level_name_ar
            FROM `students` s
            LEFT JOIN `groups` g ON g.id = s.group_id
            LEFT JOIN `users` u ON u.id = s.user_id
            LEFT JOIN `levels` l ON l.id = s.level_id
            {$whereClause}
            ORDER BY s.id ASC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatStudent'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function unlinkedAccounts(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        $conditions = [
            "u.role = 'student'",
            "u.is_active = 1",
            "NOT EXISTS (SELECT 1 FROM `students` s WHERE s.user_id = u.id)",
        ];
        $params = [];

        if ($me['role'] !== 'admin') {
            $conditions[] = "u.center_id = :cid";
            $params['cid'] = (int) $me['center_id'];
        } elseif ($request->query('center_id')) {
            $conditions[] = "u.center_id = :cid";
            $params['cid'] = (int) $request->query('center_id');
        }

        $whereClause = 'WHERE ' . implode(' AND ', $conditions);
        $users = Database::fetchAll("
            SELECT u.id, u.full_name, u.email, u.center_id
            FROM `users` u
            {$whereClause}
            ORDER BY u.full_name ASC
        ", $params);

        return $this->ok($users);
    }

    public function examBrief(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['student'] ?? 0);
        $student = Database::fetchOne("
            SELECT s.*,
                   g.name AS group_name,
                   u.full_name AS user_name, u.email AS user_email,
                   l.code AS level_code, l.name_ar AS level_name_ar
            FROM `students` s
            LEFT JOIN `groups` g ON g.id = s.group_id
            LEFT JOIN `users` u ON u.id = s.user_id
            LEFT JOIN `levels` l ON l.id = s.level_id
            WHERE s.id = :id
        ", ['id' => $id]);

        if (!$student) {
            return $this->fail('Student not found.', 404);
        }

        $centerId = $student['center_id'] !== null ? (int) $student['center_id'] : null;
        $season = null;
        if ($centerId) {
            $season = Database::fetchOne("SELECT id, name FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $centerId])
                ?: Database::fetchOne("SELECT id, name FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
        } else {
            $season = Database::fetchOne("SELECT id, name FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
        }
        $seasonId = $season ? (int) $season['id'] : null;

        $summary = null;
        if ($seasonId) {
            $summary = Database::fetchOne("
                SELECT * FROM `v_season_dashboard`
                WHERE student_id = :sid AND season_id = :seid
            ", ['sid' => $id, 'seid' => $seasonId]);
        }

        $logs = Database::fetchAll("
            SELECT * FROM `memorization_logs`
            WHERE student_id = :sid
            ORDER BY log_date DESC, id DESC
        ", ['sid' => $id]);

        $items = array_map(function ($log) {
            return [
                'id' => (int) $log['id'],
                'log_date' => $log['log_date'],
                'log_mode' => $log['log_mode'],
                'thumn_amount' => (float) $log['thumn_amount'],
                'hizb_no' => $log['hizb_no'] !== null ? (float) $log['hizb_no'] : null,
                'thumn_no' => $log['thumn_no'] !== null ? (int) $log['thumn_no'] : null,
                'surah_ref' => $log['surah_from'] !== null ? (int) $log['surah_from'] : null,
                'ayah_from' => $log['ayah_from'] !== null ? (int) $log['ayah_from'] : null,
                'ayah_to' => $log['ayah_to'] !== null ? (int) $log['ayah_to'] : null,
            ];
        }, $logs);

        $last = $items[0] ?? null;

        $exams = Database::fetchAll("
            SELECT e.* FROM `exams` e
            WHERE e.student_id = :sid
            ORDER BY e.exam_date DESC, e.id DESC
            LIMIT 10
        ", ['sid' => $id]);

        $mode = $student['memorization_mode'] ?? 'thumn';
        $current = [
            'mode' => $mode,
            'hizb' => null,
            'thumn' => null,
            'surah' => null,
            'ayah_from' => null,
            'ayah_to' => null,
            'source' => null,
        ];

        if (!empty($exams)) {
            $latestExamId = (int) $exams[0]['id'];
            $questions = Database::fetchAll("
                SELECT * FROM `exam_questions`
                WHERE exam_id = :eid
                ORDER BY sort_order ASC, question_no ASC
            ", ['eid' => $latestExamId]);

            foreach ($questions as $q) {
                if ($mode === 'thumn' && $q['hizb_ref'] !== null) {
                    $current = [
                        'mode' => 'thumn',
                        'hizb' => (float) $q['hizb_ref'],
                        'thumn' => $q['thumn_ref'] !== null ? (int) $q['thumn_ref'] : null,
                        'surah' => null,
                        'ayah_from' => null,
                        'ayah_to' => null,
                        'source' => 'last_exam',
                    ];
                    break;
                }
                if ($mode === 'surah' && $q['surah_ref'] !== null) {
                    $current = [
                        'mode' => 'surah',
                        'hizb' => null,
                        'thumn' => null,
                        'surah' => (int) $q['surah_ref'],
                        'ayah_from' => $q['ayah_from'] !== null ? (int) $q['ayah_from'] : null,
                        'ayah_to' => $q['ayah_to'] !== null ? (int) $q['ayah_to'] : null,
                        'source' => 'last_exam',
                    ];
                    break;
                }
            }
        }

        if ($current['source'] === null && $last !== null) {
            if ($mode === 'thumn' && $last['hizb_no'] !== null) {
                $current = [
                    'mode' => 'thumn',
                    'hizb' => $last['hizb_no'],
                    'thumn' => $last['thumn_no'],
                    'surah' => null,
                    'ayah_from' => null,
                    'ayah_to' => null,
                    'source' => 'last_log',
                ];
            } elseif ($mode === 'surah' && $last['surah_ref'] !== null) {
                $current = [
                    'mode' => 'surah',
                    'hizb' => null,
                    'thumn' => null,
                    'surah' => $last['surah_ref'],
                    'ayah_from' => $last['ayah_from'],
                    'ayah_to' => $last['ayah_to'],
                    'source' => 'last_log',
                ];
            }
        }

        $totalThumn = array_reduce($items, fn($carry, $item) => $carry + $item['thumn_amount'], 0.0);

        return $this->ok([
            'student' => $this->formatStudent($student),
            'group' => !empty($student['group_id']) ? ['id' => (int) $student['group_id'], 'name' => $student['group_name']] : null,
            'season' => $season ? ['id' => (int) $season['id'], 'name' => $season['name']] : null,
            'summary' => $summary ? [
                'season_thumn' => (float) ($summary['season_thumn'] ?? 0),
                'season_avg_score' => $summary['season_avg_score'] !== null ? (float) $summary['season_avg_score'] : null,
                'season_avg_sarraj' => $summary['season_avg_sarraj'] !== null ? (float) $summary['season_avg_sarraj'] : null,
                'season_attendance_pct' => $summary['season_attendance_pct'] !== null ? (float) $summary['season_attendance_pct'] : null,
                'season_overall_avg' => $summary['season_overall_avg'] !== null ? (float) $summary['season_overall_avg'] : null,
                'honor_flag' => $summary['honor_flag'] ?? 'none',
            ] : null,
            'memorization' => [
                'last' => $last,
                'items' => $items,
                'total_thumn' => $totalThumn,
            ],
            'exams' => $exams,
            'current' => $current,
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);

        $v = Validator::make($request->all(), [
            'full_name' => 'required|string',
            'center_id' => 'nullable|integer',
            'group_id' => 'nullable|integer',
            'level_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'enrollment_date' => 'nullable|date',
            'status' => 'nullable|in:active,paused,graduated,left',
            'student_type' => 'nullable|in:child,adult',
            'memorization_mode' => 'nullable|in:thumn,surah',
            'start_hizb' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $me = $request->getUser();
        $centerId = $me['role'] === 'admin'
            ? ($request->input('center_id') ? (int) $request->input('center_id') : null)
            : (int) $me['center_id'];

        if ($me['role'] === 'admin' && empty($centerId)) {
            $count = (int) Database::fetchValue("SELECT COUNT(*) FROM `centers`");
            if ($count === 1) {
                $centerId = (int) Database::fetchValue("SELECT id FROM `centers` LIMIT 1");
            }
        }

        $newAccount = $request->input('account');
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;

        if ($newAccount !== null) {
            if (empty($centerId)) {
                return $this->fail('Choose a center for the pupil account.', 422);
            }
            if (empty($newAccount['email']) || empty($newAccount['password'])) {
                return $this->fail('Email and password required for account.', 422);
            }
            if (Database::fetchValue("SELECT 1 FROM `users` WHERE email = :email", ['email' => $newAccount['email']])) {
                return $this->fail('Email is already registered.', 422, ['email' => ['The email has already been taken.']]);
            }
        } elseif ($userId !== null) {
            $linked = Database::fetchOne("SELECT id, center_id FROM `users` WHERE id = :id", ['id' => $userId]);
            if (!$linked) {
                return $this->fail('User account not found.', 404);
            }
            if (!empty($centerId) && $linked['center_id'] !== null && (int) $linked['center_id'] !== $centerId) {
                return $this->fail('Pupil account belongs to another center.', 422);
            }
        }

        $groupId = $request->input('group_id') ? (int) $request->input('group_id') : null;
        if ($groupId && !empty($centerId)) {
            $grp = Database::fetchOne("SELECT id, center_id FROM `groups` WHERE id = :id", ['id' => $groupId]);
            if ($grp && (int) $grp['center_id'] !== $centerId) {
                return $this->fail('Group belongs to another center.', 422);
            }
        }

        $levelId = $request->input('level_id') ? (int) $request->input('level_id') : null;

        $newStudentId = Database::transaction(function () use ($request, $centerId, $groupId, $levelId, $userId, $newAccount) {
            if ($newAccount !== null) {
                $userId = Database::insert('users', [
                    'full_name' => trim((string) $request->input('full_name')),
                    'email' => trim((string) $newAccount['email']),
                    'password_hash' => Security::hashPassword((string) $newAccount['password']),
                    'role' => 'student',
                    'center_id' => $centerId,
                    'is_active' => 1,
                ]);
            }

            $sid = Database::insert('students', [
                'user_id' => $userId,
                'group_id' => $groupId,
                'center_id' => $centerId,
                'level_id' => $levelId,
                'full_name' => trim((string) $request->input('full_name')),
                'birth_date' => $request->input('birth_date'),
                'gender' => $request->input('gender'),
                'enrollment_date' => $request->input('enrollment_date') ?: date('Y-m-d'),
                'status' => $request->input('status') ?: 'active',
                'student_type' => $request->input('student_type') ?: 'child',
                'memorization_mode' => $request->input('memorization_mode') ?: 'thumn',
                'start_hizb' => $request->input('start_hizb'),
                'notes' => $request->input('notes'),
            ]);

            // Track season level placement
            if ($centerId && $levelId) {
                $curSeasonId = Database::fetchValue("SELECT id FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $centerId])
                    ?: Database::fetchValue("SELECT id FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
                if ($curSeasonId) {
                    Database::insert('student_levels', [
                        'student_id' => $sid,
                        'season_id' => (int) $curSeasonId,
                        'level_id' => $levelId,
                    ]);
                }
            }

            return $sid;
        });

        $created = Database::fetchOne("
            SELECT s.*,
                   g.name AS group_name,
                   u.full_name AS user_name, u.email AS user_email,
                   l.code AS level_code, l.name_ar AS level_name_ar
            FROM `students` s
            LEFT JOIN `groups` g ON g.id = s.group_id
            LEFT JOIN `users` u ON u.id = s.user_id
            LEFT JOIN `levels` l ON l.id = s.level_id
            WHERE s.id = :id
        ", ['id' => $newStudentId]);

        return $this->created($this->formatStudent($created));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['student'] ?? 0);
        $student = Database::fetchOne("
            SELECT s.*,
                   g.name AS group_name,
                   u.full_name AS user_name, u.email AS user_email,
                   l.code AS level_code, l.name_ar AS level_name_ar
            FROM `students` s
            LEFT JOIN `groups` g ON g.id = s.group_id
            LEFT JOIN `users` u ON u.id = s.user_id
            LEFT JOIN `levels` l ON l.id = s.level_id
            WHERE s.id = :id
        ", ['id' => $id]);

        if (!$student) {
            return $this->fail('Student not found.', 404);
        }

        return $this->ok($this->formatStudent($student));
    }

    public function update(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $id = (int) ($params['id'] ?? $params['student'] ?? 0);

        $student = Database::fetchOne("SELECT * FROM `students` WHERE id = :id", ['id' => $id]);
        if (!$student) {
            return $this->fail('Student not found.', 404);
        }

        $input = $request->all();
        $fields = [
            'full_name', 'group_id', 'level_id', 'user_id', 'birth_date',
            'gender', 'enrollment_date', 'status', 'student_type',
            'memorization_mode', 'start_hizb', 'notes'
        ];

        $update = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) {
                $update[$f] = $input[$f];
            }
        }

        if (!empty($update)) {
            Database::update('students', $update, 'id = :id', ['id' => $id]);
        }

        $fresh = Database::fetchOne("
            SELECT s.*,
                   g.name AS group_name,
                   u.full_name AS user_name, u.email AS user_email,
                   l.code AS level_code, l.name_ar AS level_name_ar
            FROM `students` s
            LEFT JOIN `groups` g ON g.id = s.group_id
            LEFT JOIN `users` u ON u.id = s.user_id
            LEFT JOIN `levels` l ON l.id = s.level_id
            WHERE s.id = :id
        ", ['id' => $id]);

        return $this->ok($this->formatStudent($fresh));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['student'] ?? 0);

        $student = Database::fetchOne("SELECT * FROM `students` WHERE id = :id", ['id' => $id]);
        if (!$student) {
            return $this->fail('Student not found.', 404);
        }

        // Students are soft-deactivated via status
        Database::update('students', ['status' => 'left'], 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Student marked as left.');
    }
}
