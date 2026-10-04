<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$u = App\Models\User::withoutGlobalScopes()->find(1);
Auth::guard('api')->login($u);

$request = Illuminate\Http\Request::create('/api/v1/students', 'GET');
$request->setUserResolver(function() use($u) { return $u; });

$response = app(App\Http\Controllers\Api\V1\StudentController::class)->index($request);
echo json_encode($response->getData());
