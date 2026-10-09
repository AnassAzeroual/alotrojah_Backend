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
    public function login(LoginRequest $request): JsonResponse
    {
        // is_active is matched as a WHERE clause: deactivated accounts get the same generic 401.
        $credentials = $request->only('email', 'password') + ['is_active' => true];

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return $this->fail('Invalid credentials.', 401);
        }

        return $this->tokenResponse($token, 'Logged in.');
    }

    /**
     * Self-registration: parks the request in the waiting room.
     * No token — the account only exists after admin acceptance.
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

    public function me(): JsonResponse
    {
        $u = Auth::guard('api')->user();

        return $this->ok([
            'id' => $u->id, 'full_name' => $u->full_name, 'email' => $u->email,
            'role' => $u->role, 'center_id' => $u->center_id, 'center_name' => $u->center?->name,
            'teacher_type' => $u->teacher_type,
        ]);
    }

    public function refresh(): JsonResponse
    {
        return $this->tokenResponse(Auth::guard('api')->refresh(), 'Token refreshed.');
    }

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
