<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\ApiAuthController;
use App\Http\Controllers\Auth\ApiTokenAuthController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\WeekController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\HabitLogController;

// Push
use App\Http\Controllers\Api\V1\PushSubscriptionController;

// Reminders
use App\Http\Controllers\Api\V1\ReminderController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| base: /api
| v1:   /api/v1/*
|
| 認証方針（運用前提）:
| - SPA（ブラウザ）は Cookie/Session + CSRF を正とする（same-origin）
| - 外部クライアント（モバイル等）は Bearer Token（Sanctum PAT）を正とする
| - ただし API の保護は auth:sanctum に統一（cookie でも token でも通る）
|
| 重要:
| - 未認証は 401 JSON を返す（/login へ 302 しない設計が望ましい）
| - Push は「購読/解除/テスト」の運用事故（スパム/負荷）を避けるため
|   最低限の throttle を付与する
*/

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Token Auth (for external clients)
    |--------------------------------------------------------------------------
    | - Bearer Token（Sanctum Personal Access Token）発行専用
    | - ここは未認証で叩ける必要があるため auth:sanctum を付けない
    | - ただしブルートフォース対策として throttle を付ける
    */
    Route::post('/auth/token/login',    [ApiTokenAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/token/register', [ApiTokenAuthController::class, 'register'])->middleware('throttle:register');

    /*
    |--------------------------------------------------------------------------
    | Authenticated API (cookie or token)
    |--------------------------------------------------------------------------
    | - ここ以下は必ず認証が必要（auth:sanctum）
    | - ブラウザSPAは Cookie (XSRF) で通る
    | - 外部クライアントは Authorization: Bearer <token> で通る
    */
    Route::middleware('auth:sanctum')->group(function () {

        /*
        |----------------------------------------------------------------------
        | Auth session info
        |----------------------------------------------------------------------
        */
        Route::get('/auth/me',      [ApiAuthController::class, 'me']);
        Route::post('/auth/logout', [ApiAuthController::class, 'logout']);

        /*
        |----------------------------------------------------------------------
        | Pages data endpoints
        |----------------------------------------------------------------------
        | - SPAの表示用データ（Today / Week）
        */
        Route::get('/today', [TodayController::class, 'show']);
        Route::get('/week',  [WeekController::class, 'show']);

        /*
        |----------------------------------------------------------------------
        | Habits
        |----------------------------------------------------------------------
        | - store/show/update
        | - toggle は「今日の実行状態」をトグルするAPI
        */
        Route::post('/habits', [HabitController::class, 'store']);
        Route::get('/habits/{habit}', [HabitController::class, 'show']);
        Route::put('/habits/{habit}', [HabitController::class, 'update']);

        Route::post('/habits/{habit}/toggle', [HabitLogController::class, 'toggle']);

        /*
        |----------------------------------------------------------------------
        | Reminders
        |----------------------------------------------------------------------
        | - task は remind_tasks の id を想定（数値のみ許可）
        | - UI（モーダル）から done/cancel/snooze を叩く運用
        */
        Route::post('/reminders/{task}/snooze', [ReminderController::class, 'snooze'])->whereNumber('task');
        Route::post('/reminders/{task}/done',   [ReminderController::class, 'done'])->whereNumber('task');
        Route::post('/reminders/{task}/cancel', [ReminderController::class, 'cancel'])->whereNumber('task');

        /*
        |----------------------------------------------------------------------
        | Push (Web Push subscriptions)
        |----------------------------------------------------------------------
        | 目的:
        | - 購読（subscribe）: ブラウザの PushSubscription を DB へ保存
        | - 解除（unsubscribe）: DB とブラウザ側の購読を解除
        | - テスト送信（test）: 運用時の疎通確認（※本番では無効化するのが安全）
        | - VAPID公開鍵（vapid-public）: フロントとのキー不一致を検知する
        |
        | 運用事故防止:
        | - 認証済みユーザーでも連打/悪用で負荷を出せるため
        |   最低限の rate limit を付与する
        |
        | throttle 例:
        | - subscribe/unsubscribe/vapid-public: 30 req / 1 min
        | - test: 10 req / 1 min
        */
        Route::post('/push/subscribe',   [PushSubscriptionController::class, 'subscribe'])->middleware('throttle:30,1');
        Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe'])->middleware('throttle:30,1');
        Route::post('/push/test',        [PushSubscriptionController::class, 'test'])->middleware('throttle:10,1');
        Route::get('/push/vapid-public', [PushSubscriptionController::class, 'vapidPublic'])->middleware('throttle:30,1');
    });
});