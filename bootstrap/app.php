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
    ->withMiddleware(function (Middleware $middleware) {

        /*
        |--------------------------------------------------------------------------
        | Web Middleware（Sanctum SPA の正しい順序）
        |--------------------------------------------------------------------------
        */
        $middleware->group('web', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,

            // ★ Sanctum SPA ここ（stateful domain 判定）
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,

            // ★ Session は CSRF より前で OK
            \Illuminate\Session\Middleware\StartSession::class,

            // CSRF
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,

            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | API Middleware（Stateless）
        |--------------------------------------------------------------------------
        */
        $middleware->group('api', [
            // API は基本 stateless（Cookie認証はしない）
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Alias
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            'auth.api' => \App\Http\Middleware\EnsureApiAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();