<?php
// app/Http/Controllers/Auth/ApiAuthController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Auth API Controller  (SPA / Sanctum + Session)
|
| ルート:
|   POST /register → register()
|   POST /login    → login()
|   POST /logout   → logout()
|   GET  /user     → me()
|--------------------------------------------------------------------------
*/

class ApiAuthController extends Controller
{
    /** ------------------------
     *  Register
     *  ------------------------ */
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

        return response()->json(['message' => 'Registered'], 201);
    }

    /** ------------------------
     *  Login
     *  ------------------------ */
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

        return response()->json(['message' => 'Logged in']);
    }

    /** ------------------------
     *  Logout
     *  ------------------------ */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    /** ------------------------
     *  Current User
     *  ------------------------ */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}