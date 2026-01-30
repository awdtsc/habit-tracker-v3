<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ApiTokenAuthController extends Controller
{
    /**
     * POST /api/v1/auth/token/login
     * body: { email, password, device_name }
     * return: { token, user }
     *
     * NOTE:
     * - モバイル/外部クライアント向け（Bearer Token 発行専用）
     * - device_name は必須（端末識別）
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'       => ['required', 'string', 'email', 'max:255'],
            'password'    => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ]);
    }

    /**
     * POST /api/v1/auth/token/register
     * body: { name, email, password, device_name }
     * return: { token, user }
     *
     * NOTE:
     * - モバイル/外部クライアント向け（Bearer Token 発行専用）
     * - device_name は必須（端末識別）
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'string', 'min:8'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ], 201);
    }
}
