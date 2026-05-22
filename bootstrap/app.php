<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Public REST API middleware alias — Bearer token auth + rate limit.
        // Accepts an optional scope string: ->middleware('api.token:cortex:discuss').
        $middleware->alias([
            'api.token' => \App\Http\Middleware\AuthenticateApiToken::class,
        ]);

        // The leave endpoint is hit via navigator.sendBeacon, which cannot
        // attach a CSRF token. It only pauses the caller's own chat.
        // The Paddle webhook is signed via HMAC in the controller, not CSRF —
        // Paddle's POST has no session cookie to issue a token against.
        $middleware->validateCsrfTokens(except: [
            'chats/*/leave',
            'paddle/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
