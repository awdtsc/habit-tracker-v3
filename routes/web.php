<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;
use App\Http\Controllers\Auth\ApiAuthController;

/*
|--------------------------------------------------------------------------
| Sanctum：CSRF Cookie
|--------------------------------------------------------------------------
*/
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->middleware('web')
    ->name('sanctum.csrf-cookie');

/*
|--------------------------------------------------------------------------
| Register（SPA でも web ミドルウェアで保護する）
|--------------------------------------------------------------------------
*/
Route::post('/api/register', [ApiAuthController::class, 'register'])
    ->middleware('web')
    ->name('api.register');

/*
|--------------------------------------------------------------------------
| Login / Logout（★重要：必ず web middleware）
|--------------------------------------------------------------------------
*/
Route::post('/api/login', [ApiAuthController::class, 'login'])
    ->middleware('web')
    ->name('api.login');

Route::post('/api/logout', [ApiAuthController::class, 'logout'])
    ->middleware('web')
    ->name('api.logout');

/*
|--------------------------------------------------------------------------
| 認証ユーザー取得（毎回チェック用）
|--------------------------------------------------------------------------
*/
Route::get('/api/user', [ApiAuthController::class, 'me'])
    ->middleware(['web', 'auth:sanctum'])
    ->name('api.user');

/*
|--------------------------------------------------------------------------
| SPA fallback
|--------------------------------------------------------------------------
*/
Route::view('/', 'app');

Route::view('/{any}', 'app')
    ->where('any', '^(?!api|sanctum).*$');