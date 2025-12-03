<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes (Habit Tracker v3)
|--------------------------------------------------------------------------
| SPA（Vue Router）を基盤に置き、Laravel は初期ロードのみ担当。
| Inertia は「最初の HTML ページを返す」最低限のルートだけにする。
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => Inertia::render('Today'))
    ->middleware(['auth', 'verified'])
    ->name('today');

/*
|--------------------------------------------------------------------------
| 認証後に表示される Habit Tracker 各画面
|--------------------------------------------------------------------------
| これらは Vue Router が内部遷移するため、Inertia::render で
| 空のコンテナページを返すだけ。
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/today', fn () => Inertia::render('Today'))->name('today');

    Route::get('/weekly', fn () => Inertia::render('Weekly'))->name('weekly');

    Route::get('/habits', fn () => Inertia::render('Habits'))->name('habits');

    Route::get('/reminders', fn () => Inertia::render('Reminders'))->name('reminders');
});

/*
|--------------------------------------------------------------------------
| Breeze のプロフィール画面（維持したい場合だけ）
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::get('/profile', fn () => Inertia::render('Profile/Edit'))
        ->name('profile.edit');
});