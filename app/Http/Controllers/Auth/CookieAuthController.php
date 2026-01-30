<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CookieAuthController extends Controller
{
    /**
     * POST /auth/cookie/login
     * - Cookie(Session) ログイン
     * - Bearer(PAT) は一切触らない（共存）
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim($data['email']));

        $ok = Auth::guard('web')->attempt([
            'email'    => $email,
            'password' => $data['password'],
        ], false);

        if (!$ok) {
            // 302にしない。JSON 422（ValidationException）に寄せる。
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // セッション固定攻撃対策
        $request->session()->regenerate();

        return response()->json([
            'user' => $request->user('web'),
        ]);
    }

    /**
     * POST /auth/cookie/register
     * - Cookie(Session) 登録 + 即ログイン
     * - Bearer(PAT) は一切触らない（共存）
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $email = strtolower(trim($data['email']));

        // fillable 依存を避けて明示代入（事故回避）
        $user = new User();
        $user->name = $data['name'];
        $user->email = $email;
        $user->password = Hash::make($data['password']);
        $user->save();

        Auth::guard('web')->login($user, false);

        // セッション固定攻撃対策
        $request->session()->regenerate();

        return response()->json([
            'user' => $request->user('web'),
        ], 201);
    }

    /**
     * GET /auth/cookie/me
     * - 302にしない。未認証は JSON 401。
     */
    public function me(Request $request)
    {
        $user = $request->user('web');
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json(['user' => $user]);
    }

    /**
     * POST /auth/cookie/logout
     * - 302にしない。未認証でも 200でOK（冪等）
     */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        // セッション破棄 + CSRFトークン再生成
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
