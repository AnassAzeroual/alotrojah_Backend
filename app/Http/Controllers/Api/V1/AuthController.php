<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\RegistrationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Exchange credentials for a JWT access token.
     *
     * The returned token authorizes every other v1 call via `Authorization: Bearer <token>`.
     * Deactivated accounts get the same generic 401 as wrong passwords — the is_active
     * check is merged into the JWT attempt, so callers cannot probe which emails exist.
     *
     * @response array{success: bool, message: string, data: array{access_token: string, token_type: string, expires_in: int, user: array{id: int, full_name: string, role: 'admin'|'supervisor'|'teacher'|'student'|'board', center_id: int|null, center_name: string|null, teacher_type: 'hifz'|'murajaa'|'both'|null}}}
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password') + ['is_active' => true];

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return $this->fail('Invalid credentials.', 401);
        }

        return $this->tokenResponse($token, 'Logged in.');
    }

    /**
     * Self-registration: parks the request in the waiting room.
     *
     * No token is issued — the account only exists after an admin accepts the request.
     * Returns 422 with `email_taken` or `in_waiting_room` when the email is already
     * registered or already awaiting approval.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (User::where('email', $data['email'])->exists()) {
            return $this->fail('Email is already registered.', 422, ['email' => ['email_taken']]);
        }
        if (RegistrationRequest::where('email', $data['email'])->exists()) {
            return $this->fail('This email is already awaiting approval.', 422, ['email' => ['in_waiting_room']]);
        }

        $isStudent = $data['role'] === 'student';
        RegistrationRequest::create([
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role' => $data['role'],
            'teacher_type' => $data['role'] === 'teacher' ? $data['teacher_type'] : null,
            'phone' => $data['phone'],
            'birth_date' => $isStudent ? $data['birth_date'] : null,
            'gender' => $isStudent ? $data['gender'] : null,
        ]);

        return $this->created(null, 'Registration request received. Awaiting admin approval.');
    }

    /**
     * Return the authenticated user's profile from the JWT.
     *
     * Includes the center name (null for admins, who have no center) and
     * teacher_type (null unless role is teacher). Token-less calls get 401.
     *
     * @response array{success: bool, message: string|null, data: array{id: int, full_name: string, email: string, role: 'admin'|'supervisor'|'teacher'|'student'|'board', center_id: int|null, center_name: string|null, teacher_type: 'hifz'|'murajaa'|'both'|null}}
     */
    public function me(): JsonResponse
    {
        $u = Auth::guard('api')->user();

        return $this->ok([
            'id' => $u->id, 'full_name' => $u->full_name, 'email' => $u->email,
            'role' => $u->role, 'center_id' => $u->center_id, 'center_name' => $u->center?->name,
            'teacher_type' => $u->teacher_type,
        ]);
    }

    /**
     * Rotate the JWT: return a fresh access token (same shape as login).
     *
     * Call before expires_in runs out; the previous token is invalidated by the blacklist.
     *
     * @response array{success: bool, message: string, data: array{access_token: string, token_type: string, expires_in: int, user: array{id: int, full_name: string, role: 'admin'|'supervisor'|'teacher'|'student'|'board', center_id: int|null, center_name: string|null, teacher_type: 'hifz'|'murajaa'|'both'|null}}}
     */
    public function refresh(): JsonResponse
    {
        return $this->tokenResponse(Auth::guard('api')->refresh(), 'Token refreshed.');
    }

    /**
     * Invalidate the current token (JWT blacklist).
     *
     * The client must discard its copy; subsequent calls with the same token get 401.
     */
    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout(); // blacklisted (JWT_BLACKLIST_ENABLED)

        return $this->ok(null, 'Logged out.');
    }

    protected function tokenResponse(string $token, string $message): JsonResponse
    {
        $u = Auth::guard('api')->user();

        return $this->ok([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
            'user' => [
                'id' => $u->id, 'full_name' => $u->full_name,
                'role' => $u->role, 'center_id' => $u->center_id, 'center_name' => $u->center?->name,
                'teacher_type' => $u->teacher_type,
            ],
        ], $message);
    }
}
