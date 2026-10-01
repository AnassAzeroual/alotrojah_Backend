<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Heberjahiz strips the `Authorization` header before PHP (proxy/WAF level:
 * neither mod_rewrite nor SetEnvIf can recover it). The SPA therefore sends
 * the JWT twice — standard `Authorization` plus `X-Auth-Token` (custom
 * headers pass through untouched). This middleware restores `Authorization`
 * from the custom header so the JWT guard works unchanged.
 */
class MapCustomAuthHeader
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->header('Authorization') && $custom = $request->header('X-Auth-Token')) {
            $request->headers->set(
                'Authorization',
                str_starts_with($custom, 'Bearer ') ? $custom : "Bearer {$custom}"
            );
        }

        return $next($request);
    }
}
