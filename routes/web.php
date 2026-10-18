<?php

use App\Http\Controllers\DocsAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Docs access: session login for active admins only (viewApiDocs gate on the
// Scramble routes). The POST is throttled hard against brute force.
Route::get('docs/login', [DocsAuthController::class, 'showLogin'])->name('docs.login');
Route::post('docs/login', [DocsAuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('docs.login.submit');
Route::post('docs/logout', [DocsAuthController::class, 'logout'])->name('docs.logout');
