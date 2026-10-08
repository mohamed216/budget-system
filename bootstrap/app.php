<?php

use App\Accounting\Exceptions\AccountingConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Preserve exact journal/opening-balance amounts and fiscal-year-close input values.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('accounting/journals', 'accounting/journals/*', 'accounting/pages/journals', 'accounting/pages/journals/*', 'accounting/opening-balances', 'accounting/opening-balances/*', 'accounting/pages/opening-balances', 'accounting/pages/opening-balances/*')
            || ($request->isMethod('POST') && $request->is('accounting/fiscal-year-closes', 'accounting/pages/fiscal-year-closes'))]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception) => $request->routeIs('accounting.*') || $request->expectsJson());
        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->routeIs('accounting-pages.*')) {
                return response()->view('accounting.error', [], 500);
            }
            if ($request->routeIs('accounting.opening-balances.*')) {
                return response()->json(['message' => 'Opening balance request could not be completed.'], 500);
            }
            if ($request->routeIs('accounting.fiscal-year-closes.*')) {
                return response()->json(['message' => 'Fiscal-year close request could not be completed.'], 500);
            }

            return null;
        });
        $exceptions->render(function (AccountingConflict $exception, Request $request) {
            if ($request->routeIs('accounting.*')) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return null;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->routeIs('accounting.fiscal-year-closes.*', 'accounting-pages.fiscal-year-closes.*')
                || $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof HttpExceptionInterface) {
                return null;
            }

            if ($request->routeIs('accounting-pages.fiscal-year-closes.*')) {
                return response()->view('accounting.error', [], 500);
            }

            return response()->json(['message' => 'Fiscal-year close request could not be completed.'], 500);
        });
    })->create();
