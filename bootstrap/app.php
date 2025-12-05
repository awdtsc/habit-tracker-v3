<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        /*
        |--------------------------------------------------------------------------
        | Web Middleware
        |--------------------------------------------------------------------------
        */
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | API Middleware（Sanctum）
        |--------------------------------------------------------------------------
        | ※ 公式通り「prepend」で入れることが重要！
        */
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Alias（Laravel 11+）
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            'auth.api' => \App\Http\Middleware\EnsureApiAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();