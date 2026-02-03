<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\ApiAuthController;
use App\Http\Controllers\Auth\ApiTokenAuthController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\WeekController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\HabitLogController;

// ★Push
use App\Http\Controllers\Api\V1\PushSubscriptionController;

// ★Reminders
use App\Http\Controllers\Api\V1\ReminderController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| base: /api
| v1:  /api/v1/*
|
| 方針（モバイル運用前提）:
| - SPA（ブラウザ）は Cookie/Session + CSRF を正とする（web側 /auth/cookie/*）
| - モバイル等の外部クライアントは Bearer Token（Sanctum PAT）を正とする
|   => token発行は /api/v1/auth/token/* のみ
| - 保護は auth:sanctum で統一（cookieでもtokenでも通る）
*/

Route::prefix('v1')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Auth (public) - Token issuance for Mobile/External clients
    |----------------------------------------------------------------------
    | device_name は ApiTokenAuthController 側で必須
    */
    Route::post('/auth/token/login',    [ApiTokenAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/token/register', [ApiTokenAuthController::class, 'register'])->middleware('throttle:register');

    /*
    |----------------------------------------------------------------------
    | Protected routes (auth:sanctum)
    |----------------------------------------------------------------------
    | cookieでもtokenでも通る
    */
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/auth/me',      [ApiAuthController::class, 'me']);
        Route::post('/auth/logout', [ApiAuthController::class, 'logout']);

        // Today / Week（ダッシュボード）
        Route::get('/today', [TodayController::class, 'show']);
        Route::get('/week',  [WeekController::class, 'show']);

        // Habits
        Route::post('/habits', [HabitController::class, 'store']);
        Route::get('/habits/{habit}', [HabitController::class, 'show']);
        Route::put('/habits/{habit}', [HabitController::class, 'update']);

        // Logs（toggle）
        Route::post('/habits/{habit}/toggle', [HabitLogController::class, 'toggle']);

        /*
        |----------------------------------------------------------------------
        | Reminders (protected)
        |----------------------------------------------------------------------
        | - 子レコード方式（parent_task_id / root_task_id）前提
        | - 通知クリックからの操作（snooze/done/cancel）
        */
        Route::post('/reminders/{task}/snooze', [ReminderController::class, 'snooze'])->whereNumber('task');
        Route::post('/reminders/{task}/done',   [ReminderController::class, 'done'])->whereNumber('task');
        Route::post('/reminders/{task}/cancel', [ReminderController::class, 'cancel'])->whereNumber('task');

        /*
        |----------------------------------------------------------------------
        | Push (protected)
        |----------------------------------------------------------------------
        */
        Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe']);
        Route::post('/push/test', [PushSubscriptionController::class, 'test']);
    });
});