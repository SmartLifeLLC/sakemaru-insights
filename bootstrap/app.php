<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ELB の後ろで動くため X-Forwarded-* を信頼する（これが無いと資産 URL が http:// になり、HTTPS ページで読み込まれない）
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB);
        // 運用監視の取込 API は HMAC 認証（セッション外）のため CSRF 検証を除外する
        $middleware->validateCsrfTokens(except: ['internal/monitor/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
