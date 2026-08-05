<?php

namespace App\Support\Workspace;

/**
 * Navegación y highlights de Customer Engagement (CRM / ActiveCampaign).
 */
final class CustomerEngagementCatalog
{
    /**
     * @return list<string>
     */
    public static function highlights(): array
    {
        return [
            'CRM',
            'Campañas',
            'Automatizaciones',
            'Lead Scoring',
            'Analytics',
            'Sincronización',
        ];
    }

    /**
     * Tabs + subtabs. `route` apunta a pantallas existentes; null = próximamente.
     *
     * @return list<array<string, mixed>>
     */
    public static function navigation(): array
    {
        return [
            [
                'id' => 'dashboard',
                'label' => 'Dashboard',
                'subtabs' => [],
            ],
            [
                'id' => 'contacts',
                'label' => 'Contactos',
                'subtabs' => [
                    ['id' => 'all', 'label' => 'Todos', 'route' => 'admin.activecampaign.contacts', 'channel' => null],
                    ['id' => 'lists', 'label' => 'Listas', 'route' => null, 'channel' => null],
                    ['id' => 'tags', 'label' => 'Etiquetas', 'route' => 'admin.activecampaign.tags', 'channel' => null],
                    ['id' => 'fields', 'label' => 'Campos', 'route' => 'admin.activecampaign.fields', 'channel' => null],
                    ['id' => 'lead-score', 'label' => 'Lead Score', 'route' => null, 'channel' => null],
                    ['id' => 'customer-360', 'label' => 'Customer 360', 'route' => 'admin.activecampaign.patient-360', 'channel' => null],
                ],
            ],
            [
                'id' => 'campaigns',
                'label' => 'Campañas',
                'subtabs' => [
                    ['id' => 'email', 'label' => 'Email', 'route' => 'admin.activecampaign.analytics', 'channel' => 'email'],
                    ['id' => 'whatsapp', 'label' => 'WhatsApp', 'route' => null, 'channel' => 'whatsapp'],
                    ['id' => 'sms', 'label' => 'SMS', 'route' => null, 'channel' => 'sms'],
                    ['id' => 'push', 'label' => 'Push', 'route' => null, 'channel' => 'push'],
                    ['id' => 'templates', 'label' => 'Templates', 'route' => null, 'channel' => null],
                    ['id' => 'scheduled', 'label' => 'Programadas', 'route' => 'admin.activecampaign.automations', 'channel' => null],
                ],
            ],
            [
                'id' => 'automations',
                'label' => 'Automatizaciones',
                'subtabs' => [
                    ['id' => 'flows', 'label' => 'Flujos', 'route' => 'admin.activecampaign.automations', 'channel' => null],
                    ['id' => 'triggers', 'label' => 'Triggers', 'route' => 'admin.activecampaign.automations.builder', 'channel' => null],
                    ['id' => 'goals', 'label' => 'Objetivos', 'route' => null, 'channel' => null],
                    ['id' => 'events', 'label' => 'Eventos', 'route' => 'admin.activecampaign.events', 'channel' => null],
                    ['id' => 'abandoned-carts', 'label' => 'Carritos abandonados', 'route' => 'admin.activecampaign.funnels', 'channel' => null],
                    ['id' => 'recovery', 'label' => 'Recuperación', 'route' => 'admin.activecampaign.automations', 'channel' => null],
                ],
            ],
            [
                'id' => 'analytics',
                'label' => 'Analytics',
                'subtabs' => [
                    ['id' => 'open-rate', 'label' => 'Open Rate', 'route' => 'admin.activecampaign.analytics', 'channel' => 'email'],
                    ['id' => 'ctr', 'label' => 'CTR', 'route' => 'admin.activecampaign.analytics', 'channel' => 'email'],
                    ['id' => 'funnels', 'label' => 'Funnels', 'route' => 'admin.activecampaign.funnels', 'channel' => null],
                    ['id' => 'conversion', 'label' => 'Conversión', 'route' => 'admin.activecampaign.funnels', 'channel' => null],
                    ['id' => 'journey', 'label' => 'Journey', 'route' => 'admin.activecampaign.customer-journey', 'channel' => null],
                    ['id' => 'roi', 'label' => 'ROI', 'route' => 'admin.activecampaign.ecommerce', 'channel' => null],
                ],
            ],
            [
                'id' => 'settings',
                'label' => 'Configuración',
                'subtabs' => [
                    ['id' => 'api', 'label' => 'API', 'route' => 'admin.activecampaign.settings', 'channel' => null],
                    ['id' => 'sync', 'label' => 'Sincronización', 'route' => 'admin.activecampaign.health', 'channel' => null],
                    ['id' => 'logs', 'label' => 'Logs', 'route' => 'admin.activecampaign.logs', 'channel' => null],
                    ['id' => 'webhooks', 'label' => 'Webhooks', 'route' => null, 'channel' => null],
                    ['id' => 'custom-fields', 'label' => 'Campos personalizados', 'route' => 'admin.activecampaign.fields', 'channel' => null],
                    ['id' => 'custom-objects', 'label' => 'Objetos personalizados', 'route' => null, 'channel' => null],
                    ['id' => 'integrations', 'label' => 'Integraciones', 'route' => 'admin.activecampaign.integrations', 'channel' => null],
                ],
            ],
        ];
    }
}
