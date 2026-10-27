<?php

use App\Http\Controllers\Api\V1\GroupController;
use App\Models\User;
use App\Services\GroupStatsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$u = User::withoutGlobalScopes()->find(1);
Auth::guard('api')->login($u);

$request = Request::create('/api/v1/groups/stats', 'GET');
$request->setUserResolver(function () use ($u) {
    return $u;
});

$response = app(GroupController::class)->stats($request, app(GroupStatsService::class));
echo json_encode($response->getData());
