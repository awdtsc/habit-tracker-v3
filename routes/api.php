<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ApiAuthController;

/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth.api')->group(function () {

    // 現在のユーザー取得
    Route::get('/user', [ApiAuthController::class, 'me']);
});