<?php

declare(strict_types=1);

// Measure bootstrap time
define('APP_START_TIME', microtime(true));

// Strict error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Set root path
define('BASE_PATH', dirname(__DIR__));

// PSR-4 Autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Controllers\AnnouncementController;
use App\Controllers\AppearanceController;
use App\Controllers\AttendanceController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\CenterController;
use App\Controllers\DashboardController;
use App\Controllers\DelegationController;
use App\Controllers\ExamController;
use App\Controllers\ExamQuestionController;
use App\Controllers\GroupController;
use App\Controllers\HealthController;
use App\Controllers\LevelController;
use App\Controllers\MurajaaController;
use App\Controllers\NotificationController;
use App\Controllers\ReferenceController;
use App\Controllers\RegistrationRequestController;
use App\Controllers\ReportController;
use App\Controllers\ResultController;
use App\Controllers\ScoreController;
use App\Controllers\ScoringModuleController;
use App\Controllers\SeasonController;
use App\Controllers\SetupController;
use App\Controllers\StudentController;
use App\Controllers\TermBreakdownController;
use App\Controllers\TermPlanController;
use App\Controllers\UserController;
use App\Controllers\WeeklyGoalController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimiterMiddleware;

try {
    // 1. Load Environment
    Config::load(BASE_PATH . '/.env');

    // 2. Set timezone
    date_default_timezone_set(Config::get('APP_TIMEZONE', 'Africa/Casablanca'));

    // 3. Initialize Request & Router
    $request = new Request();
    $router = new Router();

    // -------------------------------------------------------------------------
    // Routes Definition
    // -------------------------------------------------------------------------
    // Root & Top-level health checks
    $router->get('/', [HealthController::class, 'health']);
    $router->get('health', [HealthController::class, 'health']);

    $router->group(['prefix' => 'v1'], function (Router $r): void {
        // Base v1 root & health check
        $r->get('', [HealthController::class, 'health']);
        $r->get('health', [HealthController::class, 'health']);

        // Public appearance assets
        $r->get('appearance/files/{kind}/{filename}', [AppearanceController::class, 'file']);

        // Public Auth
        $r->post('auth/login', [AuthController::class, 'login'], [RateLimiterMiddleware::class]);
        $r->post('auth/register', [AuthController::class, 'register'], [RateLimiterMiddleware::class]);

        // Authenticated routes
        $r->group(['middleware' => [AuthMiddleware::class]], function (Router $auth): void {
            // Auth session management
            $auth->get('auth/me', [AuthController::class, 'me']);
            $auth->post('auth/refresh', [AuthController::class, 'refresh']);
            $auth->post('auth/logout', [AuthController::class, 'logout']);

            // Setup guide
            $auth->get('setup/status', [SetupController::class, 'status']);

            // Appearance settings
            $auth->get('appearance', [AppearanceController::class, 'show']);
            $auth->patch('appearance', [AppearanceController::class, 'update']);
            $auth->post('appearance/{kind}', [AppearanceController::class, 'upload']);
            $auth->delete('appearance/{kind}', [AppearanceController::class, 'destroy']);

            // Identity & Users
            $auth->post('users/{user}/replace', [UserController::class, 'replace']);
            $auth->apiResource('users', UserController::class);

            $auth->get('registration-requests', [RegistrationRequestController::class, 'index']);
            $auth->post('registration-requests/{registrationRequest}/accept', [RegistrationRequestController::class, 'accept']);
            $auth->delete('registration-requests/{registrationRequest}', [RegistrationRequestController::class, 'destroy']);

            $auth->apiResource('centers', CenterController::class);

            $auth->delete('levels/reset', [LevelController::class, 'reset']);
            $auth->apiResource('levels', LevelController::class, ['only' => ['index', 'store', 'update', 'destroy']]);

            $auth->get('groups/stats', [GroupController::class, 'stats']);
            $auth->get('groups/{group}/detail', [GroupController::class, 'detail']);
            $auth->apiResource('groups', GroupController::class);

            $auth->get('students/unlinked-accounts', [StudentController::class, 'unlinkedAccounts']);
            $auth->get('students/{student}/exam-brief', [StudentController::class, 'examBrief']);
            $auth->apiResource('students', StudentController::class);

            // Daily Core: Attendance, Scores, Goals
            $auth->post('attendance/bulk', [AttendanceController::class, 'bulk']);
            $auth->apiResource('attendance', AttendanceController::class, ['only' => ['index', 'show', 'destroy']]);

            $auth->post('scores/bulk', [ScoreController::class, 'bulk']);
            $auth->get('scores', [ScoreController::class, 'index']);
            $auth->get('sessions/{session}/scores', [ScoreController::class, 'bySession']);
            $auth->get('students/{student}/weeks/{week}/followup', [ScoreController::class, 'followup']);

            $auth->put('weekly-goals', [WeeklyGoalController::class, 'upsert']);
            $auth->apiResource('weekly-goals', WeeklyGoalController::class, ['only' => ['index', 'show', 'destroy']]);

            // Planning & Calendar
            $auth->post('seasons/{season}/activate', [SeasonController::class, 'activate']);
            $auth->apiResource('seasons', SeasonController::class);

            $auth->get('seasons/{season}/terms', [CalendarController::class, 'terms']);
            $auth->get('terms/{term}', [CalendarController::class, 'showTerm']);
            $auth->patch('terms/{term}', [CalendarController::class, 'updateTerm']);
            $auth->get('weeks', [CalendarController::class, 'weeks']);
            $auth->patch('weeks/{week}', [CalendarController::class, 'updateWeek']);
            $auth->get('sessions-cal', [CalendarController::class, 'sessions']);
            $auth->patch('sessions-cal/{session}', [CalendarController::class, 'updateSession']);
            $auth->get('calendar', [CalendarController::class, 'feed']);

            $auth->put('term-plans', [TermPlanController::class, 'upsert']);
            $auth->apiResource('term-plans', TermPlanController::class, ['only' => ['index', 'show', 'destroy']]);

            $auth->put('scoring-modules', [ScoringModuleController::class, 'bulk']);
            $auth->delete('scoring-modules/reset', [ScoringModuleController::class, 'reset']);
            $auth->get('scoring-check', [ScoringModuleController::class, 'scoringCheck']);
            $auth->apiResource('scoring-modules', ScoringModuleController::class, ['only' => ['index', 'store', 'update', 'destroy']]);

            $auth->get('reference/levels', [ReferenceController::class, 'levels']);
            $auth->get('reference/surahs', [ReferenceController::class, 'surahs']);

            // Reviews & Exams
            $auth->get('murajaa-reviews', [MurajaaController::class, 'indexReviews']);
            $auth->post('murajaa-reviews', [MurajaaController::class, 'storeReview']);
            $auth->get('murajaa-reviews/{murajaaReview}', [MurajaaController::class, 'showReview']);
            $auth->delete('murajaa-reviews/{murajaaReview}', [MurajaaController::class, 'destroyReview']);

            $auth->get('revision-logs', [MurajaaController::class, 'indexLogs']);
            $auth->post('revision-logs', [MurajaaController::class, 'storeLog']);
            $auth->get('revision-logs/{revisionLog}', [MurajaaController::class, 'showLog']);
            $auth->delete('revision-logs/{revisionLog}', [MurajaaController::class, 'destroyLog']);

            $auth->get('exams/weekly-template', [ExamController::class, 'weeklyTemplate']);
            $auth->post('exams/weekly-batch', [ExamController::class, 'weeklyBatch']);
            $auth->apiResource('exams', ExamController::class);

            $auth->post('exams/{exam}/questions', [ExamQuestionController::class, 'bulk']);
            $auth->put('exams/{exam}/question-weights', [ExamQuestionController::class, 'reweight']);
            $auth->apiResource('exam-questions', ExamQuestionController::class, ['only' => ['show', 'update', 'destroy']]);

            // Results & Delegations
            $auth->get('term-results/breakdown', [TermBreakdownController::class, 'show']);
            $auth->get('term-results', [ResultController::class, 'indexTerms']);
            $auth->put('term-results', [ResultController::class, 'upsertTerm']);
            $auth->get('season-results', [ResultController::class, 'indexSeasons']);
            $auth->put('season-results', [ResultController::class, 'upsertSeason']);

            $auth->get('groups/{group}/delegations', [DelegationController::class, 'index']);
            $auth->post('groups/{group}/delegations', [DelegationController::class, 'generate']);
            $auth->post('delegations/redeem', [DelegationController::class, 'redeem']);
            $auth->delete('delegations/{delegation}', [DelegationController::class, 'revoke']);

            // Comms: Announcements & Notifications
            $auth->apiResource('announcements', AnnouncementController::class);

            $auth->apiResource('notifications', NotificationController::class, ['only' => ['index', 'store', 'show', 'destroy']]);
            $auth->patch('notifications/{notification}/status', [NotificationController::class, 'mark']);

            // Dashboard
            $auth->get('dashboard/me', [DashboardController::class, 'me']);
            $auth->get('dashboard/season', [DashboardController::class, 'season']);
            $auth->get('dashboard/weekly', [DashboardController::class, 'weekly']);
            $auth->get('dashboard/center', [DashboardController::class, 'center']);
            $auth->get('dashboard/final', [DashboardController::class, 'final']);

            // Reports
            $auth->get('reports/term', [ReportController::class, 'term']);
            $auth->get('reports/season', [ReportController::class, 'season']);
        });
    });

    // 4. Dispatch Request
    $response = $router->dispatch($request);
    $response->header('X-Response-Time', sprintf('%.2fms', (microtime(true) - APP_START_TIME) * 1000));
    $response->send();

} catch (\Throwable $e) {
    $code = $e->getCode();
    $statusCode = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;

    $errResponse = new Response([
        'success' => false,
        'message' => $e->getMessage() ?: 'Internal server error.',
        'errors' => [
            'code' => 'SERVER_ERROR',
            'exception' => Config::get('APP_DEBUG') === 'true' ? get_class($e) : null,
            'file' => Config::get('APP_DEBUG') === 'true' ? $e->getFile() . ':' . $e->getLine() : null,
        ],
    ], $statusCode);

    $errResponse->send();
}
