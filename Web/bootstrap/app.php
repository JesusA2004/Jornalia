<?php

use App\Domain\Attendance\AttendanceException;
use App\Exceptions\ImmutableRecordException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['organization' => SetCurrentOrganization::class]);

        // La organización activa debe fijarse ANTES de resolver modelos de la URL
        // (route model binding); si no, el scope multiempresa no se aplicaría y
        // habría riesgo de IDOR entre empresas.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentOrganization::class,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // Errores con el formato `ApiError` del contrato (packages/contracts).
        $error = fn (string $code, string $message, int $status, array $extra = []) => response()->json(['message' => $message, 'code' => $code] + $extra, $status);

        $exceptions->render(function (AttendanceException $e, Request $request) use ($isApi, $error) {
            if ($isApi($request)) {
                return $error($e->errorCode, $e->getMessage(), $e->status);
            }

            return back()->withErrors(['punch' => $e->getMessage()]);
        });

        $exceptions->render(function (ImmutableRecordException $e, Request $request) use ($isApi, $error) {
            report($e);

            return $isApi($request)
                ? $error('immutable_record', 'Los marcajes originales no pueden modificarse ni eliminarse. Usa una solicitud de rectificación.', 409)
                : back()->withErrors(['record' => 'Los marcajes originales no pueden modificarse ni eliminarse.']);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi, $error) {
            return $isApi($request) && $request->is('api/*')
                ? $error('validation_failed', $e->getMessage(), $e->status, ['errors' => $e->errors()])
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($error) {
            return $request->is('api/*') ? $error('unauthenticated', 'Sesión no válida o expirada.', 401) : null;
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($error) {
            return $request->is('api/*') ? $error('forbidden', 'No tienes permiso para realizar esta acción.', 403) : null;
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($error) {
            return $request->is('api/*') ? $error('not_found', 'Recurso no encontrado.', 404) : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($error) {
            return $request->is('api/*') ? $error('rate_limited', 'Demasiadas solicitudes. Intenta en un momento.', 429) : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($error) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = $e->getStatusCode();

            return $error($status === 403 ? 'forbidden' : ($status >= 500 ? 'server_error' : 'not_found'), $e->getMessage() ?: 'Error', $status);
        });
    })->create();
