<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Token-based API 用 — 現在は未使用)
|--------------------------------------------------------------------------
|
| このアプリの SPA は「session + Sanctum」で認証しているため、
| /api/* のエンドポイントはすべて routes/web.php で提供しています。
|
| → 理由：
|   api.php に置くと "api" ミドルウェアが適用されてしまい、
|   StartSession が動かず、Sanctum の session 認証が効かないため。
|
| 将来、外部アプリ・モバイルアプリ向けに
| 「Token-based API（auth:sanctum or API tokens）」を追加する場合、
| こちらにルートを記述してください。
|
*/

Route::prefix('v1')->group(function () {

    // 例（将来用）:
    // Route::middleware('auth:sanctum')->get('/profile', function () {
    //     return ['status' => 'ok'];
    // });

});