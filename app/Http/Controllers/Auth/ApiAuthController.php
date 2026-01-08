<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
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
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * POST /api/v1/auth/logout (auth:sanctum)
     * body: { all?: boolean }
     *
     * all=true: 全トークン破棄（全端末ログアウト）
     * all=false: 今のトークンだけ破棄
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $all = (bool) $request->input('all', false);

        if ($all) {
            $user->tokens()->delete();
        } else {
            $token = $user->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        return response()->json(['ok' => true]);
    }
}