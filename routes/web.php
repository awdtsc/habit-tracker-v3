<?php

use Illuminate\Support\Facades\Route;

// Sanctum
Route::get('/sanctum/csrf-cookie', '\Laravel\Sanctum\Http\Controllers\CsrfCookieController@show');

// ❗ API と sanctum を先に除外
Route::prefix('api')->group(function () {
    // ここは絶対に空でOK（ルートは routes/api.php で定義される）
});

// SPA fallback（最後）
Route::fallback(function () {
    return view('app');
});