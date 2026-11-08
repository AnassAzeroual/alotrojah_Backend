<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\MurajaaSummary;
use App\Core\Request;
use App\Core\Response;

final class SessionWorkspaceController extends BaseController
{
    private const ATTENDANCE_STATES = ['present', 'absent', 'late', 'excused'];
    private const TONES = ['good', 'focus', 'neutral'];

    /**
     * GET /v1/sessions/{session}/workspace
     */
    public function show(Request $request, array $params): Response
    {
        $user = $request->getUser();
        if ($user === null) {
            return $this->fail('Unauthenticated.', 401);
        }

        $sessionId = (int) ($params['session'] ?? 0);
        $ctx = $this->context($sessionId, $user);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return $this->ok($this->workspacePayload($ctx, $user));
    }

    /**
     * PUT /v1/sessions/{session}/workspace
     * One transactional save for attendance, module scores and notes.
     */
    public function save(Request $request, array $params): Response
    {
        $user = $request->getUser();
        if ($user === null) {
            return $this->fail('Unauthenticated.', 401);
        }

        $sessionId = (int) ($params['session'] ?? 0);
        $ctx = $this->context($sessionId, $user);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $permissions = $this->permissions($ctx, $user);
        $session = $ctx['session'];
        $students = $this->studentIds((int) $ctx['group']['id']);
        $modules = $this->modules((int) $ctx['group']['center_id']);

        $attendance = (array) ($request->input('attendance') ?? []);
        $scores = (array) ($request->input('scores') ?? []);
        $notes = (array) ($request->input('notes') ?? []);

        if ($attendance !== [] && ! $permissions['attendance']) {
            return $this->forbidden('You cannot edit attendance for this group.');
        }

        $moduleById = [];
        $moduleByCode = [];
        foreach ($modules as $module) {
            $moduleById[(int) $module['id']] = $module;
            $moduleByCode[(string) $module['code']] = $module;
        }

        foreach ($scores as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            $moduleId = (int) ($row['module_id'] ?? 0);
            if (! isset($students[$studentId]) || ! isset($moduleById[$moduleId])) {
                return $this->unprocessable(['scores' => ['A score row references an invalid student or module.']]);
            }
            $module = $moduleById[$moduleId];
            $scope = (string) $module['scope'];
            if ($scope === 'murajaa' && ! $permissions['murajaa']) {
                return $this->forbidden('You cannot edit Murajaa scores for this group.');
            }
            if ($scope !== 'murajaa' && ! $permissions['hifz']) {
                return $this->forbidden('You cannot edit Hifz scores for this group.');
            }
            $score = $row['score'] ?? null;
            if ($score !== null && $score !== '' && (! is_numeric($score) || (float) $score < 0 || (float) $score > (float) $module['max_points'])) {
                return $this->unprocessable(['scores' => ['A score is outside the module range.']]);
            }
        }

        foreach ($notes as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            if (! isset($students[$studentId])) {
                return $this->unprocessable(['notes' => ['A note row references an invalid student.']]);
            }
            $code = (string) ($row['module_code'] ?? '');
            if ($code !== 'behavior' && ! isset($moduleByCode[$code])) {
                return $this->unprocessable(['notes' => ['A note references an unknown module.']]);
            }
            $module = $moduleByCode[$code] ?? null;
            if ($module !== null && (string) $module['scope'] === 'murajaa' && ! $permissions['murajaa']) {
                return $this->forbidden('You cannot edit Murajaa notes for this group.');
            }
            if ($module !== null && (string) $module['scope'] !== 'murajaa' && ! $permissions['hifz']) {
                return $this->forbidden('You cannot edit Hifz notes for this group.');
            }
            $tone = $row['tone'] ?? null;
            if ($tone !== null && $tone !== '' && ! in_array($tone, self::TONES, true)) {
                return $this->unprocessable(['notes' => ['Invalid note tone.']]);
            }
        }

        $seasonId = (int) $session['season_id'];
        $termId = (int) $session['term_id'];
        $weekId = (int) $session['week_id'];
        $logDate = (string) ($session['planned_date'] ?: date('Y-m-d'));
        $teacherId = (int) $user['id'];

        Database::transaction(function () use (
            $attendance,
            $scores,
            $notes,
            $sessionId,
            $seasonId,
            $termId,
            $weekId,
            $logDate,
            $teacherId,
            $moduleByCode,
        ): void {
            foreach ($attendance as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);
                $status = (string) ($row['status'] ?? '');
                if (! in_array($status, self::ATTENDANCE_STATES, true)) {
                    continue;
                }
                Database::getPdo()->prepare(
                    'INSERT INTO `attendance`
                        (`student_id`, `season_id`, `term_id`, `week_id`, `session_id`, `status`, `marked_by`, `notes`)
                     VALUES (:sid, :season, :term, :week, :session, :status, :by, :notes)
                     ON DUPLICATE KEY UPDATE
                        `status` = VALUES(`status`), `marked_by` = VALUES(`marked_by`),
                        `marked_at` = CURRENT_TIMESTAMP, `notes` = VALUES(`notes`)',
                )->execute([
                    'sid' => $studentId,
                    'season' => $seasonId,
                    'term' => $termId,
                    'week' => $weekId,
                    'session' => $sessionId,
                    'status' => $status,
                    'by' => $teacherId,
                    'notes' => isset($row['notes']) && $row['notes'] !== '' ? (string) $row['notes'] : null,
                ]);
            }

            foreach ($scores as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);
                $moduleId = (int) ($row['module_id'] ?? 0);
                $score = $row['score'] ?? null;

                if ($score === null || $score === '') {
                    Database::delete(
                        'session_scores',
                        'student_id = :sid AND session_id = :session AND module_id = :module',
                        ['sid' => $studentId, 'session' => $sessionId, 'module' => $moduleId],
                    );
                    continue;
                }

                Database::getPdo()->prepare(
                    'INSERT INTO `session_scores`
                        (`student_id`, `season_id`, `term_id`, `week_id`, `session_id`, `log_date`, `module_id`, `score`, `entered_by`)
                     VALUES (:sid, :season, :term, :week, :session, :log_date, :module, :score, :by)
                     ON DUPLICATE KEY UPDATE
                        `score` = VALUES(`score`), `entered_by` = VALUES(`entered_by`), `log_date` = VALUES(`log_date`)',
                )->execute([
                    'sid' => $studentId,
                    'season' => $seasonId,
                    'term' => $termId,
                    'week' => $weekId,
                    'session' => $sessionId,
                    'log_date' => $logDate,
                    'module' => $moduleId,
                    'score' => (float) $score,
                    'by' => $teacherId,
                ]);
            }

            foreach ($notes as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);
                $code = trim((string) ($row['module_code'] ?? ''));
                $note = trim((string) ($row['note'] ?? ''));
                if ($note === '') {
                    Database::delete(
                        'session_notes',
                        'student_id = :sid AND session_id = :session AND module_code = :code',
                        ['sid' => $studentId, 'session' => $sessionId, 'code' => $code],
                    );
                    continue;
                }

                Database::getPdo()->prepare(
                    'INSERT INTO `session_notes`
                        (`student_id`, `session_id`, `module_code`, `note`, `tone`, `visibility`, `entered_by`)
                     VALUES (:sid, :session, :code, :note, :tone, :visibility, :by)
                     ON DUPLICATE KEY UPDATE
                        `note` = VALUES(`note`), `tone` = VALUES(`tone`),
                        `visibility` = VALUES(`visibility`), `entered_by` = VALUES(`entered_by`)',
                )->execute([
                    'sid' => $studentId,
                    'session' => $sessionId,
                    'code' => $code,
                    'note' => $note,
                    'tone' => isset($row['tone']) && $row['tone'] !== '' ? (string) $row['tone'] : null,
                    'visibility' => ($row['visibility'] ?? 'staff') === 'student' ? 'student' : 'staff',
                    'by' => $teacherId,
                ]);
            }
        });

        $affectedStudents = [];
        foreach ($scores as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            if ($studentId > 0) {
                $affectedStudents[$studentId] = true;
            }
        }
        foreach (array_keys($affectedStudents) as $studentId) {
            MurajaaSummary::recomputeTerm($studentId, $seasonId, $termId);
            MurajaaSummary::recomputeStudentSeason($studentId, $seasonId);
        }

        return $this->show($request, ['session' => $sessionId]);
    }

    /**
     * POST /v1/groups/{group}/sessions
     */
    public function createSession(Request $request, array $params): Response
    {
        $user = $request->getUser();
        if ($user === null) {
            return $this->fail('Unauthenticated.', 401);
        }

        $groupId = (int) ($params['group'] ?? 0);
        $group = Database::fetchOne(
            'SELECT id, center_id, teacher_id FROM `groups` WHERE id = :id',
            ['id' => $groupId],
        );
        if (! $group) {
            return $this->fail('Group not found.', 404);
        }
        $ctx = $this->contextForGroup($group, $user);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $role = (string) $user['role'];
        if ($role !== 'admin' && $role !== 'supervisor' && ! $this->teacherInGroup($groupId, (int) $user['id'])) {
            return $this->forbidden('You cannot create sessions for this group.');
        }

        $weekId = (int) $request->input('week_id', 0);
        $week = Database::fetchOne(
            'SELECT w.*, t.id AS term_id FROM `weeks` w JOIN `terms` t ON t.id = w.term_id WHERE w.id = :id',
            ['id' => $weekId],
        );
        if (! $week) {
            return $this->unprocessable(['week_id' => ['The selected week is invalid.']]);
        }

        $type = (string) $request->input('session_type', 'revision');
        if (! in_array($type, ['memorization', 'revision', 'exam'], true)) {
            return $this->unprocessable(['session_type' => ['Invalid session type.']]);
        }

        $date = (string) ($request->input('planned_date') ?: $week['start_date'] ?: date('Y-m-d'));
        if ($week['start_date'] && $date < $week['start_date']) {
            return $this->unprocessable(['planned_date' => ['The session date is before its week.']]);
        }
        if ($week['end_date'] && $date > $week['end_date']) {
            return $this->unprocessable(['planned_date' => ['The session date is after its week.']]);
        }

        $startTime = (string) ($request->input('start_time') ?: '16:00');
        $endTime = (string) ($request->input('end_time') ?: '18:00');
        if ($endTime <= $startTime) {
            return $this->unprocessable(['end_time' => ['End time must be after start time.']]);
        }

        $sessionId = Database::transaction(function () use ($group, $week, $type, $date, $startTime, $endTime, $user): int {
            Database::getPdo()->query('SELECT id FROM `groups` WHERE id = ' . (int) $group['id'] . ' FOR UPDATE')->fetch();
            $global = (int) Database::fetchValue(
                'SELECT COALESCE(MAX(session_number_global), 0) + 1 FROM `sessions` WHERE season_id = :season AND group_id = :group',
                ['season' => (int) $week['season_id'], 'group' => (int) $group['id']],
            );
            $inWeek = (int) Database::fetchValue(
                'SELECT COALESCE(MAX(session_number_in_week), 0) + 1 FROM `sessions` WHERE week_id = :week AND group_id = :group',
                ['week' => (int) $week['id'], 'group' => (int) $group['id']],
            );

            return Database::insert('sessions', [
                'season_id' => (int) $week['season_id'],
                'term_id' => (int) $week['term_id'],
                'week_id' => (int) $week['id'],
                'group_id' => (int) $group['id'],
                'session_number_global' => $global,
                'session_number_in_week' => $inWeek,
                'session_type' => $type,
                'planned_date' => $date,
                'status' => 'planned',
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);
        });

        $session = Database::fetchOne('SELECT * FROM `sessions` WHERE id = :id', ['id' => $sessionId]);

        return $this->created($this->formatSession($session));
    }

    /**
     * GET /v1/students/{student}/timeline?season_id=
     */
    public function timeline(Request $request, array $params): Response
    {
        $user = $request->getUser();
        if ($user === null) {
            return $this->fail('Unauthenticated.', 401);
        }

        $studentId = (int) ($params['student'] ?? 0);
        $student = Database::fetchOne(
            'SELECT s.*, g.name AS group_name, g.teacher_id, g.center_id AS group_center_id
             FROM `students` s LEFT JOIN `groups` g ON g.id = s.group_id WHERE s.id = :id',
            ['id' => $studentId],
        );
        if (! $student) {
            return $this->fail('Student not found.', 404);
        }

        $scope = $this->studentScope($student, $user);
        if ($scope instanceof Response) {
            return $scope;
        }

        $seasonId = (int) $request->query('season_id', 0);
        if ($seasonId === 0) {
            $seasonId = (int) Database::fetchValue(
                'SELECT id FROM `academic_seasons` WHERE center_id = :cid ORDER BY is_current DESC, id DESC LIMIT 1',
                ['cid' => (int) $student['center_id']],
            );
        }
        $season = $seasonId > 0
            ? Database::fetchOne('SELECT id, name, start_date, end_date FROM `academic_seasons` WHERE id = :id', ['id' => $seasonId])
            : null;

        $terms = $seasonId > 0
            ? Database::fetchAll('SELECT * FROM `terms` WHERE season_id = :sid ORDER BY term_number', ['sid' => $seasonId])
            : [];
        $weeks = $seasonId > 0
            ? Database::fetchAll('SELECT * FROM `weeks` WHERE season_id = :sid ORDER BY week_number_global', ['sid' => $seasonId])
            : [];
        $sessions = $seasonId > 0
            ? Database::fetchAll(
                'SELECT s.* FROM `sessions` s WHERE s.season_id = :sid AND s.group_id = :gid ORDER BY s.session_number_global',
                ['sid' => $seasonId, 'gid' => (int) $student['group_id']],
            )
            : [];

        $modules = $this->modules((int) $student['center_id']);
        $moduleById = [];
        foreach ($modules as $module) {
            $moduleById[(int) $module['id']] = $module;
        }

        $scoreRows = $seasonId > 0
            ? Database::fetchAll(
                'SELECT sc.*, m.code AS module_code, m.name_ar AS module_name, m.scope
                 FROM `session_scores` sc JOIN `scoring_modules` m ON m.id = sc.module_id
                 WHERE sc.student_id = :sid AND sc.season_id = :season',
                ['sid' => $studentId, 'season' => $seasonId],
            )
            : [];
        $scoresBySession = [];
        foreach ($scoreRows as $row) {
            // Duplicate legacy module definitions must not render twice.
            if (! isset($moduleById[(int) $row['module_id']])) {
                continue;
            }
            $scoresBySession[(int) $row['session_id']][] = [
                'module_code' => (string) $row['module_code'],
                'module_name' => (string) $row['module_name'],
                'scope' => (string) $row['scope'],
                'score' => (float) $row['score'],
            ];
        }

        $legacyReviews = $seasonId > 0
            ? Database::fetchAll(
                'SELECT * FROM `murajaa_reviews` WHERE student_id = :sid AND season_id = :season ORDER BY week_from',
                ['sid' => $studentId, 'season' => $seasonId],
            )
            : [];
        foreach ($legacyReviews as $review) {
            $sid = (int) $review['session_id'];
            $already = false;
            foreach ($scoresBySession[$sid] ?? [] as $existing) {
                if ($existing['module_code'] === 'murajaa') {
                    $already = true;
                }
            }
            if (! $already) {
                $scoresBySession[$sid][] = [
                    'module_code' => 'murajaa',
                    'module_name' => 'Murajaa',
                    'scope' => 'murajaa',
                    'score' => (float) $review['score'],
                    'legacy' => true,
                ];
            }
        }

        $attendanceRows = $seasonId > 0
            ? Database::fetchAll(
                'SELECT session_id, status, notes FROM `attendance` WHERE student_id = :sid AND season_id = :season',
                ['sid' => $studentId, 'season' => $seasonId],
            )
            : [];
        $attendanceBySession = [];
        foreach ($attendanceRows as $row) {
            $attendanceBySession[(int) $row['session_id']] = [
                'status' => (string) $row['status'],
                'notes' => $row['notes'] ?? null,
            ];
        }

        $noteSql = 'SELECT sn.*, u.full_name AS author_name FROM `session_notes` sn
                    LEFT JOIN `users` u ON u.id = sn.entered_by
                    WHERE sn.student_id = :sid';
        if ((string) ($user['role'] ?? '') === 'student') {
            $noteSql .= " AND sn.visibility = 'student'";
        }
        $noteRows = $seasonId > 0
            ? Database::fetchAll($noteSql . ' ORDER BY sn.created_at', ['sid' => $studentId])
            : [];
        $notesBySession = [];
        foreach ($noteRows as $row) {
            $notesBySession[(int) $row['session_id']][] = [
                'module_code' => (string) $row['module_code'],
                'note' => (string) $row['note'],
                'tone' => $row['tone'],
                'visibility' => (string) $row['visibility'],
                'author_name' => $row['author_name'],
            ];
        }

        $sessionRows = [];
        $termMurajaa = [];
        foreach ($sessions as $session) {
            $sid = (int) $session['id'];
            $sessionScores = $scoresBySession[$sid] ?? [];
            $murajaaScores = array_values(array_filter(
                $sessionScores,
                static fn (array $s): bool => $s['module_code'] === 'murajaa',
            ));
            if ($murajaaScores !== []) {
                $termId = (int) $session['term_id'];
                $termMurajaa[$termId][] = (float) $murajaaScores[0]['score'];
            }
            $sessionRows[] = [
                'id' => $sid,
                'term_id' => (int) $session['term_id'],
                'week_id' => (int) $session['week_id'],
                'session_number_global' => (int) $session['session_number_global'],
                'session_number_in_week' => (int) $session['session_number_in_week'],
                'session_type' => (string) $session['session_type'],
                'planned_date' => $session['planned_date'],
                'status' => (string) $session['status'],
                'scores' => $sessionScores,
                'notes' => $notesBySession[$sid] ?? [],
                'attendance' => $attendanceBySession[$sid] ?? null,
            ];
        }

        $termSummaries = [];
        foreach ($terms as $term) {
            $scores = $termMurajaa[(int) $term['id']] ?? [];
            $termSummaries[] = [
                'id' => (int) $term['id'],
                'name' => (string) $term['name_ar'],
                'murajaa_count' => count($scores),
                'murajaa_average' => $scores === [] ? null : round(array_sum($scores) / count($scores), 2),
            ];
        }
        $seasonAverages = array_values(array_filter(
            array_map(static fn (array $term): ?float => $term['murajaa_average'], $termSummaries),
            static fn (?float $avg): bool => $avg !== null,
        ));
        $seasonMurajaa = $seasonAverages === [] ? null : round(array_sum($seasonAverages) / count($seasonAverages), 2);

        return $this->ok([
            'student' => [
                'id' => $studentId,
                'full_name' => (string) $student['full_name'],
                'center_id' => (int) $student['center_id'],
                'group_id' => $student['group_id'] !== null ? (int) $student['group_id'] : null,
                'group_name' => $student['group_name'] ?? null,
                'gender' => $student['gender'],
            ],
            'season' => $season ? [
                'id' => (int) $season['id'],
                'name' => (string) $season['name'],
                'start_date' => $season['start_date'],
                'end_date' => $season['end_date'],
            ] : null,
            'terms' => array_map(static fn (array $term): array => [
                'id' => (int) $term['id'],
                'name' => (string) $term['name_ar'],
                'term_number' => (int) $term['term_number'],
            ], $terms),
            'weeks' => array_map(static fn (array $week): array => [
                'id' => (int) $week['id'],
                'term_id' => (int) $week['term_id'],
                'week_number_global' => (int) $week['week_number_global'],
                'week_number_in_term' => (int) $week['week_number_in_term'],
                'week_type' => (string) $week['week_type'],
                'start_date' => $week['start_date'],
                'end_date' => $week['end_date'],
            ], $weeks),
            'sessions' => $sessionRows,
            'modules' => $modules,
            'summaries' => [
                'terms' => $termSummaries,
                'season' => [
                    'murajaa_count' => array_sum(array_column($termSummaries, 'murajaa_count')),
                    'murajaa_average' => $seasonMurajaa,
                ],
            ],
        ]);
    }

    private function context(int $sessionId, array $user): array|Response
    {
        $session = Database::fetchOne(
            'SELECT s.*, g.name AS group_name, g.center_id, g.teacher_id,
                    se.name AS season_name, t.name_ar AS term_name, w.week_number_global
             FROM `sessions` s
             JOIN `groups` g ON g.id = s.group_id
             JOIN `academic_seasons` se ON se.id = s.season_id
             JOIN `terms` t ON t.id = s.term_id
             JOIN `weeks` w ON w.id = s.week_id
             WHERE s.id = :id',
            ['id' => $sessionId],
        );
        if (! $session) {
            return $this->fail('Session not found.', 404);
        }

        $group = [
            'id' => (int) $session['group_id'],
            'name' => (string) $session['group_name'],
            'center_id' => (int) $session['center_id'],
            'teacher_id' => (int) $session['teacher_id'],
        ];

        return $this->contextForGroup($group, $user, $session);
    }

    private function contextForGroup(array $group, array $user, ?array $session = null): array|Response
    {
        $role = (string) $user['role'];
        if ($role === 'admin' || $role === 'board') {
            return ['group' => $group, 'session' => $session];
        }
        if ($role === 'supervisor') {
            if ((int) $user['center_id'] !== (int) $group['center_id']) {
                return $this->forbidden('Group belongs to another center.');
            }
            return ['group' => $group, 'session' => $session];
        }
        if ($role === 'teacher' && $this->teacherInGroup((int) $group['id'], (int) $user['id'])) {
            return ['group' => $group, 'session' => $session];
        }

        return $this->forbidden('You cannot access this group.');
    }

    private function permissions(array $ctx, array $user): array
    {
        $role = (string) $user['role'];
        $type = (string) ($user['teacher_type'] ?? '');
        $staff = in_array($role, ['admin', 'supervisor'], true);
        $hifz = $staff || in_array($type, ['hifz', 'both'], true);
        $murajaa = $staff || in_array($type, ['murajaa', 'both'], true);

        return [
            'attendance' => $hifz,
            'hifz' => $hifz,
            'murajaa' => $murajaa,
            'can_create_session' => $staff || ($role === 'teacher' && $this->teacherInGroup((int) $ctx['group']['id'], (int) $user['id'])),
        ];
    }

    private function workspacePayload(array $ctx, array $user): array
    {
        $session = $ctx['session'];
        $group = $ctx['group'];
        $sessionId = (int) $session['id'];
        $modules = $this->modules((int) $group['center_id']);

        $students = Database::fetchAll(
            'SELECT id, full_name, status FROM `students` WHERE group_id = :gid ORDER BY full_name',
            ['gid' => (int) $group['id']],
        );
        $studentIds = array_map(static fn (array $s): int => (int) $s['id'], $students);
        $scoreRows = $studentIds === [] ? [] : Database::fetchAll(
            'SELECT sc.*, m.code AS module_code FROM `session_scores` sc
             JOIN `scoring_modules` m ON m.id = sc.module_id
             WHERE sc.session_id = :session',
            ['session' => $sessionId],
        );
        $attendanceRows = $studentIds === [] ? [] : Database::fetchAll(
            'SELECT student_id, status FROM `attendance` WHERE session_id = :session',
            ['session' => $sessionId],
        );
        $noteRows = $studentIds === [] ? [] : Database::fetchAll(
            'SELECT student_id, module_code, note, tone, visibility FROM `session_notes` WHERE session_id = :session',
            ['session' => $sessionId],
        );

        $scores = [];
        $moduleIds = array_fill_keys(array_map(static fn (array $m): int => (int) $m['id'], $modules), true);
        foreach ($scoreRows as $row) {
            if (! isset($moduleIds[(int) $row['module_id']])) {
                continue;
            }
            $scores[(int) $row['student_id']][(string) $row['module_code']] = (float) $row['score'];
        }
        $attendance = [];
        foreach ($attendanceRows as $row) {
            $attendance[(int) $row['student_id']] = (string) $row['status'];
        }
        $notes = [];
        foreach ($noteRows as $row) {
            $notes[(int) $row['student_id']][(string) $row['module_code']] = [
                'note' => (string) $row['note'],
                'tone' => $row['tone'],
                'visibility' => (string) $row['visibility'],
            ];
        }

        return [
            'session' => $this->formatSession($session),
            'group' => $group,
            'modules' => $modules,
            'permissions' => $this->permissions($ctx, $user),
            'students' => array_map(static fn (array $student): array => [
                'id' => (int) $student['id'],
                'full_name' => (string) $student['full_name'],
                'status' => (string) $student['status'],
                'attendance' => $attendance[(int) $student['id']] ?? null,
                'scores' => $scores[(int) $student['id']] ?? [],
                'notes' => $notes[(int) $student['id']] ?? [],
            ], $students),
        ];
    }

    private function studentIds(int $groupId): array
    {
        $ids = [];
        foreach (Database::fetchAll('SELECT id FROM `students` WHERE group_id = :gid', ['gid' => $groupId]) as $row) {
            $ids[(int) $row['id']] = true;
        }
        return $ids;
    }

    private function modules(int $centerId): array
    {
        $rows = Database::fetchAll(
            'SELECT id, code, name_ar, scope, max_points, is_in_weekly_total, sort_order
             FROM `scoring_modules`
             WHERE is_active = 1 AND (center_id IS NULL OR center_id = :cid)
             ORDER BY sort_order, id',
            ['cid' => $centerId],
        );
        $seen = [];
        $modules = [];
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $modules[] = $row;
        }

        return $modules;
    }

    private function studentScope(array $student, array $user): ?Response
    {
        $role = (string) $user['role'];
        if ($role === 'admin' || $role === 'board') {
            return null;
        }
        if ($role === 'student' && (int) $student['user_id'] === (int) $user['id']) {
            return null;
        }
        if ($role === 'supervisor' && (int) $student['center_id'] === (int) $user['center_id']) {
            return null;
        }
        if ($role === 'teacher' && $this->teacherInGroup((int) $student['group_id'], (int) $user['id'])) {
            return null;
        }
        return $this->forbidden('You cannot access this student timeline.');
    }

    private function teacherInGroup(int $groupId, int $userId): bool
    {
        if ((bool) Database::fetchValue(
            'SELECT 1 FROM `group_teachers` WHERE group_id = :gid AND user_id = :uid',
            ['gid' => $groupId, 'uid' => $userId],
        )) {
            return true;
        }

        // Legacy fallback while existing groups have only the lead teacher.
        return (bool) Database::fetchValue(
            'SELECT 1 FROM `groups` WHERE id = :gid AND teacher_id = :uid',
            ['gid' => $groupId, 'uid' => $userId],
        );
    }

    private function formatSession(array $session): array
    {
        return [
            'id' => (int) $session['id'],
            'season_id' => (int) $session['season_id'],
            'term_id' => (int) $session['term_id'],
            'week_id' => (int) $session['week_id'],
            'group_id' => $session['group_id'] !== null ? (int) $session['group_id'] : null,
            'session_number_global' => (int) $session['session_number_global'],
            'session_number_in_week' => (int) $session['session_number_in_week'],
            'session_type' => (string) $session['session_type'],
            'planned_date' => $session['planned_date'],
            'start_time' => $session['start_time'] ? substr((string) $session['start_time'], 0, 5) : null,
            'end_time' => $session['end_time'] ? substr((string) $session['end_time'], 0, 5) : null,
            'status' => (string) $session['status'],
        ];
    }
}
