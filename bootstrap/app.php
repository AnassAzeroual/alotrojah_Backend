<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Runs before route auth: restores Authorization from X-Auth-Token (host strips it).
        $middleware->prependToGroup('api', \App\Http\Middleware\MapCustomAuthHeader::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\ForceJsonResponse::class);
        $middleware->redirectGuestsTo(fn () => null); // API-only: 401 JSON, never redirect
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn () => request()->is('api/*'));
    })->create();
