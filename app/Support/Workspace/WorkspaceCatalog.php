<?php

namespace App\Support\Workspace;

/**
 * Catálogo de Workspaces (flujos de trabajo).
 * Agregar módulos aquí no requiere tocar el Sidebar.
 */
final class WorkspaceCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function workspaces(): array
    {
        return [
            [
                'id' => 'clinical-ai',
                'slug' => 'clinical-ai',
                'emoji' => '🩺',
                'name' => 'IA Clínica',
                'description' => 'Herramientas inteligentes para interpretación clínica y apoyo médico.',
                'accent' => 'teal',
                'featured' => true,
                'cta' => 'Abrir IA Clínica',
                'permissions' => ['clinical-interpreter.manage'],
                'tools' => [
                    [
                        'id' => 'prescription-reader',
                        'title' => 'Lector de Recetas Médicas',
                        'description' => 'Interpreta recetas y genera propuestas clínicas.',
                        'route' => 'admin.clinical-interpreter.index',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'lab-interpreter',
                        'title' => 'Interpretador de Resultados de Laboratorio',
                        'description' => 'Matching y lectura asistida de estudios.',
                        'route' => 'admin.clinical-interpreter.matching',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'smart-pharmacy',
                        'title' => 'Farmacia Inteligente',
                        'description' => 'Recomendaciones y propuestas comerciales.',
                        'route' => 'admin.clinical-interpreter.assistant',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'clinical-recommendations',
                        'title' => 'Recomendaciones Clínicas IA',
                        'description' => 'Aprendizaje y sugerencias clínicas.',
                        'route' => 'admin.clinical-interpreter.learning',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'drug-interactions',
                        'title' => 'Interacciones entre Medicamentos',
                        'description' => 'Próximamente — alertas de interacciones.',
                        'route' => null,
                        'status' => 'coming_soon',
                    ],
                    [
                        'id' => 'interpretation-history',
                        'title' => 'Historial de Interpretaciones',
                        'description' => 'Consultas e interpretaciones previas.',
                        'route' => 'admin.clinical-interpreter.history',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'clinical-orders',
                        'title' => 'Clinical Orders',
                        'description' => 'Órdenes clínicas generadas por IA.',
                        'route' => 'admin.clinical-interpreter.orders.index',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'radiology-ai',
                        'title' => 'Radiología IA',
                        'description' => 'Futuro — interpretación de imagenología.',
                        'route' => null,
                        'status' => 'coming_soon',
                    ],
                    [
                        'id' => 'telemedicine-ai',
                        'title' => 'Telemedicina IA',
                        'description' => 'Futuro — apoyo a consultas remotas.',
                        'route' => null,
                        'status' => 'coming_soon',
                    ],
                ],
            ],
            [
                'id' => 'customers',
                'slug' => 'customers',
                'emoji' => '👥',
                'name' => 'Clientes',
                'description' => 'Conoce el comportamiento completo de tus pacientes.',
                'accent' => 'indigo',
                'featured' => false,
                'cta' => 'Abrir Clientes',
                'permissions' => ['customers.manage'],
                'tools' => [
                    [
                        'id' => 'journey',
                        'title' => 'Customer Journey',
                        'description' => 'Recorrido registro → compra.',
                        'route' => 'admin.customer-intelligence.customer-journey',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'health',
                        'title' => 'Customer Health',
                        'description' => 'Health Score y riesgo.',
                        'route' => 'admin.customer-intelligence.customer-health',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'dormant',
                        'title' => 'Clientes Dormidos',
                        'description' => 'Sin compras — oportunidades de activación.',
                        'route' => 'admin.customers.dormant',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'customer-360',
                        'title' => 'Customer 360',
                        'description' => 'Listado y ficha de clientes.',
                        'route' => 'admin.customers.index',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'referrals',
                        'title' => 'Referral Intelligence',
                        'description' => 'Programa de referidos.',
                        'route' => 'admin.customers.referrals',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'segmentation',
                        'title' => 'Segmentación',
                        'description' => 'Cohorts y retención.',
                        'route' => 'admin.customer-intelligence.cohorts',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'id' => 'marketing',
                'slug' => 'marketing',
                'emoji' => '📈',
                'name' => 'Marketing',
                'description' => 'Adquisición, campañas y automatización.',
                'accent' => 'orange',
                'featured' => false,
                'cta' => 'Abrir Marketing',
                'permissions' => ['activecampaign.manage'],
                'tools' => [
                    [
                        'id' => 'campaigns',
                        'title' => 'Campañas',
                        'description' => 'Analytics de marketing.',
                        'route' => 'admin.activecampaign.analytics',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'segments',
                        'title' => 'Segment Builder',
                        'description' => 'Contactos y audiencia.',
                        'route' => 'admin.activecampaign.contacts',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'referral',
                        'title' => 'Referral',
                        'description' => 'Desempeño de referidos.',
                        'route' => 'admin.customers.referrals',
                        'status' => 'active',
                        'permissions' => ['customers.manage'],
                    ],
                    [
                        'id' => 'automations',
                        'title' => 'Automatizaciones',
                        'description' => 'Automation Center.',
                        'route' => 'admin.activecampaign.automations',
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
                        'id' => 'activecampaign',
                        'title' => 'ActiveCampaign',
                        'description' => 'Dashboard de sincronización.',
                        'route' => 'admin.activecampaign.dashboard',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'id' => 'executive',
                'slug' => 'executive',
                'emoji' => '📊',
                'name' => 'Dirección',
                'description' => 'Indicadores estratégicos del negocio.',
                'accent' => 'purple',
                'featured' => false,
                'cta' => 'Abrir Dirección',
                'permissions' => ['activecampaign.manage'],
                'tools' => [
                    [
                        'id' => 'executive-dashboard',
                        'title' => 'Executive Dashboard',
                        'description' => 'Pulso ejecutivo.',
                        'route' => 'admin.activecampaign.dashboard',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'revenue',
                        'title' => 'Revenue',
                        'description' => 'Ecommerce e ingresos.',
                        'route' => 'admin.activecampaign.ecommerce',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'forecast',
                        'title' => 'Forecast',
                        'description' => 'Señales de crecimiento.',
                        'route' => 'admin.activecampaign.analytics',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'business-intelligence',
                        'title' => 'Business Intelligence',
                        'description' => 'Labs, farmacia y membresías.',
                        'route' => 'admin.activecampaign.laboratories',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'kpis',
                        'title' => 'KPIs',
                        'description' => 'Funnels e indicadores.',
                        'route' => 'admin.activecampaign.funnels',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'executive-copilot',
                        'title' => 'Executive Copilot',
                        'description' => 'Asistente IA para dirección.',
                        'route' => 'admin.monitoring-ai.index',
                        'status' => 'active',
                        'permissions' => ['monitoring-ai.manage'],
                    ],
                ],
            ],
            [
                'id' => 'ai',
                'slug' => 'ai',
                'emoji' => '✨',
                'name' => 'IA',
                'description' => 'Administración del ecosistema de IA.',
                'accent' => 'violet',
                'featured' => false,
                'cta' => 'Abrir IA',
                'permissions' => ['clinical-interpreter.manage', 'monitoring-ai.manage'],
                'permission_mode' => 'any',
                'tools' => [
                    [
                        'id' => 'ai-operations',
                        'title' => 'AI Operations',
                        'description' => 'Centro de operaciones IA.',
                        'route' => 'admin.clinical-interpreter.operations',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                    [
                        'id' => 'prompt-center',
                        'title' => 'Prompt Center',
                        'description' => 'Interpretador clínico y prompts.',
                        'route' => 'admin.clinical-interpreter.index',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                    [
                        'id' => 'models',
                        'title' => 'Modelos',
                        'description' => 'Configuración de modelos.',
                        'route' => 'admin.clinical-interpreter.config',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                    [
                        'id' => 'copilot',
                        'title' => 'Copilot',
                        'description' => 'Asistente IA general.',
                        'route' => 'admin.monitoring-ai.index',
                        'status' => 'active',
                        'permissions' => ['monitoring-ai.manage'],
                    ],
                    [
                        'id' => 'ai-monitoring',
                        'title' => 'AI Monitoring',
                        'description' => 'Monitoreo y aprendizaje.',
                        'route' => 'admin.clinical-interpreter.learning',
                        'status' => 'active',
                        'permissions' => ['clinical-interpreter.manage'],
                    ],
                ],
            ],
            [
                'id' => 'platform',
                'slug' => 'platform',
                'emoji' => '⚙',
                'name' => 'Plataforma',
                'description' => 'Administración técnica.',
                'accent' => 'slate',
                'featured' => false,
                'cta' => 'Abrir Plataforma',
                'permissions' => ['activecampaign.manage', 'administrators.manage', 'logs-general.manage', 'view_config_monitor'],
                'permission_mode' => 'any',
                'tools' => [
                    [
                        'id' => 'monitoring',
                        'title' => 'Monitoreo',
                        'description' => 'Health Center operativo.',
                        'route' => 'admin.activecampaign.health',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                    [
                        'id' => 'logs',
                        'title' => 'Logs',
                        'description' => 'Logs Center / logs generales.',
                        'route' => 'admin.activecampaign.logs',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                    [
                        'id' => 'queues',
                        'title' => 'Queues',
                        'description' => 'Event Center.',
                        'route' => 'admin.activecampaign.events',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                    [
                        'id' => 'jobs',
                        'title' => 'Jobs',
                        'description' => 'Alertas y jobs.',
                        'route' => 'admin.activecampaign.alerts',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                    [
                        'id' => 'permissions',
                        'title' => 'Permisos',
                        'description' => 'Roles y permisos.',
                        'route' => 'admin.roles.index',
                        'status' => 'active',
                        'permissions' => ['administrators.manage'],
                    ],
                    [
                        'id' => 'roles',
                        'title' => 'Roles',
                        'description' => 'Administradores y roles.',
                        'route' => 'admin.administrators.index',
                        'status' => 'active',
                        'permissions' => ['administrators.manage'],
                    ],
                    [
                        'id' => 'governance',
                        'title' => 'Governance',
                        'description' => 'Configuration Center.',
                        'route' => 'admin.activecampaign.settings',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                    [
                        'id' => 'integrations',
                        'title' => 'Integraciones',
                        'description' => 'Integrations Hub.',
                        'route' => 'admin.activecampaign.integrations',
                        'status' => 'active',
                        'permissions' => ['activecampaign.manage'],
                    ],
                ],
            ],
        ];
    }

    public static function find(string $slug): ?array
    {
        foreach (self::workspaces() as $workspace) {
            if ($workspace['slug'] === $slug) {
                return $workspace;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::workspaces(), 'slug');
    }
}
