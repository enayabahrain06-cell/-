<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [SetLocale::class]);
        // Behind the nginx / TLS proxy of the Docker stack (TRUSTED_PROXIES=* there); off when unset.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always answer JSON on /api, in the request locale.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('api.unauthenticated')], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('api.forbidden')], 403);
            }
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('api.not_found')], 404);
            }
        });
    })->create();
