<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 運用監視の取込 API は HMAC 認証（セッション外）のため CSRF 検証を除外する
        $middleware->validateCsrfTokens(except: ['internal/monitor/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
