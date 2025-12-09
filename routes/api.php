<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Token-based APIs)
|--------------------------------------------------------------------------
|
| 現在、SPA はすべて session（web.php）経由で提供しているため、
| このファイルは使用していません。
|
| 将来、モバイルアプリや外部連携向けに「トークン認証の純粋な API」を
| 追加する場合に、このファイルを利用してください。
|
*/

Route::prefix('v1')->group(function () {
    // 例：トークン認証で保護された API を追加する場合
    //
    // Route::middleware('auth:sanctum')->group(function () {
    //     Route::get('/profile', function () {
    //         return ['status' => 'ok'];
    //     });
    // });
});