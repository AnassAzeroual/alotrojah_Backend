<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CenterController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\ScoreController;
use App\Http\Controllers\Api\V1\ScoringModuleController;
use App\Http\Controllers\Api\V1\SeasonController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\TermPlanController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WeeklyGoalController;
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

    Route::middleware('auth:api')->group(function () {
        // S6 identity
        Route::apiResource('users', UserController::class);
        Route::apiResource('centers', CenterController::class);
        Route::apiResource('groups', GroupController::class);
        Route::apiResource('students', StudentController::class);
        Route::apiResource('guardians', GuardianController::class);

        // S7 daily core
        Route::post('attendance/bulk', [AttendanceController::class, 'bulk']);
        Route::apiResource('attendance', AttendanceController::class)->only(['index', 'show', 'destroy']);
        Route::post('scores/bulk', [ScoreController::class, 'bulk']);
        Route::get('scores', [ScoreController::class, 'index']);
        Route::get('sessions/{session}/scores', [ScoreController::class, 'bySession']);
        Route::get('students/{student}/weeks/{week}/followup', [ScoreController::class, 'followup']);
        Route::put('weekly-goals', [WeeklyGoalController::class, 'upsert']);
        Route::apiResource('weekly-goals', WeeklyGoalController::class)->only(['index', 'show', 'destroy']);

        // S8 planning
        Route::post('seasons/{season}/activate', [SeasonController::class, 'activate']);
        Route::apiResource('seasons', SeasonController::class);
        Route::get('seasons/{season}/terms', [CalendarController::class, 'terms']);
        Route::get('terms/{term}', [CalendarController::class, 'showTerm']);
        Route::patch('terms/{term}', [CalendarController::class, 'updateTerm']);
        Route::get('weeks', [CalendarController::class, 'weeks']);
        Route::patch('weeks/{week}', [CalendarController::class, 'updateWeek']);
        Route::get('sessions-cal', [CalendarController::class, 'sessions']);
        Route::patch('sessions-cal/{session}', [CalendarController::class, 'updateSession']);
        Route::put('term-plans', [TermPlanController::class, 'upsert']);
        Route::apiResource('term-plans', TermPlanController::class)->only(['index', 'show', 'destroy']);
        Route::put('scoring-modules', [ScoringModuleController::class, 'bulk']);
        Route::get('scoring-check', [ScoringModuleController::class, 'scoringCheck']);
        Route::apiResource('scoring-modules', ScoringModuleController::class)->only(['index', 'store', 'update']);
        Route::get('reference/levels', [ReferenceController::class, 'levels']);
        Route::get('reference/surahs', [ReferenceController::class, 'surahs']);
        Route::get('reference/hizb', [ReferenceController::class, 'hizb']);
    });
});
