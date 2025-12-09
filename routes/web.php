<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;
use App\Http\Controllers\Auth\ApiAuthController;

/*
|--------------------------------------------------------------------------
| Sanctum CSRF Cookie（web middleware）
|--------------------------------------------------------------------------
*/
Route::middleware('web')->get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->name('sanctum.csrf-cookie');

/*
|--------------------------------------------------------------------------
| Session-based Authentication（SPA: /api/* だけど web middleware）
|--------------------------------------------------------------------------
*/
Route::middleware('web')->group(function () {
    
    // Login
    Route::post('/api/login',  [ApiAuthController::class, 'login']);
    
    // Logout
    Route::post('/api/logout', [ApiAuthController::class, 'logout']);

    // Register
    Route::post('/api/register', [ApiAuthController::class, 'register']);

    // 認証状態チェック（Session で user を返す）
    Route::get('/api/user', [ApiAuthController::class, 'me']);
});

/*
|--------------------------------------------------------------------------
| SPA fallback（sanctum/csrf-cookie 以外の全てを Vue に渡す）
|--------------------------------------------------------------------------
*/
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');