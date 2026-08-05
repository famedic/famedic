<?php

namespace App\Support\Workspace;

/**
 * Catálogo del producto ActiveCampaign Hub (Workspace).
 * Solo define navegación y señales conocidas; no inventa sync.
 */
final class ActiveCampaignHubCatalog
{
    /**
     * @return list<string>
     */
    public static function highlights(): array
    {
        return ['CRM', 'Campañas', 'Automatizaciones', 'Integraciones', 'Analytics', 'Developer'];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function tabs(): array
    {
        return [
            ['id' => 'overview', 'label' => 'Overview'],
            ['id' => 'crm', 'label' => 'CRM'],
            ['id' => 'campaigns', 'label' => 'Campañas'],
            ['id' => 'automations', 'label' => 'Automatizaciones'],
            ['id' => 'analytics', 'label' => 'Analytics'],
            ['id' => 'integrations', 'label' => 'Integraciones'],
            ['id' => 'developer', 'label' => 'Developer'],
            ['id' => 'settings', 'label' => 'Configuración'],
        ];
    }

    /**
     * Módulos Overview (tarjetas grandes → pantallas existentes).
     *
     * @return list<array<string, mixed>>
     */
    public static function overviewModules(): array
    {
        return [
            [
                'id' => 'crm',
                'emoji' => '👤',
                'title' => 'CRM',
                'description' => 'Contactos y fichas del ciclo de vida.',
                'items' => ['Contactos', 'Lead Score', 'Customer 360', 'Buscar Contacto'],
                'href_route' => 'admin.activecampaign.contacts',
            ],
            [
                'id' => 'segmentation',
                'emoji' => '🧩',
                'title' => 'Segmentación',
                'description' => 'Listas, etiquetas y campos personalizados.',
                'items' => ['Listas', 'Etiquetas', 'Campos Personalizados', 'Objetos Personalizados'],
                'href_route' => 'admin.activecampaign.tags',
            ],
            [
                'id' => 'campaigns',
                'emoji' => '📣',
                'title' => 'Campañas',
                'description' => 'Email y rendimiento de envíos.',
                'items' => ['Email', 'Templates', 'Programadas', 'Historial'],
                'href_route' => 'admin.activecampaign.analytics',
            ],
            [
                'id' => 'automations',
                'emoji' => '⚡',
                'title' => 'Automatizaciones',
                'description' => 'Flujos, triggers y recuperación.',
                'items' => ['Flujos', 'Triggers', 'Objetivos', 'Carritos abandonados', 'Recuperación'],
                'href_route' => 'admin.activecampaign.automations',
            ],
            [
                'id' => 'analytics',
                'emoji' => '📊',
                'title' => 'Analytics',
                'description' => 'Open Rate, funnels y journey.',
                'items' => ['Open Rate', 'CTR', 'ROI', 'Funnels', 'Journey', 'Conversiones'],
                'href_route' => 'admin.activecampaign.analytics',
            ],
            [
                'id' => 'sync',
                'emoji' => '🔄',
                'title' => 'Sincronización',
                'description' => 'Salud de sync, errores y reintentos.',
                'items' => ['Estado', 'Pendientes', 'Errores', 'Reintentos', 'Webhook'],
                'href_route' => 'admin.activecampaign.health',
            ],
            [
                'id' => 'developer',
                'emoji' => '🛠',
                'title' => 'Developer',
                'description' => 'API, logs, jobs y configuración.',
                'items' => ['API', 'Logs', 'Requests', 'Webhooks', 'Tokens', 'Jobs', 'Queue'],
                'href_route' => 'admin.activecampaign.settings',
            ],
        ];
    }

    /**
     * Atajos por tab (reutilizan rutas AC existentes).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function tabShortcuts(): array
    {
        return [
            'crm' => [
                ['id' => 'contacts', 'label' => 'Contactos', 'route' => 'admin.activecampaign.contacts'],
                ['id' => 'patient-360', 'label' => 'Customer 360', 'route' => 'admin.activecampaign.patient-360'],
                ['id' => 'journey', 'label' => 'Customer Journey', 'route' => 'admin.activecampaign.customer-journey'],
                ['id' => 'tags', 'label' => 'Tags', 'route' => 'admin.activecampaign.tags'],
                ['id' => 'fields', 'label' => 'Campos', 'route' => 'admin.activecampaign.fields'],
            ],
            'campaigns' => [
                ['id' => 'analytics', 'label' => 'Analytics / Email', 'route' => 'admin.activecampaign.analytics'],
                ['id' => 'notifications', 'label' => 'Notification Center', 'route' => 'admin.activecampaign.notifications'],
                ['id' => 'alerts', 'label' => 'Alerts', 'route' => 'admin.activecampaign.alerts'],
            ],
            'automations' => [
                ['id' => 'automations', 'label' => 'Automation Center', 'route' => 'admin.activecampaign.automations'],
                ['id' => 'list', 'label' => 'Lista de flujos', 'route' => 'admin.activecampaign.automations.list'],
                ['id' => 'builder', 'label' => 'Builder', 'route' => 'admin.activecampaign.automations.builder'],
                ['id' => 'events', 'label' => 'Event Center', 'route' => 'admin.activecampaign.events'],
            ],
            'analytics' => [
                ['id' => 'analytics', 'label' => 'Analytics', 'route' => 'admin.activecampaign.analytics'],
                ['id' => 'funnels', 'label' => 'Funnels', 'route' => 'admin.activecampaign.funnels'],
                ['id' => 'journey', 'label' => 'Journey', 'route' => 'admin.activecampaign.customer-journey'],
                ['id' => 'ecommerce', 'label' => 'E-commerce', 'route' => 'admin.activecampaign.ecommerce'],
                ['id' => 'labs', 'label' => 'Laboratorios', 'route' => 'admin.activecampaign.laboratories'],
                ['id' => 'memberships', 'label' => 'Membresías', 'route' => 'admin.activecampaign.memberships'],
            ],
            'integrations' => [
                ['id' => 'integrations-hub', 'label' => 'Integrations Hub', 'route' => 'admin.activecampaign.integrations'],
                ['id' => 'health', 'label' => 'Health Center', 'route' => 'admin.activecampaign.health'],
                ['id' => 'events', 'label' => 'Event Center', 'route' => 'admin.activecampaign.events'],
                ['id' => 'logs', 'label' => 'Logs', 'route' => 'admin.activecampaign.logs'],
                ['id' => 'qa', 'label' => 'QA vs Prod', 'route' => 'admin.activecampaign.qa-compare'],
            ],
            'developer' => [
                ['id' => 'settings', 'label' => 'Settings / API', 'route' => 'admin.activecampaign.settings'],
                ['id' => 'logs', 'label' => 'Logs', 'route' => 'admin.activecampaign.logs'],
                ['id' => 'health', 'label' => 'Health / Queue', 'route' => 'admin.activecampaign.health'],
                ['id' => 'integrations', 'label' => 'Integrations', 'route' => 'admin.activecampaign.integrations'],
                ['id' => 'qa', 'label' => 'QA Compare', 'route' => 'admin.activecampaign.qa-compare'],
            ],
            'settings' => [
                ['id' => 'settings', 'label' => 'Configuración AC', 'route' => 'admin.activecampaign.settings'],
                ['id' => 'health', 'label' => 'Health Center', 'route' => 'admin.activecampaign.health'],
                ['id' => 'logs', 'label' => 'Logs', 'route' => 'admin.activecampaign.logs'],
                ['id' => 'dashboard', 'label' => 'Dashboard AC', 'route' => 'admin.activecampaign.dashboard'],
            ],
        ];
    }

    /**
     * Integraciones Famedic → AC detectadas en código (sin inventar).
     *
     * @return list<array<string, mixed>>
     */
    public static function famedicIntegrations(): array
    {
        return [
            ['id' => 'customer_registered', 'label' => 'Cliente Registrado', 'signal' => 'registration / sync paciente', 'href_route' => 'admin.activecampaign.events'],
            ['id' => 'laboratory_purchase', 'label' => 'Compra Laboratorio', 'signal' => 'tag_laboratory_purchase_completed', 'href_route' => 'admin.activecampaign.laboratories'],
            ['id' => 'pharmacy_purchase', 'label' => 'Compra Farmacia', 'signal' => 'tag_pharmacy_purchase_completed', 'href_route' => 'admin.activecampaign.ecommerce'],
            ['id' => 'membership', 'label' => 'Membresía Activada', 'signal' => 'memberships intelligence', 'href_route' => 'admin.activecampaign.memberships'],
            ['id' => 'abandoned_cart', 'label' => 'Carrito Abandonado', 'signal' => 'activecampaign:tag-abandoned-carts', 'href_route' => 'admin.activecampaign.automations'],
            ['id' => 'checkout', 'label' => 'Checkout', 'signal' => 'event center / ecommerce', 'href_route' => 'admin.activecampaign.events'],
            ['id' => 'laboratory_result', 'label' => 'Resultado Laboratorio', 'signal' => 'tag_lab_results_available', 'href_route' => 'admin.activecampaign.events'],
            ['id' => 'sample_collected', 'label' => 'Toma de Muestra', 'signal' => 'tag_lab_sample_collected', 'href_route' => 'admin.activecampaign.events'],
            ['id' => 'customer_health', 'label' => 'Customer Health', 'signal' => 'Customer Intelligence', 'href_route' => 'admin.customer-intelligence.customer-health'],
            ['id' => 'customer_journey', 'label' => 'Customer Journey', 'signal' => 'AC Customer Journey', 'href_route' => 'admin.activecampaign.customer-journey'],
            ['id' => 'referral', 'label' => 'Referral', 'signal' => 'Referral Intelligence', 'href_route' => 'admin.customers.referrals'],
            ['id' => 'coupons', 'label' => 'Cupones', 'signal' => 'credit_* / promo_* dispatches', 'href_route' => 'admin.activecampaign.logs'],
            ['id' => 'orders', 'label' => 'Órdenes', 'signal' => 'ecommerce intelligence', 'href_route' => 'admin.activecampaign.ecommerce'],
            ['id' => 'events', 'label' => 'Eventos', 'signal' => 'Event Center', 'href_route' => 'admin.activecampaign.events'],
            ['id' => 'tags', 'label' => 'Tags', 'signal' => 'Tags Manager', 'href_route' => 'admin.activecampaign.tags'],
            ['id' => 'fields', 'label' => 'Campos Personalizados', 'signal' => 'Custom Fields Manager', 'href_route' => 'admin.activecampaign.fields'],
        ];
    }

    /**
     * Catálogo de eventos conocidos (dispatch + domain).
     * source: dispatch = filas en activecampaign_dispatches; domain = pipeline instrumentado.
     *
     * @return list<array<string, mixed>>
     */
    public static function eventCatalog(): array
    {
        return [
            ['key' => 'credit_assigned', 'label' => 'Crédito asignado', 'source' => 'dispatch'],
            ['key' => 'credit_redeemed', 'label' => 'Crédito redimido', 'source' => 'dispatch'],
            ['key' => 'credit_restored', 'label' => 'Crédito restaurado', 'source' => 'dispatch'],
            ['key' => 'credit_revoked', 'label' => 'Crédito revocado', 'source' => 'dispatch'],
            ['key' => 'credit_expiring', 'label' => 'Crédito por expirar', 'source' => 'dispatch'],
            ['key' => 'pending_beneficiary_created', 'label' => 'Beneficiario pendiente creado', 'source' => 'dispatch'],
            ['key' => 'pending_beneficiary_activated', 'label' => 'Beneficiario activado', 'source' => 'dispatch'],
            ['key' => 'pending_beneficiary_cancelled', 'label' => 'Beneficiario cancelado', 'source' => 'dispatch'],
            ['key' => 'promo_validated', 'label' => 'Promo validada', 'source' => 'dispatch'],
            ['key' => 'promo_used', 'label' => 'Promo usada', 'source' => 'dispatch'],
            ['key' => 'promo_released', 'label' => 'Promo liberada', 'source' => 'dispatch'],
            ['key' => 'registration', 'label' => 'Registro / customer created', 'source' => 'domain'],
            ['key' => 'laboratory_purchase', 'label' => 'Compra laboratorio', 'source' => 'domain'],
            ['key' => 'pharmacy_purchase', 'label' => 'Compra farmacia', 'source' => 'domain'],
            ['key' => 'membership', 'label' => 'Membresía', 'source' => 'domain'],
            ['key' => 'laboratory_results', 'label' => 'Resultados laboratorio', 'source' => 'domain'],
            ['key' => 'coupon_assigned', 'label' => 'Cupón asignado', 'source' => 'domain'],
            ['key' => 'cart_abandoned', 'label' => 'Carrito abandonado', 'source' => 'domain'],
            ['key' => 'activecampaign_dispatch', 'label' => 'Pipeline dispatch', 'source' => 'domain'],
        ];
    }

    /**
     * Dominios del panel Estado de Integración.
     *
     * @return list<array<string, mixed>>
     */
    public static function integrationDomains(): array
    {
        return [
            ['id' => 'contacts', 'label' => 'Contactos', 'health_id' => 'patients'],
            ['id' => 'tags', 'label' => 'Tags', 'flag' => null, 'href_route' => 'admin.activecampaign.tags'],
            ['id' => 'lists', 'label' => 'Listas', 'flag' => null],
            ['id' => 'fields', 'label' => 'Campos', 'href_route' => 'admin.activecampaign.fields'],
            ['id' => 'orders', 'label' => 'Pedidos', 'business_id' => 'lab'],
            ['id' => 'events', 'label' => 'Eventos', 'href_route' => 'admin.activecampaign.events'],
            ['id' => 'memberships', 'label' => 'Membresías', 'business_id' => 'membership'],
            ['id' => 'pharmacy', 'label' => 'Farmacia', 'business_id' => 'pharmacy'],
            ['id' => 'laboratories', 'label' => 'Laboratorios', 'business_id' => 'lab'],
            ['id' => 'journey', 'label' => 'Journey', 'href_route' => 'admin.activecampaign.customer-journey'],
            ['id' => 'health', 'label' => 'Health', 'href_route' => 'admin.activecampaign.health'],
            ['id' => 'referral', 'label' => 'Referral', 'href_route' => 'admin.customers.referrals'],
            ['id' => 'webhooks', 'label' => 'Webhooks', 'flag' => 'roadmap'],
            ['id' => 'credits', 'label' => 'Cupones / Créditos', 'health_id' => 'credits'],
            ['id' => 'abandoned', 'label' => 'Carritos', 'business_id' => 'abandoned'],
        ];
    }

    /**
     * @return list<array{id: string, label: string, status: string}>
     */
    public static function futureChannels(): array
    {
        return [
            ['id' => 'whatsapp', 'label' => 'WhatsApp Business', 'status' => 'coming_soon'],
            ['id' => 'sms', 'label' => 'SMS', 'status' => 'coming_soon'],
            ['id' => 'push', 'label' => 'Push Notifications', 'status' => 'coming_soon'],
            ['id' => 'meta-audiences', 'label' => 'Meta Audiences', 'status' => 'coming_soon'],
            ['id' => 'google-ads', 'label' => 'Google Ads Conversion', 'status' => 'coming_soon'],
            ['id' => 'facebook-capi', 'label' => 'Facebook Conversion API', 'status' => 'coming_soon'],
        ];
    }

    /**
     * @return list<array{id: string, label: string, route: string}>
     */
    public static function quickActions(): array
    {
        return [
            ['id' => 'create-contact', 'label' => 'Crear Contacto', 'route' => 'admin.activecampaign.contacts'],
            ['id' => 'create-campaign', 'label' => 'Crear Campaña', 'route' => 'admin.activecampaign.analytics'],
            ['id' => 'create-automation', 'label' => 'Crear Automatización', 'route' => 'admin.activecampaign.automations'],
            ['id' => 'create-list', 'label' => 'Crear Lista', 'route' => 'admin.activecampaign.contacts'],
            ['id' => 'create-tag', 'label' => 'Crear Tag', 'route' => 'admin.activecampaign.tags'],
            ['id' => 'send-event', 'label' => 'Enviar Evento', 'route' => 'admin.activecampaign.events'],
            ['id' => 'sync-all', 'label' => 'Sincronizar Todo', 'route' => 'admin.activecampaign.health'],
            ['id' => 'view-logs', 'label' => 'Ver Logs', 'route' => 'admin.activecampaign.logs'],
        ];
    }
}
