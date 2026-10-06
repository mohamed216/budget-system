<?php

use App\Accounting\Exceptions\AccountingConflict;
use Illuminate\Database\QueryException;
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
        // Preserve exact decimal input syntax on journal endpoints.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('accounting/journals', 'accounting/journals/*', 'accounting/pages/journals', 'accounting/pages/journals/*')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception) => $request->routeIs('accounting.*') || $request->expectsJson());
        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->routeIs('accounting-pages.*')) {
                return response()->view('accounting.error', [], 500);
            }

            return null;
        });
        $exceptions->render(function (AccountingConflict $exception, Request $request) {
            if ($request->routeIs('accounting.*')) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return null;
        });
    })->create();
