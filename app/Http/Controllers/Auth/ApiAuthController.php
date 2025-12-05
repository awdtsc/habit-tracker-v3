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
     * ※ register は session を使うため web.php 配下で定義している。
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:50'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // Create user
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Immediately log in user
        Auth::login($user);

        // Sanctum SPA 認証では session regenerate が必須
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Registered & Logged in',
            'user'    => $user,
        ], 201);
    }

    /**
     * ------------------------------------------------------------
     * Login (API)
     * ------------------------------------------------------------
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials, true)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        // 必ず regenerate を実行（セキュリティ＋SPA 認証維持）
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Logged in',
            'user'    => Auth::user(),
        ]);
    }

    /**
     * ------------------------------------------------------------
     * Logout (API)
     * ------------------------------------------------------------
     */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        // Session invalidate & regenerate token
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out'
        ]);
    }
}