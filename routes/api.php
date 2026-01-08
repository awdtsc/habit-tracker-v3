<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\ApiAuthController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\WeekController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\HabitLogController;

/*
|--------------------------------------------------------------------------
| API Routes (Bearer Token / Sanctum Personal Access Tokens)
|--------------------------------------------------------------------------
| base: /api
| このファイルでは v1 を切って運用する。
| 例: Route::post('/auth/login') => POST /api/v1/auth/login
*/

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Auth (public)
    |--------------------------------------------------------------------------
    */
    Route::post('/auth/login',    [ApiAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/register', [ApiAuthController::class, 'register'])->middleware('throttle:register');

    /*
    |--------------------------------------------------------------------------
    | Auth (protected: Bearer required)
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/auth/me',      [ApiAuthController::class, 'me']);
        Route::post('/auth/logout', [ApiAuthController::class, 'logout']);

        // Today / Week
        Route::get('/today', [TodayController::class, 'show']);
        Route::get('/week',  [WeekController::class, 'show']);

        // Habits / Logs
        Route::post('/habits', [HabitController::class, 'store']);
        Route::post('/habits/{habit}/toggle', [HabitLogController::class, 'toggle']);
    });
});