<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        /*
        |--------------------------------------------------------------------------
        | Web Middleware（SPA shell を返すだけ。Cookie/Session/CSRF は使わない）
        |--------------------------------------------------------------------------
        */
        $middleware->group('web', [
            \App\Http\Middleware\SecurityHeaders::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | API Middleware（Stateless）
        |--------------------------------------------------------------------------
        */
        $middleware->group('api', [
            \Illuminate\Http\Middleware\HandleCors::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Alias
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            // 'auth.api' => \App\Http\Middleware\EnsureApiAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ★重要：/api/* は Accept ヘッダに依存せず常に JSON 401（302/HTML を禁止）
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return null; // web はデフォルト挙動に任せる（/login redirect 等）
        });
    })
    ->create();
