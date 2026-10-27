<?php

use App\Http\Controllers\Api\V1\GroupController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$u = User::withoutGlobalScopes()->find(1);
Auth::guard('api')->login($u);

$request = Request::create('/api/v1/groups', 'GET');
$request->setUserResolver(function () use ($u) {
    return $u;
});

try {
    $response = app(GroupController::class)->index($request);
    echo "SUCCESS\n";
    echo json_encode($response->getData());
} catch (Exception $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}
