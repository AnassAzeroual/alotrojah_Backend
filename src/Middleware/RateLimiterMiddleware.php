<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Security;

final class RateLimiterMiddleware
{
    private int $maxAttempts;
    private int $decaySeconds;

    public function __construct(int $maxAttempts = 60, int $decaySeconds = 60)
    {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
    }

    public function handle(Request $request): ?Response
    {
        $key = 'rl_' . $request->ip() . '_' . $request->getPath();
        if (!Security::checkRateLimit($key, $this->maxAttempts, $this->decaySeconds)) {
            return Response::fail('Too Many Attempts.', 429, ['code' => 'TOO_MANY_REQUESTS']);
        }
        return null;
    }
}
