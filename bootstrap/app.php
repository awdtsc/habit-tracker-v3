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
        | Web Middleware（SPA shell を返すだけ。Cookie/Session/CSRF は使わない）
        |--------------------------------------------------------------------------
        |
        | このプロジェクトは API を Bearer トークンに統一するため、
        | web 側で StartSession / CSRF / Sanctum stateful は不要。
        |
        */
        $middleware->group('web', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | API Middleware（Stateless）
        |--------------------------------------------------------------------------
        |
        | routes/api.php は自動で /api プレフィックスが付く想定。
        | Bearer トークン認証は auth:sanctum をルート側で使用。
        |
        */
        $middleware->group('api', [
            // いまは必要最小限。必要なら throttle 等を足す。
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Alias
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            // いったん残してもいいが、Bearer統一なら基本使わない
            // 'auth.api' => \App\Http\Middleware\EnsureApiAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();