<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ApiAuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     * body: { email, password, device_name? }
     * return: { token, user }
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'       => ['required', 'string', 'email', 'max:255'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $data['device_name'] ?? 'api';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ]);
    }

    /**
     * POST /api/v1/auth/register
     * body: { name, email, password, device_name? }
     * return: { token, user }
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'string', 'min:8'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $deviceName = $data['device_name'] ?? 'api';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ], 201);
    }

    /**
     * GET /api/v1/auth/me (auth:sanctum)
     * return: { user }
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * POST /api/v1/auth/logout (auth:sanctum)
     * body: { all?: boolean }
     *
     * all=true : 全トークン破棄（全端末ログアウト想定）
     * all=false: 今のトークンだけ破棄（Bearerの場合）
     *
     * Cookieログイン時（currentAccessToken=null）でも必ず落ちずに成功する。
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // 未認証でも冪等にOK
        if (!$user) {
            return response()->json(['ok' => true]);
        }

        $all = $request->boolean('all', false);

        // 1) トークン（Bearer）側の処理
        // Cookieログインでも「過去に発行したトークンを全部消したい(all=true)」はあり得るので対応
        try {
            if ($all) {
                $user->tokens()->delete(); // 全トークン破棄
            } else {
                $token = $user->currentAccessToken();
                if ($token) {
                    $token->delete(); // 今のトークンだけ
                }
            }
        } catch (\Throwable $e) {
            // token削除は失敗してもログアウト処理は続行（冪等優先）
        }

        // 2) セッション（Cookie）側の処理
        // Cookieログインの場合に効く。Bearerだけでも害はない。
        try {
            Auth::guard('web')->logout();
        } catch (\Throwable $e) {
            // ignore
        }

        if (method_exists($request, 'hasSession') && $request->hasSession()) {
            try {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return response()->json(['ok' => true]);
    }
}
