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
        | Web Middleware
        |--------------------------------------------------------------------------
        | Phase 1（共存）で /auth/cookie/* を検証するため、web には Session/CSRF を戻す。
        | ただし Bearer(/api) は壊さない。
        */
        $middleware->group('web', [
            \App\Http\Middleware\SecurityHeaders::class,

            // Cookie / Session を成立させる最小セット
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,

            // ValidationExceptionなどの共有
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,

            // CSRF（/sanctum/csrf-cookie と /auth/cookie/* のため）
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,

            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | API Middleware（Bearerを維持しつつ Cookie(Sanctum stateful) も通す）
        |--------------------------------------------------------------------------
        | 重要:
        | - Bearer(PAT) はそのまま動く（Authorizationヘッダ）
        | - Cookieログイン済みの場合、Sanctumが「statefulなリクエスト」と判定できれば
        |   auth:sanctum で Cookieセッションでも通せるようになる（Phase 2の入口）
        |
        | NOTE:
        | - StartSession/CSRF は API に入れない（ここでは最小）
        | - Cookieを送るにはフロント側で withCredentials が必要（次ステップ）
        */
        $middleware->group('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
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
