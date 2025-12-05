<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ApiAuthController;
use App\Http\Controllers\Auth\UserInfoController;

/*
|--------------------------------------------------------------------------
| Public Auth Routes (API)
|--------------------------------------------------------------------------
*/
Route::post('/login',  [ApiAuthController::class, 'login']);
Route::post('/logout', [ApiAuthController::class, 'logout']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth.api')->group(function () {
    Route::get('/user', [UserInfoController::class, 'me']);
});