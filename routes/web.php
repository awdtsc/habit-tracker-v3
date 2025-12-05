<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

// -------------------------------------------------------------
// Sanctum：CSRF Cookie（SPA が最初に叩く必要あり）
// -------------------------------------------------------------
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->name('sanctum.csrf-cookie');

// -------------------------------------------------------------
// ※ 重要：Breeze（Blade）の /login /register /forgot 等は削除する！
// API 認証は routes/api.php にまとめる
// -------------------------------------------------------------

// -------------------------------------------------------------
// SPA fallback
// -------------------------------------------------------------
Route::view('/{any}', 'app')
    ->where('any', '^(?!api|sanctum).*$');