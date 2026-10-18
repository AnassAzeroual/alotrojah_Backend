<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Docs gate replacing Scramble's RestrictedDocsAccess: local env passes,
 * signed-in admins (viewApiDocs) pass, JSON clients get 403, and browsers
 * navigating the UI are sent to the docs login page instead of a bare 403.
 */
class EnsureDocsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        if (Gate::allows('viewApiDocs')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('docs/api.json')) {
            abort(403);
        }

        return redirect()->route('docs.login');
    }
}
