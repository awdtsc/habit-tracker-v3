<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        /*
        |--------------------------------------------------------------------------
        | API Middleware (Sanctum SPA)
        |--------------------------------------------------------------------------
        |
        | Sanctum’s stateful middleware **must be prepended**, so that requests
        | coming from the SPA (localhost:5173 etc.) are treated as "first-party".
        | This prevents unauthenticated API requests from being redirected to
        | the "login" route, and ensures Sanctum cookie-based auth works.
        |
        */
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Alias (Laravel 11+)
        |--------------------------------------------------------------------------
        |
        | auth.api → 未ログイン時に必ず 401 JSON を返す。  
        | Redirect せず、/login を探させない。
        |
        */
        $middleware->alias([
            'auth.api' => \App\Http\Middleware\EnsureApiAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();