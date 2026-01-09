<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA entry
|--------------------------------------------------------------------------
| Vue Router(history mode) のため、/login /today /week などは
| すべて同じ Blade(view('app')) を返す。
|
| ただし /api/* は API ルート(api.php)に任せるので除外する。
*/

// ★これを追加：Laravel が route('login') を要求しても 500 にならない
Route::view('/login', 'app')->name('login');

// 既存の SPA catch-all（/api は除外）
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');