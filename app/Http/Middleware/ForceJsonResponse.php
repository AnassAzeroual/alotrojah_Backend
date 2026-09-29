<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** API-only app: every /api/* response is JSON, including auth errors. */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
