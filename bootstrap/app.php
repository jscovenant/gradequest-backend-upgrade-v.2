<?php

use App\Http\Middleware\CustomRole;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\ResolveSchoolFromDomain;
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
        // ❌ REMOVE THIS LINE - it causes CSRF on API routes
        // $middleware->statefulApi();

        // ✅ ADD THIS - Exclude API routes from CSRF
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->append(ResolveSchoolFromDomain::class);



        // Register global middleware (runs for every request)
        // $middleware->append(CorsMiddleware::class);

        // Define aliases for use in routes
        $middleware->alias([
            'custormrole'       => CustomRole::class,
            'role'              => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'        => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission'=> \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'customdomain'      => \App\Http\Middleware\CustomDomain::class, 
            'cors'              => CorsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();