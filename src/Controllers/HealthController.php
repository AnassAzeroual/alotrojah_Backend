<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class HealthController extends BaseController
{
    public function index(Request $request): Response
    {
        return Response::json([
            'success' => true,
            'data' => [
                'status' => 'ok',
            ],
        ]);
    }

    public function health(Request $request): Response
    {
        return $this->index($request);
    }
}
