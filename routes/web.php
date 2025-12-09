<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;
use App\Http\Controllers\Auth\ApiAuthController;

/*
|--------------------------------------------------------------------------
| Sanctum CSRF Cookie（web middleware）
|--------------------------------------------------------------------------
| SPA の CSRF 初期化のために必須。
| /sanctum/csrf-cookie は web ミドルウェアで提供する必要がある。
*/
Route::middleware('web')->get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->name('sanctum.csrf-cookie');

/*
|--------------------------------------------------------------------------
| Session-based Authentication for SPA
|--------------------------------------------------------------------------
| SPA は /api/* であっても、セッションベースで動作するため
| 全て web ミドルウェアの下で提供する。
*/
Route::middleware('web')->group(function () {

    // Login
    Route::post('/api/login',  [ApiAuthController::class, 'login']);

    // Logout
    Route::post('/api/logout', [ApiAuthController::class, 'logout']);

    // Register
    Route::post('/api/register', [ApiAuthController::class, 'register']);

    // 認証状態チェック（Session で user を返す / 未ログインは 401）
    Route::middleware('auth.api')->get('/api/user', [ApiAuthController::class, 'me']);
});

/*
|--------------------------------------------------------------------------
| SPA fallback
|--------------------------------------------------------------------------
| sanctum/csrf-cookie 以外の全てのリクエストを Vue SPA に渡す。
*/
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');