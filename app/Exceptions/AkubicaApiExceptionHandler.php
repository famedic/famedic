<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class AkubicaApiExceptionHandler
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! static::isAkubicaApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'UNAUTHENTICATED',
                'Token inválido o expirado.',
                401,
            );
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! static::isAkubicaApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'FORBIDDEN',
                $e->getMessage() ?: 'No tienes permiso para acceder a este recurso.',
                403,
            );
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! static::isAkubicaApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Los datos enviados no son válidos.',
                422,
                $e->errors(),
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! static::isAkubicaApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'NOT_FOUND',
                'Recurso no encontrado.',
                404,
            );
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! static::isAkubicaApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'TOO_MANY_REQUESTS',
                'Demasiadas solicitudes. Intenta de nuevo en unos minutos.',
                429,
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! static::isAkubicaApiRequest($request)) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403) {
                return ApiResponse::error(
                    'FORBIDDEN',
                    $e->getMessage() ?: 'No tienes permiso para acceder a este recurso.',
                    403,
                );
            }

            if (config('app.debug')) {
                return null;
            }

            return ApiResponse::error(
                'INTERNAL_ERROR',
                'Error interno del servidor.',
                500,
            );
        });
    }

    public static function isAkubicaApiRequest(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }
}
