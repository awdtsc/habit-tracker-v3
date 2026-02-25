<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ApiTokenAuthController extends Controller
{
    /**
     * POST /api/v1/auth/token/login
     * body: { email, password, device_name }
     * return: { ok, token, user }
     *
     * NOTE:
     * - モバイル/外部クライアント向け（Bearer Token 発行専用）
     * - device_name は必須（端末識別）
     * - 失敗時も必ず JSON を返す（リダイレクトしない）
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'email'       => ['required', 'string', 'email', 'max:255'],
                'password'    => ['required', 'string'],
                'device_name' => ['required', 'string', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            // ★認証失敗は 401 に寄せる（APIらしい）
            return response()->json([
                'ok' => false,
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 200);
    }

    /**
     * POST /api/v1/auth/token/register
     * body: { name, email, password, device_name }
     * return: { ok, token, user }
     *
     * NOTE:
     * - モバイル/外部クライアント向け（Bearer Token 発行専用）
     * - device_name は必須（端末識別）
     * - 失敗時も必ず JSON を返す（リダイレクトしない）
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name'        => ['required', 'string', 'max:255'],
                'email'       => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password'    => ['required', 'string', 'min:8'],
                'device_name' => ['required', 'string', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }
}
