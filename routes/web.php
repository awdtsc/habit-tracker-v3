<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

use App\Http\Controllers\Auth\ApiAuthController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\HabitLogController;
use App\Http\Controllers\WeekController;

/*
|--------------------------------------------------------------------------
| Sanctum CSRF Cookie
|--------------------------------------------------------------------------
*/
Route::middleware('web')->get(
    '/sanctum/csrf-cookie',
    [CsrfCookieController::class, 'show']
)->name('sanctum.csrf-cookie');


/*
|--------------------------------------------------------------------------
| Session-based Auth for SPA
|--------------------------------------------------------------------------
|
| ※ SPA は Sanctum（session）認証なので、
|   /api/* も web ミドルウェア（EncryptCookies + StartSession）配下に置く。
|
*/
Route::middleware('web')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Auth (public)
    |--------------------------------------------------------------------------
    */
    Route::post('/api/login',    [ApiAuthController::class, 'login']);
    Route::post('/api/logout',   [ApiAuthController::class, 'logout']);
    Route::post('/api/register', [ApiAuthController::class, 'register']);

    /*
    |--------------------------------------------------------------------------
    | Authenticated API (auth.api)
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth.api')->group(function () {

        // 認証ユーザー
        Route::get('/api/user', [ApiAuthController::class, 'me']);

        // 今日のデータ
        Route::get('/api/today', [TodayController::class, 'show']);

        // 週のデータ（★追加）
        Route::get('/api/week', [WeekController::class, 'show']);

        /*
        |--------------------------------------------------------------------------
        | Habit Logs：トグル・評価更新（v3 統一仕様）
        |--------------------------------------------------------------------------
        */
        Route::post('/api/habits/{habit}/toggle', [HabitLogController::class, 'toggle']);
        // 将来の self-rating などもここに追加可能
        // Route::post('/api/habits/{habit}/rate',   [HabitLogController::class, 'rate']);
    });
});


/*
|--------------------------------------------------------------------------
| SPA fallback（必ず最後）
|--------------------------------------------------------------------------
*/
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');