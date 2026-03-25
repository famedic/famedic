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
                // Monitoreo y herramientas internas solo para administradores
                [
                    'label' => 'Monitoreo',
                    'icon' => 'ClipboardDocumentListIcon',
                    'items' => array_values(array_filter([
                        $request->user()->administrator->hasPermissionTo('logs-general.manage') ? [
                            'label' => 'Logs generales',
                            'url' => route('admin.logs-general.manage'),
                            'current' => Route::currentRouteName() === 'admin.logs-general.manage',
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('users.manage') ? [
                            'label' => 'Usuarios',
                            'url' => route('admin.users.index'),
                            'current' => Route::currentRouteName() === 'admin.users.index'
                                || Route::currentRouteName() === 'admin.users.show',
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('efevoo-tokens.manage') ? [
                            'label' => 'Tokens Efevoo',
                            'url' => route('admin.efevoo-tokens.index'),
                            'current' => Route::currentRouteName() === 'admin.efevoo-tokens.index'
                                || Route::currentRouteName() === 'admin.efevoo-tokens.show',
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('tax-profiles.manage') ? [
                            'label' => 'Perfiles fiscales',
                            'url' => route('admin.tax-profiles.index'),
                            'current' => Route::currentRouteName() === 'admin.tax-profiles.index'
                                || Route::currentRouteName() === 'admin.tax-profiles.show',
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('payment-attempts.manage') ? [
                            'label' => 'Intentos de pago',
                            'url' => route('admin.payment-attempts.index'),
                            'current' => Route::currentRouteName() === 'admin.payment-attempts.index'
                                || Route::currentRouteName() === 'admin.payment-attempts.show',
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') ? [
                            'label' => 'Monitor notificaciones lab',
                            'url' => route('admin.laboratory-notifications-monitor.index'),
                            'current' => Route::currentRouteName() === 'admin.laboratory-notifications-monitor.index'
                                || Route::currentRouteName() === 'admin.laboratory-notifications-monitor.show',
                        ] : null,
                        $request->user()->administrator->roles()->where('roles.id', 1)->exists() ? [
                            'label' => 'Murguía — asegurados',
                            'url' => route('admin.murguia-monitor.index'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.murguia'),
                        ] : null,
                    ])),
                ],
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
