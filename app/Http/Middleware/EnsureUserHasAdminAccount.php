<?php

namespace App\Http\Middleware;

use App\Services\CouponAuthorizationInboxService;
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

        $administrator = $request->user()->administrator;
        $isAuthorizer = $administrator->hasRole('autorizador');
        $pendingAuthorizationsCount = 0;

        if ($isAuthorizer) {
            $pendingAuthorizationsCount = app(CouponAuthorizationInboxService::class)
                ->actionableCountFor($request->user());
        }

        Inertia::share([
            'couponAuthorizerNav' => [
                'is_authorizer' => $isAuthorizer,
                'pending_actionable_count' => $pendingAuthorizationsCount,
                'inbox_url' => $isAuthorizer ? route('admin.coupons.authorizations.index') : null,
            ],
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
                                Route::currentRouteName() === 'admin.laboratory-purchases.chart' ||
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
                        $request->user()->administrator->laboratoryConcierge ? [
                            'label' => 'Métricas de citas',
                            'url' => route('admin.laboratory-appointments.metrics'),
                            'current' => Route::currentRouteName() === 'admin.laboratory-appointments.metrics',
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
                ...($request->user()->administrator->hasPermissionTo('coupons.manage') || $isAuthorizer) ? [[
                    'label' => 'Créditos a favor',
                    'icon' => 'BanknotesIcon',
                    'disabled' => (bool) config('famedic.admin_coupons_navigation_disabled', false),
                    'items' => [
                        [
                            'label' => 'Beneficiarios',
                            'url' => route('admin.coupons.beneficiaries.index'),
                            'current' => Route::currentRouteName() === 'admin.coupons.beneficiaries.index'
                                || Route::currentRouteName() === 'admin.coupons.beneficiaries.export',
                        ],
                        [
                            'label' => 'Códigos promocionales',
                            'url' => route('admin.coupons.promo-codes.index'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.coupons.promo-codes.'),
                        ],
                        ...($isAuthorizer ? [[
                            'label' => $pendingAuthorizationsCount > 0
                                ? "Pendientes de autorización ({$pendingAuthorizationsCount})"
                                : 'Pendientes de autorización',
                            'url' => route('admin.coupons.authorizations.index'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.coupons.authorizations.'),
                        ]] : []),
                        [
                            'label' => 'Créditos',
                            'url' => route('admin.coupons.index'),
                            'current' => Route::currentRouteName() === 'admin.coupons.index'
                                || Route::currentRouteName() === 'admin.coupons.create'
                                || Route::currentRouteName() === 'admin.coupons.edit'
                                || Route::currentRouteName() === 'admin.coupons.show'
                                || Route::currentRouteName() === 'admin.coupons.assign'
                                || Route::currentRouteName() === 'admin.coupons.import',
                        ],
                        [
                            'label' => 'Crear crédito',
                            'url' => route('admin.coupons.create'),
                            'current' => Route::currentRouteName() === 'admin.coupons.create',
                        ],
                        [
                            'label' => 'Configuración',
                            'url' => route('admin.coupons.settings'),
                            'current' => Route::currentRouteName() === 'admin.coupons.settings'
                                && $request->query('tab') !== 'concepts',
                        ],
                        [
                            'label' => 'Conceptos',
                            'url' => route('admin.coupons.settings', ['tab' => 'concepts']),
                            'current' => Route::currentRouteName() === 'admin.coupons.settings'
                                && $request->query('tab') === 'concepts',
                        ],
                        [
                            'label' => 'Historial',
                            'url' => route('admin.coupons.logs'),
                            'current' => Route::currentRouteName() === 'admin.coupons.logs',
                        ],
                    ],
                ]] : [],
                ...$request->user()->administrator->hasPermissionTo('documentation.manage') ? [[
                    'label' => 'Documentación legal',
                    'url' => route('admin.documentation'),
                    'icon' => 'BookOpenIcon',
                    'current' => Route::currentRouteName() === 'admin.documentation',
                ]] : [],
                ...$request->user()->administrator->hasPermissionTo('simulators.manage') ? [[
                    'label' => 'Simuladores',
                    'icon' => 'BeakerIcon',
                    'items' => [
                        [
                            'label' => 'Inicio',
                            'url' => route('admin.simulators.index'),
                            'current' => Route::currentRouteName() === 'admin.simulators.index',
                        ],
                        [
                            'label' => 'Simulador OTP',
                            'url' => route('admin.simulators.otp'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.simulators.otp'),
                        ],
                        [
                            'label' => 'Simulador de correos',
                            'url' => route('admin.simulators.emails'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.simulators.emails'),
                        ],
                        [
                            'label' => 'Simulador GDA',
                            'url' => route('admin.simulators.gda'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.simulators.gda'),
                        ],
                    ],
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
                        $request->user()->administrator->hasPermissionTo('view carts') ? [
                            'label' => 'Carritos',
                            'url' => route('admin.carts.index'),
                            'current' => Route::currentRouteName() === 'admin.carts.index'
                                || Route::currentRouteName() === 'admin.carts.show',
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
                        $request->user()->administrator->hasPermissionTo('view_config_monitor') ? [
                            'label' => 'Config Monitor',
                            'url' => route('admin.config-monitor.index'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.config-monitor'),
                        ] : null,
                        $request->user()->administrator->hasPermissionTo('monitoring-ai.manage') ? [
                            'label' => 'Asistente IA',
                            'url' => route('admin.monitoring-ai.index'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.monitoring-ai'),
                        ] : null,
                        $request->user()->administrator->roles()->where('roles.id', 1)->exists() ? [
                            'label' => 'Murguía — dashboard',
                            'url' => route('admin.murguia-dashboard.index'),
                            'current' => Route::currentRouteName() === 'admin.murguia-dashboard.index',
                        ] : null,
                        $request->user()->administrator->roles()->where('roles.id', 1)->exists() ? [
                            'label' => 'Murguía — reportes',
                            'url' => route('admin.murguia-reports.index'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.murguia-reports'),
                        ] : null,
                        $request->user()->administrator->roles()->where('roles.id', 1)->exists() ? [
                            'label' => 'Murguía — conciliación',
                            'url' => route('admin.murguia-reconciliation.index'),
                            'current' => str_starts_with((string) Route::currentRouteName(), 'admin.murguia-reconciliation'),
                        ] : null,
                        $request->user()->administrator->roles()->where('roles.id', 1)->exists() ? [
                            'label' => 'Murguía — monitor',
                            'url' => route('admin.murguia-monitor.index'),
                            'current' => in_array(Route::currentRouteName(), [
                                'admin.murguia-monitor.index',
                                'admin.murguia-monitor.show',
                                'admin.murguia.upload',
                                'admin.murguia.logs',
                            ], true),
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
