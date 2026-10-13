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
        // === AKTIFKAN SANCTUM SPA AUTH (COOKIE) DI SINI ===
        $middleware->statefulApi();

        // === API IS JSON-FIRST: guests must get HTTP 401, never a redirect ===
        // Laravel's default is `->redirectGuestsTo(fn () => route('login'))`,
        // but this SPA has no named `login` route (the login page lives in the
        // Next.js frontend). Returning null for API requests makes the auth
        // middleware render a controlled JSON 401 instead of throwing
        // RouteNotFoundException. This does NOT disable Sanctum/session/CSRF.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : '/login');

        // === DAFTARKAN ALIAS MIDDLEWARE ROLE DI SINI ===
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();