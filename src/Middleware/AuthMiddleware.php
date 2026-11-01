<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\JWT;
use App\Core\Request;
use App\Core\Response;

final class AuthMiddleware
{
    public function handle(Request $request): ?Response
    {
        $token = $request->getBearerToken();
        if (!$token) {
            return Response::fail('Unauthenticated.', 401, ['code' => 'UNAUTHENTICATED']);
        }

        $payload = JWT::decode($token);
        if (!$payload || !isset($payload['sub'])) {
            return Response::fail('Unauthenticated.', 401, ['code' => 'UNAUTHENTICATED']);
        }

        $userId = (int) $payload['sub'];
        $user = Database::fetchOne("
            SELECT u.id, u.full_name, u.email, u.role, u.phone, u.center_id, u.teacher_type, u.is_active,
                   c.name AS center_name
            FROM `users` u
            LEFT JOIN `centers` c ON c.id = u.center_id
            WHERE u.id = :id AND u.is_active = 1
            LIMIT 1
        ", ['id' => $userId]);

        if (!$user) {
            return Response::fail('Unauthenticated.', 401, ['code' => 'UNAUTHENTICATED']);
        }

        $request->setUser($user);
        return null;
    }
}
