<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        // Guests are never redirected from the API: there is no login route on
        // the backend (the SPA owns /login), and building that redirect threw
        // "Route [login] not defined", which the API reported as a 500 to any
        // request without Accept: application/json (#58). With no redirect,
        // the AuthenticationException handler below answers 401 JSON.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ensure API requests always receive JSON and no internal leakage
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });

        // Must be handled explicitly: AuthenticationException is not an
        // HttpException, so the catch-all below would report an expired or
        // missing token as a 500. The SPA keys its auto-logout off a 401
        // (see frontend/src/lib/api/client.js), so a 500 here left users
        // staring at "an internal error occurred" instead of the login screen.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            if ($e instanceof ValidationException
                || $e instanceof NotFoundHttpException
                || $e instanceof AuthenticationException
            ) {
                return null;
            }
            $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
            return response()->json([
                'message' => $status >= 500 ? 'An internal error occurred.' : $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], $status);
        });
    })->create();
