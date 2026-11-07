<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\JWT;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Validator;

final class AuthController extends BaseController
{
    public function login(Request $request): Response
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        $user = Database::fetchOne("
            SELECT u.id, u.full_name, u.email, u.password_hash, u.role, u.center_id, u.teacher_type, u.is_active,
                   c.name AS center_name
            FROM `users` u
            LEFT JOIN `centers` c ON c.id = u.center_id
            WHERE u.email = :email
            LIMIT 1
        ", ['email' => $email]);

        if (!$user || !(bool) $user['is_active'] || !Security::verifyPassword($password, (string) $user['password_hash'])) {
            return $this->fail('Invalid credentials.', 401, ['code' => 'INVALID_CREDENTIALS']);
        }

        $ttlMinutes = (int) Config::get('jwt_ttl', 60);
        $payload = [
            'sub' => (int) $user['id'],
            'role' => $user['role'],
            'center_id' => $user['center_id'] !== null ? (int) $user['center_id'] : null,
            'teacher_type' => $user['teacher_type'],
        ];

        $token = JWT::encode($payload, $ttlMinutes);

        return $this->ok([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttlMinutes * 60,
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'center_id' => $user['center_id'] !== null ? (int) $user['center_id'] : null,
                'center_name' => $user['center_name'],
                'teacher_type' => $user['teacher_type'],
                'pending_registrations' => $this->pendingRegistrations($user),
            ],
        ], 'Logged in.');
    }

    public function register(Request $request): Response
    {
        $v = Validator::make($request->all(), [
            'full_name' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:supervisor,teacher,student,board',
            'phone' => 'nullable|string',
            'teacher_type' => 'nullable|in:hifz,murajaa,both',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $email = trim((string) $request->input('email'));

        if (Database::fetchValue("SELECT 1 FROM `users` WHERE email = :email", ['email' => $email])) {
            return $this->fail('Email is already registered.', 422, [
                'email' => ['email_taken'],
                'code' => 'EMAIL_TAKEN',
            ]);
        }

        if (Database::fetchValue("SELECT 1 FROM `registration_requests` WHERE email = :email", ['email' => $email])) {
            return $this->fail('This email is already awaiting approval.', 422, [
                'email' => ['in_waiting_room'],
                'code' => 'IN_WAITING_ROOM',
            ]);
        }

        $role = (string) $request->input('role');
        $isStudent = $role === 'student';

        Database::insert('registration_requests', [
            'full_name' => trim((string) $request->input('full_name')),
            'email' => $email,
            'password_hash' => Security::hashPassword((string) $request->input('password')),
            'role' => $role,
            'teacher_type' => $role === 'teacher' ? ($request->input('teacher_type') ?: 'both') : null,
            'phone' => $request->input('phone'),
            'birth_date' => $isStudent ? $request->input('birth_date') : null,
            'gender' => $isStudent ? $request->input('gender') : null,
        ]);

        return $this->created(null, 'Registration request received. Awaiting admin approval.');
    }

    private function pendingRegistrations(array $user): ?int
    {
        if (($user['role'] ?? null) !== 'admin') {
            return null;
        }

        return (int) Database::fetchValue('SELECT COUNT(*) FROM `registration_requests`');
    }

    public function me(Request $request): Response
    {
        $user = $request->getUser();
        if (!$user) {
            return $this->fail('Unauthenticated.', 401);
        }

        return $this->ok([
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'center_id' => $user['center_id'] !== null ? (int) $user['center_id'] : null,
            'center_name' => $user['center_name'] ?? null,
            'teacher_type' => $user['teacher_type'],
            'pending_registrations' => $this->pendingRegistrations($user),
        ]);
    }

    public function refresh(Request $request): Response
    {
        $user = $request->getUser();
        if (!$user) {
            return $this->fail('Unauthenticated.', 401);
        }

        $oldToken = $request->getBearerToken();
        if ($oldToken) {
            JWT::blacklist($oldToken);
        }

        $ttlMinutes = (int) Config::get('jwt_ttl', 60);
        $payload = [
            'sub' => (int) $user['id'],
            'role' => $user['role'],
            'center_id' => $user['center_id'] !== null ? (int) $user['center_id'] : null,
            'teacher_type' => $user['teacher_type'],
        ];

        $token = JWT::encode($payload, $ttlMinutes);

        return $this->ok([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttlMinutes * 60,
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'center_id' => $user['center_id'] !== null ? (int) $user['center_id'] : null,
                'center_name' => $user['center_name'] ?? null,
                'teacher_type' => $user['teacher_type'],
                'pending_registrations' => $this->pendingRegistrations($user),
            ],
        ], 'Token refreshed.');
    }

    public function logout(Request $request): Response
    {
        $token = $request->getBearerToken();
        if ($token) {
            JWT::blacklist($token);
        }
        return $this->ok(null, 'Logged out.');
    }
}
