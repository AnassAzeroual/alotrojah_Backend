<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return $this->fail('Invalid credentials.', 401);
        }

        return $this->tokenResponse($token, 'Logged in.');
    }

    public function me(): JsonResponse
    {
        $u = Auth::guard('api')->user();

        return $this->ok([
            'id' => $u->id, 'full_name' => $u->full_name, 'email' => $u->email,
            'role' => $u->role, 'center_id' => $u->center_id, 'teacher_type' => $u->teacher_type,
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
                'role' => $u->role, 'center_id' => $u->center_id, 'teacher_type' => $u->teacher_type,
            ],
        ], $message);
    }
}
