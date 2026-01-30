<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureApiAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        // ユーザーが取れない → 未ログイン → 401 JSON を返す
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return $next($request);
    }
}