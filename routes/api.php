<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
 * API v1. Sections land with S6-S10:
 * S5 auth | S6 identity | S7 daily core | S8 planning
 * S9 reviews+exams | S10 comms+dashboard
 */

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['success' => true, 'data' => ['status' => 'ok']]));

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
        Route::middleware('auth:api')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('refresh', [AuthController::class, 'refresh']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });
});
