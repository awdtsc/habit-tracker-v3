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
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
