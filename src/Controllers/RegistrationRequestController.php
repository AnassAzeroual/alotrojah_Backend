<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class RegistrationRequestController extends BaseController
{
    private function formatRequest(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'teacher_type' => $row['teacher_type'] ?? null,
            'phone' => $row['phone'] ?? null,
            'birth_date' => $row['birth_date'] ?? null,
            'gender' => $row['gender'] ?? null,
            'requested_at' => $row['requested_at'] ?? null,
        ];
    }

    public function index(Request $request): Response
    {
        $this->authorizeRole($request, ['admin']);

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $sql = "SELECT * FROM `registration_requests` ORDER BY requested_at DESC";
        $paginated = Database::paginate($sql, [], $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatRequest'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function accept(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin']);
        $id = (int) ($params['id'] ?? $params['registrationRequest'] ?? 0);

        $reg = Database::fetchOne("SELECT * FROM `registration_requests` WHERE id = :id", ['id' => $id]);
        if (!$reg) {
            return $this->fail('Registration request not found.', 404);
        }

        $v = Validator::make($request->all(), [
            'center_id' => 'required|integer',
            'group_id' => 'nullable|integer',
            'level_id' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $centerId = (int) $request->input('center_id');
        $groupId = $request->input('group_id') ? (int) $request->input('group_id') : null;
        $levelId = $request->input('level_id') ? (int) $request->input('level_id') : null;

        if ($reg['role'] === 'student' && ($groupId === null || $levelId === null)) {
            return $this->fail('Student approval requires a group and a level.', 422);
        }

        if ($groupId !== null) {
            if (!in_array($reg['role'], ['teacher', 'student'], true)) {
                return $this->fail('Group assignment only applies to teachers and students.', 422);
            }
            $group = Database::fetchOne("SELECT id, center_id FROM `groups` WHERE id = :id", ['id' => $groupId]);
            if (!$group) {
                return $this->fail('Group not found.', 404);
            }
            if ((int) $group['center_id'] !== $centerId) {
                return $this->fail('Group belongs to another center.', 422);
            }
        }

        $newUser = Database::transaction(function () use ($reg, $centerId, $groupId, $levelId) {
            $userId = Database::insert('users', [
                'full_name' => $reg['full_name'],
                'email' => $reg['email'],
                'password_hash' => $reg['password_hash'],
                'role' => $reg['role'],
                'phone' => $reg['phone'],
                'center_id' => $centerId,
                'teacher_type' => $reg['teacher_type'],
                'is_active' => 1,
            ]);

            if ($reg['role'] === 'student') {
                $studentType = 'child';
                if (!empty($reg['birth_date'])) {
                    $age = (new \DateTime())->diff(new \DateTime($reg['birth_date']))->y;
                    if ($age >= 18) {
                        $studentType = 'adult';
                    }
                }

                $studentId = Database::insert('students', [
                    'user_id' => $userId,
                    'center_id' => $centerId,
                    'group_id' => $groupId,
                    'level_id' => $levelId,
                    'full_name' => $reg['full_name'],
                    'birth_date' => $reg['birth_date'],
                    'gender' => $reg['gender'],
                    'student_type' => $studentType,
                    'enrollment_date' => date('Y-m-d'),
                ]);

                // Place in current season if exists
                $currentSeasonId = Database::fetchValue("SELECT id FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $centerId])
                    ?: Database::fetchValue("SELECT id FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
                if ($currentSeasonId && $levelId) {
                    Database::insert('student_levels', [
                        'student_id' => $studentId,
                        'season_id' => (int) $currentSeasonId,
                        'level_id' => $levelId,
                    ]);
                }
            } elseif ($groupId !== null) {
                Database::update('groups', ['teacher_id' => $userId], 'id = :id', ['id' => $groupId]);
            }

            Database::delete('registration_requests', 'id = :id', ['id' => $reg['id']]);

            return Database::fetchOne("
                SELECT u.*, c.name AS center_name
                FROM `users` u
                LEFT JOIN `centers` c ON c.id = u.center_id
                WHERE u.id = :id
            ", ['id' => $userId]);
        });

        return $this->ok([
            'id' => (int) $newUser['id'],
            'full_name' => $newUser['full_name'],
            'email' => $newUser['email'],
            'role' => $newUser['role'],
            'center_id' => $newUser['center_id'] !== null ? (int) $newUser['center_id'] : null,
            'center_name' => $newUser['center_name'] ?? null,
            'teacher_type' => $newUser['teacher_type'] ?? null,
        ], 'Registration request accepted.');
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin']);
        $id = (int) ($params['id'] ?? $params['registrationRequest'] ?? 0);

        $exists = Database::fetchValue("SELECT 1 FROM `registration_requests` WHERE id = :id", ['id' => $id]);
        if (!$exists) {
            return $this->fail('Registration request not found.', 404);
        }

        Database::delete('registration_requests', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Registration request rejected.');
    }
}
