<?php

use Illuminate\Support\Facades\Route;

// v3では APIルートは Habit / Log / Weekly など個別機能用。
// 認証系は一切置かない。

Route::middleware('auth:sanctum')->group(function () {
    //
    // 例：習慣 CRUD、ログ記録、週間APIなど
    //
});