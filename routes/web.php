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
    ->name('sanctum.csrf-cookie');

/*
|--------------------------------------------------------------------------
| Register（※ SPA でも web ミドルウェアで処理するのが正解）
|--------------------------------------------------------------------------
*/
Route::post('/register', [ApiAuthController::class, 'register']);

/*
|--------------------------------------------------------------------------
| SPA fallback
|--------------------------------------------------------------------------
*/
Route::view('/', 'app');
Route::view('/{any}', 'app')
    ->where('any', '^(?!api|sanctum).*$');