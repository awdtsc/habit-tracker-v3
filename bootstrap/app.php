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
        | H2: CSP/セキュアヘッダ（XSS耐性の底上げ）
        |
        */
        $middleware->group('web', [
            \App\Http\Middleware\SecurityHeaders::class,
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
        | M3: CORS を明示（別originのSPA配信/将来拡張に備える）
        |
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
            // いったん残してもいいが、Bearer統一なら基本使わない
            // 'auth.api' => \App\Http\Middleware\EnsureApiAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
