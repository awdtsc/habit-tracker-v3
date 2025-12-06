<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class ApiAuthController extends Controller
{
    /**
     * ------------------------------------------------------------
     * Register (web middleware)
     * ------------------------------------------------------------
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:50'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        // SPA は /api/user を見にいくので、userデータは返さない
        return response()->json(['message' => 'Registered'], 201);
    }

    /**
     * ------------------------------------------------------------
     * Login (web middleware)
     * ------------------------------------------------------------
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials, true)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $request->session()->regenerate();

        // userデータは返さず、成功のみ返す（SPAは/api/userを参照）
        return response()->json(['message' => 'Logged in']);
    }

    /**
     * ------------------------------------------------------------
     * Logout (web middleware)
     * ------------------------------------------------------------
     */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * ------------------------------------------------------------
     * Me（SPAの認証ソース）
     * ------------------------------------------------------------
     * Routerのガードが毎回これで認証状態を判断する。
     * ------------------------------------------------------------
     */
    public function me(Request $request)
    {
        // ★形式を統一：必ず「userモデルそのまま」を返す
        // Laravel Breeze や Jetstream と完全互換
        return response()->json($request->user());
    }
}