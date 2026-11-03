<?php

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (cookie) authentication for the first-party React app.
        $middleware->statefulApi();
        $middleware->throttleApi('api');
        $middleware->appendToGroup('api', SetLocale::class);
        $middleware->alias(['staff' => EnsureStaff::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => rtrim((string) config('promofy.frontend_url'), '/').'/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
