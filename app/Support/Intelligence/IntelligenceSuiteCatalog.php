<?php

namespace App\Support\Intelligence;

/**
 * Catálogo de Suites del Intelligence Hub.
 * Agregar una Suite aquí no requiere tocar el Sidebar.
 */
final class IntelligenceSuiteCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function suites(): array
    {
        return [
            [
                'id' => 'customer',
                'slug' => 'customer',
                'emoji' => '👥',
                'name' => 'Customer Intelligence',
                'description' => 'Conoce el comportamiento completo de tus clientes.',
                'accent' => 'indigo',
                'permissions' => ['customers.manage'],
                'stat_labels' => ['Módulos', 'Health promedio', 'Clientes Dormidos', 'Insights'],
                'modules' => [
                    [
                        'id' => 'dashboard',
                        'title' => 'Dashboard',
                        'description' => 'Portada del Customer Intelligence Center.',
                        'route' => 'admin.customer-intelligence.index',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'dormant',
                        'title' => 'Clientes Dormidos',
                        'description' => 'Registrados sin compras. Oportunidades de activación.',
                        'route' => 'admin.customers.dormant',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'journey',
                        'title' => 'Customer Journey',
                        'description' => 'Recorrido completo registro → compra.',
                        'route' => 'admin.customer-intelligence.customer-journey',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'cohorts',
                        'title' => 'Cohorts & Retention',
                        'description' => 'Retención, churn y LTV por cohort.',
                        'route' => 'admin.customer-intelligence.cohorts',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'health',
                        'title' => 'Customer Health',
                        'description' => 'Health Score y personas predictivas.',
                        'route' => 'admin.customer-intelligence.customer-health',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'customer-360',
                        'title' => 'Customer 360',
                        'description' => 'Ficha operativa de clientes.',
                        'route' => 'admin.customers.index',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'referrals',
                        'title' => 'Referral Intelligence',
                        'description' => 'Desempeño del programa de referidos.',
                        'route' => 'admin.customers.referrals',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'id' => 'marketing',
                'slug' => 'marketing',
                'emoji' => '📈',
                'name' => 'Marketing Intelligence',
                'description' => 'Campañas, automatizaciones, ROI y segmentos.',
                'accent' => 'orange',
                'permissions' => ['activecampaign.manage'],
                'stat_labels' => ['Campañas activas', 'Automatizaciones', 'ROI', 'Segmentos'],
                'modules' => [
                    [
                        'id' => 'dashboard',
                        'title' => 'Dashboard',
                        'description' => 'Pulso de marketing y sincronización.',
                        'route' => 'admin.activecampaign.dashboard',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'campaigns',
                        'title' => 'Campañas',
                        'description' => 'Analytics y atribución de campañas.',
                        'route' => 'admin.activecampaign.analytics',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'segments',
                        'title' => 'Segment Builder',
                        'description' => 'Contactos y audiencia sincronizada.',
                        'route' => 'admin.activecampaign.contacts',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'referral',
                        'title' => 'Referral',
                        'description' => 'Referral Intelligence Dashboard.',
                        'route' => 'admin.customers.referrals',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'roi',
                        'title' => 'ROI',
                        'description' => 'Funnels e inteligencia de conversión.',
                        'route' => 'admin.activecampaign.funnels',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'automation',
                        'title' => 'Automation Center',
                        'description' => 'Automatizaciones ActiveCampaign.',
                        'route' => 'admin.activecampaign.automations',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'id' => 'executive',
                'slug' => 'executive',
                'emoji' => '👔',
                'name' => 'Executive Intelligence',
                'description' => 'KPIs ejecutivos y decisiones estratégicas.',
                'accent' => 'purple',
                'permissions' => ['activecampaign.manage'],
                'stat_labels' => ['Revenue', 'Forecast', 'Executive Copilot', 'KPIs'],
                'modules' => [
                    [
                        'id' => 'dashboard',
                        'title' => 'Dashboard',
                        'description' => 'Dashboard ejecutivo de marketing.',
                        'route' => 'admin.activecampaign.dashboard',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'revenue',
                        'title' => 'Revenue',
                        'description' => 'Ecommerce e ingresos atribuidos.',
                        'route' => 'admin.activecampaign.ecommerce',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'forecast',
                        'title' => 'Forecast',
                        'description' => 'Proyecciones y señales de crecimiento.',
                        'route' => 'admin.activecampaign.analytics',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'copilot',
                        'title' => 'Executive Copilot',
                        'description' => 'Asistente IA para dirección.',
                        'route' => 'admin.monitoring-ai.index',
                        'status' => 'active',
                        'permissions' => ['monitoring-ai.manage'],
                    ],
                    [
                        'id' => 'kpis',
                        'title' => 'KPIs',
                        'description' => 'Indicadores y funnels ejecutivos.',
                        'route' => 'admin.activecampaign.funnels',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'id' => 'business',
                'slug' => 'business',
                'emoji' => '📊',
                'name' => 'Business Intelligence',
                'description' => 'Analítica comercial y operativa.',
                'accent' => 'sky',
                'permissions' => ['activecampaign.manage'],
                'stat_labels' => ['Ventas', 'Laboratorios', 'Farmacia', 'Membresías'],
                'modules' => [
                    [
                        'id' => 'dashboard',
                        'title' => 'Dashboard',
                        'description' => 'Vista consolidada de negocio.',
                        'route' => 'admin.activecampaign.dashboard',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'sales',
                        'title' => 'Ventas',
                        'description' => 'Ecommerce Intelligence.',
                        'route' => 'admin.activecampaign.ecommerce',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'labs',
                        'title' => 'Laboratorios',
                        'description' => 'Laboratory Intelligence.',
                        'route' => 'admin.activecampaign.laboratories',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'pharmacy',
                        'title' => 'Farmacia',
                        'description' => 'Señales de farmacia online.',
                        'route' => 'admin.activecampaign.ecommerce',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'memberships',
                        'title' => 'Membresías',
                        'description' => 'Membership Intelligence.',
                        'route' => 'admin.activecampaign.memberships',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'convenios',
                        'title' => 'Convenios',
                        'description' => 'Próximamente — inteligencia de convenios.',
                        'route' => null,
                        'status' => 'coming_soon',
                    ],
                ],
            ],
            [
                'id' => 'ai-operations',
                'slug' => 'ai-operations',
                'emoji' => '✨',
                'name' => 'AI Operations',
                'description' => 'Administración de IA y Prompts.',
                'accent' => 'violet',
                'permissions' => ['clinical-interpreter.manage', 'monitoring-ai.manage'],
                'permission_mode' => 'any',
                'stat_labels' => ['Prompts', 'Modelos', 'Automatizaciones', 'Logs'],
                'modules' => [
                    [
                        'id' => 'dashboard',
                        'title' => 'Dashboard',
                        'description' => 'AI Operations Center.',
                        'route' => 'admin.clinical-interpreter.operations',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                    [
                        'id' => 'prompt-center',
                        'title' => 'Prompt Center',
                        'description' => 'AI Clinical Interpreter.',
                        'route' => 'admin.clinical-interpreter.index',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                    [
                        'id' => 'models',
                        'title' => 'Modelos',
                        'description' => 'Configuración de modelos IA.',
                        'route' => 'admin.clinical-interpreter.config',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                    [
                        'id' => 'monitoring',
                        'title' => 'AI Monitoring',
                        'description' => 'Asistente y monitoreo IA.',
                        'route' => 'admin.monitoring-ai.index',
                        'status' => 'active',
                        'permissions' => ['monitoring-ai.manage'],
                    ],
                    [
                        'id' => 'learning',
                        'title' => 'AI Learning',
                        'description' => 'Historizaje y mejora continua.',
                        'route' => 'admin.clinical-interpreter.learning',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                    [
                        'id' => 'logs',
                        'title' => 'Logs',
                        'description' => 'Historial de interpretaciones.',
                        'route' => 'admin.clinical-interpreter.history',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                ],
            ],
            [
                'id' => 'operations',
                'slug' => 'operations',
                'emoji' => '⚙',
                'name' => 'Operations Center',
                'description' => 'Monitoreo operativo del ecosistema.',
                'accent' => 'slate',
                'permissions' => ['activecampaign.manage'],
                'stat_labels' => ['Queues', 'Jobs', 'Logs', 'Performance'],
                'modules' => [
                    [
                        'id' => 'dashboard',
                        'title' => 'Dashboard',
                        'description' => 'Health Center operativo.',
                        'route' => 'admin.activecampaign.health',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'queues',
                        'title' => 'Queues',
                        'description' => 'Event Center y colas.',
                        'route' => 'admin.activecampaign.events',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'workers',
                        'title' => 'Workers',
                        'description' => 'Automatizaciones en ejecución.',
                        'route' => 'admin.activecampaign.automations',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'jobs',
                        'title' => 'Jobs',
                        'description' => 'Alertas operativas.',
                        'route' => 'admin.activecampaign.alerts',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'performance',
                        'title' => 'Performance',
                        'description' => 'Analytics de rendimiento.',
                        'route' => 'admin.activecampaign.analytics',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'integrations',
                        'title' => 'Integraciones',
                        'description' => 'Integrations Hub.',
                        'route' => 'admin.activecampaign.integrations',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'logs',
                        'title' => 'Logs',
                        'description' => 'Logs Center.',
                        'route' => 'admin.activecampaign.logs',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'id' => 'governance',
                'slug' => 'governance',
                'emoji' => '🛡',
                'name' => 'Platform Governance',
                'description' => 'Auditoría, seguridad y gobierno.',
                'accent' => 'green',
                'permissions' => ['activecampaign.manage', 'administrators.manage'],
                'permission_mode' => 'any',
                'stat_labels' => ['Auditorías', 'Permisos', 'Roles', 'Compliance'],
                'modules' => [
                    [
                        'id' => 'dashboard',
                        'title' => 'Dashboard',
                        'description' => 'Configuration Center.',
                        'route' => 'admin.activecampaign.settings',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                    [
                        'id' => 'audits',
                        'title' => 'Auditorías',
                        'description' => 'QA vs Production.',
                        'route' => 'admin.activecampaign.qa-compare',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                    [
                        'id' => 'users',
                        'title' => 'Usuarios',
                        'description' => 'Administradores de la plataforma.',
                        'route' => 'admin.administrators.index',
                        'status' => 'active',
                        'permissions' => ['administrators.manage'],
                    ],
                    [
                        'id' => 'roles',
                        'title' => 'Roles',
                        'description' => 'Roles y permisos.',
                        'route' => 'admin.roles.index',
                        'status' => 'active',
                        'permissions' => ['administrators.manage'],
                    ],
                    [
                        'id' => 'permissions',
                        'title' => 'Permisos',
                        'description' => 'Gobierno de acceso.',
                        'route' => 'admin.roles.index',
                        'status' => 'active',
                        'permissions' => ['administrators.manage'],
                    ],
                    [
                        'id' => 'logs',
                        'title' => 'Logs',
                        'description' => 'Logs de marketing y sync.',
                        'route' => 'admin.activecampaign.logs',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                ],
            ],
        ];
    }

    public static function find(string $slug): ?array
    {
        foreach (self::suites() as $suite) {
            if ($suite['slug'] === $slug) {
                return $suite;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::suites(), 'slug');
    }
}
