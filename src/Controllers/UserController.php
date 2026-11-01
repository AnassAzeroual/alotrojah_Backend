<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Validator;

final class UserController extends BaseController
{
    private function formatUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'phone' => $row['phone'] ?? null,
            'center_id' => $row['center_id'] !== null ? (int) $row['center_id'] : null,
            'center_name' => $row['center_name'] ?? null,
            'teacher_type' => $row['teacher_type'] ?? null,
            'is_active' => (bool) $row['is_active'],
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    public function index(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);

        $conditions = [];
        $params = [];

        $this->applyCenterScope($request, 'users', $conditions, $params);

        $roleFilter = $request->query('role');
        if ($roleFilter !== null && $roleFilter !== '') {
            $conditions[] = "users.role = :role_filter";
            $params['role_filter'] = $roleFilter;
        }

        $teacherTypeFilter = $request->query('teacher_type');
        if ($teacherTypeFilter !== null && $teacherTypeFilter !== '') {
            $conditions[] = "users.teacher_type = :tt_filter";
            $params['tt_filter'] = $teacherTypeFilter;
        }

        $search = $request->query('search');
        if ($search !== null && $search !== '') {
            $conditions[] = "(users.full_name LIKE :search OR users.email LIKE :search OR users.phone LIKE :search)";
            $params['search'] = "%{$search}%";
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $baseSql = "
            SELECT users.id, users.full_name, users.email, users.role, users.phone,
                   users.center_id, users.teacher_type, users.is_active, users.created_at,
                   centers.name AS center_name
            FROM `users`
            LEFT JOIN `centers` ON centers.id = users.center_id
            {$whereClause}
            ORDER BY users.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $paginated = Database::paginate($baseSql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatUser'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);

        $v = Validator::make($request->all(), [
            'full_name' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,supervisor,teacher,student,board',
            'phone' => 'nullable|string',
            'center_id' => 'nullable|integer',
            'teacher_type' => 'nullable|in:hifz,murajaa,both',
            'is_active' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $me = $request->getUser();
        $role = (string) $request->input('role');
        if ($me['role'] !== 'admin' && $role === 'admin') {
            return $this->fail('Only admin can create admins.', 403);
        }

        $email = trim((string) $request->input('email'));
        if (Database::fetchValue("SELECT 1 FROM `users` WHERE email = :email", ['email' => $email])) {
            return $this->fail('Email is already registered.', 422, ['email' => ['The email has already been taken.']]);
        }

        $centerId = $me['role'] === 'admin'
            ? ($request->input('center_id') ? (int) $request->input('center_id') : null)
            : ($me['center_id'] !== null ? (int) $me['center_id'] : null);

        $teacherType = null;
        if ($role === 'teacher') {
            $teacherType = $request->input('teacher_type') ?: 'both';
        }

        $newId = Database::insert('users', [
            'full_name' => trim((string) $request->input('full_name')),
            'email' => $email,
            'password_hash' => Security::hashPassword((string) $request->input('password')),
            'role' => $role,
            'phone' => $request->input('phone'),
            'center_id' => $centerId,
            'teacher_type' => $teacherType,
            'is_active' => $request->input('is_active') !== null ? (int) filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN) : 1,
        ]);

        $created = Database::fetchOne("
            SELECT u.*, c.name AS center_name
            FROM `users` u
            LEFT JOIN `centers` c ON c.id = u.center_id
            WHERE u.id = :id
        ", ['id' => $newId]);

        return $this->created($this->formatUser($created));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['user'] ?? 0);
        $user = Database::fetchOne("
            SELECT u.*, c.name AS center_name
            FROM `users` u
            LEFT JOIN `centers` c ON c.id = u.center_id
            WHERE u.id = :id
        ", ['id' => $id]);

        if (!$user) {
            return $this->fail('User not found.', 404);
        }

        $me = $request->getUser();
        if ($me['role'] !== 'admin' && $me['role'] !== 'board' && (int) $user['id'] !== (int) $me['id']) {
            if ($user['center_id'] !== null && (int) $user['center_id'] !== (int) $me['center_id']) {
                return $this->fail('This action is unauthorized.', 403);
            }
        }

        return $this->ok($this->formatUser($user));
    }

    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['user'] ?? 0);
        $user = Database::fetchOne("SELECT * FROM `users` WHERE id = :id", ['id' => $id]);
        if (!$user) {
            return $this->fail('User not found.', 404);
        }

        $me = $request->getUser();
        $self = (int) $user['id'] === (int) $me['id'];

        $input = $request->all();
        $deactivating = isset($input['is_active']) && !filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);

        if ($self) {
            unset($input['role'], $input['center_id'], $input['teacher_type']);
            if ($deactivating && $me['role'] === 'admin') {
                return $this->fail('Admins cannot deactivate their own account.', 422);
            }
            if (!$deactivating) {
                unset($input['is_active']);
            }
        }

        if ($me['role'] !== 'admin') {
            if (isset($input['role']) && $input['role'] === 'admin') {
                return $this->fail('Only admin can assign admin role.', 403);
            }
            unset($input['center_id']);
        }

        $updateData = [];
        if (isset($input['full_name'])) {
            $updateData['full_name'] = trim((string) $input['full_name']);
        }
        if (isset($input['email'])) {
            $email = trim((string) $input['email']);
            if ($email !== $user['email'] && Database::fetchValue("SELECT 1 FROM `users` WHERE email = :email AND id != :id", ['email' => $email, 'id' => $id])) {
                return $this->fail('Email is already registered.', 422, ['email' => ['The email has already been taken.']]);
            }
            $updateData['email'] = $email;
        }
        if (!empty($input['password'])) {
            $updateData['password_hash'] = Security::hashPassword((string) $input['password']);
        }
        if (isset($input['role'])) {
            $updateData['role'] = $input['role'];
            if ($input['role'] !== 'teacher') {
                $updateData['teacher_type'] = null;
            }
        }
        if (isset($input['phone'])) {
            $updateData['phone'] = $input['phone'];
        }
        if (array_key_exists('center_id', $input)) {
            $updateData['center_id'] = $input['center_id'] !== null ? (int) $input['center_id'] : null;
        }
        if (isset($input['teacher_type']) && ($input['role'] ?? $user['role']) === 'teacher') {
            $updateData['teacher_type'] = $input['teacher_type'];
        }
        if (isset($input['is_active'])) {
            $updateData['is_active'] = (int) filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($deactivating && $user['role'] === 'admin') {
            $otherActive = Database::fetchValue("SELECT 1 FROM `users` WHERE role = 'admin' AND is_active = 1 AND id != :id", ['id' => $id]);
            if (!$otherActive) {
                return $this->fail('The last active admin cannot be deactivated.', 422);
            }
        }

        if (!empty($updateData)) {
            Database::update('users', $updateData, 'id = :id', ['id' => $id]);
        }

        $fresh = Database::fetchOne("
            SELECT u.*, c.name AS center_name
            FROM `users` u
            LEFT JOIN `centers` c ON c.id = u.center_id
            WHERE u.id = :id
        ", ['id' => $id]);

        return $this->ok($this->formatUser($fresh));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['user'] ?? 0);

        $user = Database::fetchOne("SELECT * FROM `users` WHERE id = :id", ['id' => $id]);
        if (!$user) {
            return $this->fail('User not found.', 404);
        }

        if ($user['role'] === 'admin') {
            return $this->fail('Admin accounts cannot be deleted.', 422);
        }

        $groups = Database::fetchAll("SELECT id, name FROM `groups` WHERE teacher_id = :tid", ['tid' => $id]);
        $examsCount = (int) Database::fetchValue("SELECT COUNT(*) FROM `exams` WHERE examiner_id = :id", ['id' => $id]);
        $revLogsCount = (int) Database::fetchValue("SELECT COUNT(*) FROM `revision_logs` WHERE entered_by = :id", ['id' => $id]);
        $murajaaCount = (int) Database::fetchValue("SELECT COUNT(*) FROM `murajaa_reviews` WHERE entered_by = :id", ['id' => $id]);
        $annCount = (int) Database::fetchValue("SELECT COUNT(*) FROM `announcements` WHERE author_id = :id", ['id' => $id]);
        $tokensCount = (int) Database::fetchValue("SELECT COUNT(*) FROM `delegation_tokens` WHERE granter_teacher_id = :id OR used_by_teacher_id = :id", ['id' => $id]);

        $enteredScores = $revLogsCount + $murajaaCount;

        if (!empty($groups) || $examsCount > 0 || $enteredScores > 0) {
            return $this->fail('This teacher owns groups or history — choose a replacer.', 422, [
                'code' => 'NEED_REPLACER',
                'teacher' => [
                    'id' => (int) $user['id'],
                    'full_name' => $user['full_name'],
                    'teacher_type' => $user['teacher_type'],
                    'center_id' => $user['center_id'] !== null ? (int) $user['center_id'] : null,
                ],
                'groups' => $groups,
                'counts' => [
                    'exams' => $examsCount,
                    'entered_scores' => $enteredScores,
                    'announcements' => $annCount,
                    'tokens' => $tokensCount,
                ],
            ]);
        }

        Database::transaction(function () use ($id) {
            Database::delete('announcements', 'author_id = :id', ['id' => $id]);
            Database::delete('delegation_tokens', 'granter_teacher_id = :id OR used_by_teacher_id = :id', ['id' => $id]);
            Database::delete('users', 'id = :id', ['id' => $id]);
        });

        return $this->ok(null, 'Deleted.');
    }

    public function replace(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['user'] ?? 0);
        $user = Database::fetchOne("SELECT * FROM `users` WHERE id = :id", ['id' => $id]);
        if (!$user) {
            return $this->fail('User not found.', 404);
        }

        if ($user['role'] === 'admin') {
            return $this->fail('Admin accounts cannot be deleted.', 422);
        }

        $v = Validator::make($request->all(), [
            'replacer_id' => 'required|integer',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $replacerId = (int) $request->input('replacer_id');
        $replacer = Database::fetchOne("SELECT * FROM `users` WHERE id = :id", ['id' => $replacerId]);
        if (!$replacer) {
            return $this->fail('Replacer not found.', 404);
        }

        if ($replacerId === $id) {
            return $this->fail('The replacer cannot be the deleted teacher.', 422);
        }
        if ($replacer['role'] !== 'teacher' || !(bool) $replacer['is_active']) {
            return $this->fail('The replacer must be an active teacher.', 422);
        }
        if ((int) $replacer['center_id'] !== (int) $user['center_id']) {
            return $this->fail('The replacer must be in the same center.', 422);
        }
        if ($replacer['teacher_type'] !== $user['teacher_type'] && $replacer['teacher_type'] !== 'both') {
            return $this->fail('The replacer must have the same type (or both).', 422);
        }
        if (Database::fetchValue("SELECT 1 FROM `groups` WHERE teacher_id = :tid AND is_active = 1", ['tid' => $replacerId])) {
            return $this->fail('The replacer already owns an active group.', 422);
        }

        $moved = [];
        Database::transaction(function () use ($id, $replacerId, &$moved) {
            $moved['groups'] = Database::update('groups', ['teacher_id' => $replacerId], 'teacher_id = :old', ['old' => $id]);
            $moved['exams'] = Database::update('exams', ['examiner_id' => $replacerId], 'examiner_id = :old', ['old' => $id]);
            Database::update('revision_logs', ['entered_by' => $replacerId], 'entered_by = :old', ['old' => $id]);
            Database::update('murajaa_reviews', ['entered_by' => $replacerId], 'entered_by = :old', ['old' => $id]);
            $moved['announcements'] = Database::delete('announcements', 'author_id = :id', ['id' => $id]);
            $moved['tokens'] = Database::delete('delegation_tokens', 'granter_teacher_id = :id OR used_by_teacher_id = :id', ['id' => $id]);
            Database::delete('users', 'id = :id', ['id' => $id]);
        });

        return $this->ok(['replacer_id' => $replacerId, 'moved' => $moved], 'Replaced and deleted.');
    }
}
