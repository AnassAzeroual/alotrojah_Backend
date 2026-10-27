<?php

use App\Http\Controllers\Api\V1\StudentController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$u = User::withoutGlobalScopes()->find(1);
Auth::guard('api')->login($u);

$request = Request::create('/api/v1/students', 'GET');
$request->setUserResolver(function () use ($u) {
    return $u;
});

$response = app(StudentController::class)->index($request);
echo json_encode($response->getData());
