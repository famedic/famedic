<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasAdminAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->administrator) {
            abort(404);
        }

        Inertia::share([
            'adminNavigation' => [
                [
                    'label' => 'Resumen',
                    'url' => route('admin.admin'),
                    'icon' => 'PresentationChartLineIcon',
                    'current' => Route::currentRouteName() === 'admin.admin',
                ],
                ...$request->user()->administrator->hasPermissionTo('administrators.manage') ? [[
                    'label' => 'Personal y permisos',
                    'icon' => 'UsersIcon',
                    'items' => [
                        [
                            'label' => 'Administradores',
                            'url' => route('admin.administrators.index'),
                            'current' => Route::currentRouteName() === 'admin.administrators.index' ||
                                Route::currentRouteName() === 'admin.administrators.create' ||
                                Route::currentRouteName() === 'admin.administrators.edit',
                        ],
                        [
                            'label' => 'Roles y permisos',
                            'url' => route('admin.roles.index'),
                            'current' => Route::currentRouteName() === 'admin.roles.index' ||
                                Route::currentRouteName() === 'admin.roles.create' ||
                                Route::currentRouteName() === 'admin.roles.edit',
                        ],
                    ],
                ]] : [],
                [
                    'label' => 'Laboratorios',
                    'icon' => 'BeakerIcon',
                    'items' => array_values(array_filter([
                        $request->user()->administrator->hasPermissionTo('laboratory-purchases.manage') ? [
                            'label' => 'Pedidos',
                            'url' => route('admin.laboratory-purchases.index'),
                            'current' => Route::currentRouteName() === 'admin.laboratory-purchases.index' ||
                                Route::currentRouteName() === 'admin.laboratory-purchases.show',
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('laboratory-tests.manage') ? [
                            'label' => 'Catálogo de estudios',
                            'url' => route('admin.laboratory-tests.index'),
                            'current' => Route::currentRouteName() === 'admin.laboratory-tests.index' ||
                                Route::currentRouteName() === 'admin.laboratory-tests.create' ||
                                Route::currentRouteName() === 'admin.laboratory-tests.edit',
                        ] : null,
                        $request->user()->administrator->laboratoryConcierge ? [
                            'label' => 'Citas',
                            'url' => route('admin.laboratory-appointments.index'),
                            'current' => Route::currentRouteName() === 'admin.laboratory-appointments.index' ||
                                Route::currentRouteName() === 'admin.laboratory-appointments.show',
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('laboratory-purchases.manage.vendor-payments') ? [
                            'label' => 'Pagos a GDA',
                            'url' => route('admin.laboratory-purchases.vendor-payments.index'),
                            'current' => Route::currentRouteName() === 'admin.laboratory-purchases.vendor-payments.index' ||
                                Route::currentRouteName() === 'admin.laboratory-purchases.vendor-payments.create' ||
                                Route::currentRouteName() === 'admin.laboratory-purchases.vendor-payments.show' ||
                                Route::currentRouteName() === 'admin.laboratory-purchases.vendor-payments.edit',
                        ] : null,
                    ])),
                ],
                [
                    'label' => 'Farmacia',
                    'icon' => 'BuildingStorefrontIcon',
                    'items' => array_values(array_filter([
                        $request->user()->administrator->hasPermissionTo('online-pharmacy-purchases.manage') ? [
                            'label' => 'Pedidos',
                            'url' => route('admin.online-pharmacy-purchases.index'),
                            'current' => Route::currentRouteName() === 'admin.online-pharmacy-purchases.index' ||
                                Route::currentRouteName() === 'admin.online-pharmacy-purchases.show',
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('online-pharmacy-purchases.manage.vendor-payments') ? [
                            'label' => 'Pagos a Vitau',
                            'url' => route('admin.online-pharmacy-purchases.vendor-payments.index'),
                            'current' => Route::currentRouteName() === 'admin.online-pharmacy-purchases.vendor-payments.index' ||
                                Route::currentRouteName() === 'admin.online-pharmacy-purchases.vendor-payments.create' ||
                                Route::currentRouteName() === 'admin.online-pharmacy-purchases.vendor-payments.show' ||
                                Route::currentRouteName() === 'admin.online-pharmacy-purchases.vendor-payments.edit',
                        ] : null,
                    ])),
                ],
                ...$request->user()->administrator->hasPermissionTo('medical-attention-subscriptions.manage') ? [[
                    'label' => 'Membresías médicas',
                    'url' => route('admin.medical-attention-subscriptions.index'),
                    'icon' => 'HeartIcon',
                    'current' => Route::currentRouteName() === 'admin.medical-attention-subscriptions.index' ||
                        Route::currentRouteName() === 'admin.medical-attention-subscriptions.show',
                ]] : [],
                ...$request->user()->administrator->hasPermissionTo('customers.manage') ? [[
                    'label' => 'Clientes',
                    'url' => route('admin.customers.index'),
                    'icon' => 'UserGroupIcon',
                    'current' => Route::currentRouteName() === 'admin.customers.index' ||
                        Route::currentRouteName() === 'admin.customers.show',
                ]] : [],
                ...$request->user()->administrator->hasPermissionTo('documentation.manage') ? [[
                    'label' => 'Documentación legal',
                    'url' => route('admin.documentation'),
                    'icon' => 'BookOpenIcon',
                    'current' => Route::currentRouteName() === 'admin.documentation',
                ]] : [],
            ],
            'adminUserNavigation' => [
                [
                    'label' => 'Regresar a Famedic',
                    'url' => route('home'),
                    'icon' => 'ArrowLeftEndOnRectangleIcon',
                    'current' => false,
                ],
            ],
        ]);

        return $next($request);
    }
}
