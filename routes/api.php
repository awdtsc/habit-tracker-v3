<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\UserInfoController;

/*
|--------------------------------------------------------------------------
| API Routes (Sanctum API)
|--------------------------------------------------------------------------
| ※ Login/Logout/Register は絶対にここに置かない
|    すべて routes/web.php の web middleware に置く
*/

Route::middleware('auth.api')->group(function () {
    Route::get('/user', [UserInfoController::class, 'me']);
});