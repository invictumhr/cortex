<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The leave endpoint is hit via navigator.sendBeacon, which cannot
        // attach a CSRF token. It only pauses the caller's own chat.
        $middleware->validateCsrfTokens(except: [
            'chats/*/leave',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
