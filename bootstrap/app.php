<?php

use App\Http\Middleware\EnsureLabIntegration;
use App\Http\Middleware\EnsurePlatform;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\UseCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'platform' => EnsurePlatform::class,
            'lab-integration' => EnsureLabIntegration::class,
            'role' => EnsureUserHasRole::class,
            'use-company' => UseCompany::class,
        ]);
        // A ótica é escolhida antes do route model binding (o registro de outra ótica dá 404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: UseCompany::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
