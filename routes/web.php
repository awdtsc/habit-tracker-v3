<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\CookieAuthController;

/*
|--------------------------------------------------------------------------
| SPA entry
|--------------------------------------------------------------------------
| Vue Router(history mode) のため、/login /today /week などは
| すべて同じ Blade(view('app')) を返す。
|
| ただし /api/* は API ルート(api.php)に任せるので除外する。
|
| Phase 1（共存）:
| - Bearer(/api/v1/*) は一切触らない
| - Cookie(Session)ログイン検証用に /auth/cookie/* を追加
| - catch-all より前に定義して飲まれないようにする
*/

// ★これを追加：Laravel が route('login') を要求しても 500 にならない
Route::view('/login', 'app')->name('login');

/*
|--------------------------------------------------------------------------
| Cookie Auth (Phase 1 coexist / testing only)
|--------------------------------------------------------------------------
| - Bearer(PAT) はそのまま維持
| - Cookie(Session)認証はここで検証（Axios側はフラグONのときだけ叩く想定）
| - 302リダイレクトに依存しないよう、Controller側でJSONを返す設計
*/
Route::prefix('auth/cookie')->group(function () {
    Route::post('/login',  [CookieAuthController::class, 'login']);
    Route::post('/logout', [CookieAuthController::class, 'logout']);
    Route::get('/me',      [CookieAuthController::class, 'me']);
});

// 既存の SPA catch-all（/api は除外）
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
