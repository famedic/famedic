<?php

namespace App\Support\ActiveCampaign;

/**
 * Catálogo de pantallas del módulo Marketing Intelligence (solo navegación / placeholders).
 */
final class MarketingIntelligenceCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => [
                'key' => 'dashboard',
                'title' => 'Dashboard Ejecutivo',
                'description' => 'Pulso de automatización marketing, salud de sync y señales de conversión.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Core',
                'actions' => [
                    ['label' => 'Actualizar', 'disabled' => true],
                    ['label' => 'Exportar', 'disabled' => true],
                    ['label' => 'Ver alertas', 'disabled' => true],
                ],
                'layout' => 'dashboard',
                'metric_cards' => [
                    ['label' => 'Estado ActiveCampaign', 'truth' => 'disponible'],
                    ['label' => 'Pacientes sincronizados', 'truth' => 'proxy'],
                    ['label' => 'Errores', 'truth' => 'disponible'],
                    ['label' => 'Cola pendiente', 'truth' => 'disponible'],
                    ['label' => 'Automatizaciones', 'truth' => 'proximamente'],
                    ['label' => 'Última sincronización', 'truth' => 'disponible'],
                ],
                'kpi_cards' => [
                    ['label' => 'Compras laboratorio', 'truth' => 'proxy'],
                    ['label' => 'Compras farmacia', 'truth' => 'proxy'],
                    ['label' => 'Membresías', 'truth' => 'proxy'],
                    ['label' => 'Carritos abandonados', 'truth' => 'disponible'],
                    ['label' => 'Créditos', 'truth' => 'disponible'],
                    ['label' => 'Promociones', 'truth' => 'disponible'],
                ],
                'charts' => [
                    ['title' => 'Sincronizaciones por día', 'truth' => 'disponible'],
                    ['title' => 'Errores por día', 'truth' => 'disponible'],
                    ['title' => 'Eventos más frecuentes', 'truth' => 'disponible'],
                    ['title' => 'Compras atribuidas', 'truth' => 'instrumentacion'],
                ],
                'tables' => [
                    ['title' => 'Actividad reciente', 'columns' => ['Cuándo', 'Evento', 'Email', 'Estado']],
                    ['title' => 'Últimos errores', 'columns' => ['Cuándo', 'Evento', 'Intentos', 'Error']],
                    ['title' => 'En cola', 'columns' => ['Desde', 'Evento', 'Email', 'Estado']],
                    ['title' => 'Resumen dispatches', 'columns' => ['Estado', 'Total']],
                ],
                'notes' => [
                    'Esta pantalla consolidará métricas reales en una fase posterior.',
                    'Los badges indican qué datos ya existen vs. los que requieren instrumentación.',
                ],
            ],
            'contacts' => [
                'key' => 'contacts',
                'title' => 'Contactos',
                'description' => 'Audiencia con actividad de marketing y señales de sincronización.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Audiencia',
                'actions' => [
                    ['label' => 'Exportar', 'disabled' => true],
                    ['label' => 'Sincronizar', 'disabled' => true],
                    ['label' => 'Vista 360 demo', 'href' => 'admin.activecampaign.patient-360', 'disabled' => false],
                ],
                'layout' => 'list',
                'metric_cards' => [
                    ['label' => 'Total contactos', 'truth' => 'proxy'],
                    ['label' => 'Con sync exitoso', 'truth' => 'instrumentacion'],
                    ['label' => 'Con error', 'truth' => 'disponible'],
                ],
                'tables' => [
                    ['title' => 'Listado de contactos', 'columns' => ['Email', 'Nombre', 'Último evento', 'Tags', 'Estado']],
                ],
                'notes' => [
                    'La Vista 360 se abrirá desde cada fila cuando la pantalla esté instrumentada.',
                ],
            ],
            'patient-360' => [
                'key' => 'patient-360',
                'title' => 'Vista 360 del Paciente',
                'description' => 'Ficha unificada: identidad, journey, compras, créditos, tags y logs de marketing.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Detalle',
                'parent' => ['key' => 'contacts', 'title' => 'Contactos', 'route' => 'admin.activecampaign.contacts'],
                'actions' => [
                    ['label' => 'Ver cliente Famedic', 'disabled' => true],
                    ['label' => 'Re-sincronizar', 'disabled' => true],
                    ['label' => 'Copiar email', 'disabled' => true],
                ],
                'layout' => 'detail',
                'metric_cards' => [
                    ['label' => 'Estado sync', 'truth' => 'instrumentacion'],
                    ['label' => 'Último evento', 'truth' => 'disponible'],
                    ['label' => 'Errores abiertos', 'truth' => 'disponible'],
                    ['label' => 'Saldo crédito', 'truth' => 'disponible'],
                ],
                'charts' => [
                    ['title' => 'Timeline del journey', 'truth' => 'proxima_fase'],
                ],
                'tables' => [
                    ['title' => 'Eventos recientes', 'columns' => ['Fecha', 'Evento', 'Canal', 'Resultado']],
                    ['title' => 'Tags aplicados', 'columns' => ['Tag', 'Origen', 'Fecha']],
                ],
                'notes' => [
                    'Placeholder de detalle. En producción se abrirá con un contacto concreto.',
                ],
            ],
            'customer-journey' => [
                'key' => 'customer-journey',
                'title' => 'Customer Journey',
                'description' => 'Recorrido narrativo del paciente a través de eventos de marketing.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Journey',
                'actions' => [
                    ['label' => 'Buscar paciente', 'disabled' => true],
                    ['label' => 'Solo errores', 'disabled' => true],
                ],
                'layout' => 'journey',
                'charts' => [
                    ['title' => 'Línea de tiempo', 'truth' => 'proxima_fase'],
                ],
                'tables' => [
                    ['title' => 'Detalle del evento seleccionado', 'columns' => ['Campo', 'Valor']],
                ],
                'notes' => [
                    'Opens, clicks y SMS aparecerán cuando exista instrumentación de engagement.',
                ],
            ],
            'automations' => [
                'key' => 'automations',
                'title' => 'Automatizaciones',
                'description' => 'Inventario de automatizaciones y su relación con triggers de Famedic.',
                'phase' => 'instrumentacion',
                'phase_label' => 'Requiere sincronización',
                'badge' => 'Automation',
                'actions' => [
                    ['label' => 'Refrescar desde AC', 'disabled' => true],
                    ['label' => 'Exportar', 'disabled' => true],
                ],
                'layout' => 'list',
                'metric_cards' => [
                    ['label' => 'Activas', 'truth' => 'instrumentacion'],
                    ['label' => 'Pausadas', 'truth' => 'instrumentacion'],
                    ['label' => 'Entradas hoy', 'truth' => 'instrumentacion'],
                ],
                'tables' => [
                    ['title' => 'Automatizaciones', 'columns' => ['Nombre', 'Trigger', 'Estado', 'Entradas', 'Última corrida']],
                    ['title' => 'Triggers conocidos en Famedic', 'columns' => ['Tag / Evento', 'Uso', 'Estado']],
                ],
                'notes' => [
                    'Los conteos live viven en ActiveCampaign hasta instrumentar lectura o webhooks.',
                ],
            ],
            'funnels' => [
                'key' => 'funnels',
                'title' => 'Funnels Intelligence',
                'description' => 'Embudos de conversión de recorridos reales: general, laboratorios, farmacia y membresías.',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Conversión',
                'actions' => [
                    ['label' => 'Dashboard', 'href' => 'admin.activecampaign.dashboard', 'disabled' => false],
                    ['label' => 'Analytics', 'href' => 'admin.activecampaign.analytics', 'disabled' => false],
                    ['label' => 'Event Center', 'href' => 'admin.activecampaign.events', 'disabled' => false],
                ],
                'layout' => 'funnel',
                'metric_cards' => [
                    ['label' => 'Registros', 'truth' => 'proxy'],
                    ['label' => 'Compras lab/farmacia', 'truth' => 'proxy'],
                    ['label' => 'Abandonos', 'truth' => 'disponible'],
                    ['label' => 'Conversión cohort', 'truth' => 'instrumentacion'],
                ],
                'charts' => [
                    ['title' => 'Embudo seleccionado', 'truth' => 'proxy'],
                    ['title' => 'Comparativo periodo', 'truth' => 'proxy'],
                ],
                'notes' => [
                    'Volúmenes desde Dashboard; tasas entre etapas y GMV = Próximamente / Requiere instrumentación.',
                ],
            ],
            'events' => [
                'key' => 'events',
                'title' => 'Eventos',
                'description' => 'Catálogo de eventos de marketing emitidos o planificados por Famedic.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Catálogo',
                'actions' => [
                    ['label' => 'Filtrar familia', 'disabled' => true],
                    ['label' => 'Ver logs', 'href' => 'admin.activecampaign.logs', 'disabled' => false],
                ],
                'layout' => 'list',
                'metric_cards' => [
                    ['label' => 'Instrumentados', 'truth' => 'disponible'],
                    ['label' => 'Solo proxy', 'truth' => 'proxy'],
                    ['label' => 'Futuros', 'truth' => 'proximamente'],
                ],
                'tables' => [
                    ['title' => 'Catálogo de eventos', 'columns' => ['Evento', 'Familia', 'Volumen 7d', 'Estado dato', 'Última vez']],
                ],
            ],
            'tags' => [
                'key' => 'tags',
                'title' => 'Tags Manager',
                'description' => 'Consola para administrar, analizar y entender los tags del ecosistema Famedic × ActiveCampaign.',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Administration',
                'actions' => [
                    ['label' => 'Automation Center', 'href' => 'admin.activecampaign.automations', 'disabled' => false],
                    ['label' => 'Customer Journey', 'href' => 'admin.activecampaign.customer-journey', 'disabled' => false],
                    ['label' => 'QA vs Prod', 'href' => 'admin.activecampaign.qa-compare', 'disabled' => false],
                ],
                'layout' => 'tags',
                'metric_cards' => [
                    ['label' => 'Total tags', 'truth' => 'disponible'],
                    ['label' => 'Tags activos', 'truth' => 'disponible'],
                    ['label' => 'Tags sin uso', 'truth' => 'proxy'],
                    ['label' => 'Automáticos', 'truth' => 'disponible'],
                    ['label' => 'Manuales', 'truth' => 'proxy'],
                ],
                'tables' => [
                    ['title' => 'Catálogo', 'columns' => ['Nombre', 'Descripción', 'Contactos', 'Origen', 'Automatización', 'Último uso', 'Estado']],
                ],
                'notes' => [
                    'Composición: ActiveCampaign /tags + config FM-* + dispatches. Sin mutaciones en v1.',
                ],
            ],
            'fields' => [
                'key' => 'fields',
                'title' => 'Custom Fields Manager',
                'description' => 'Consola para visualizar, analizar y administrar los campos personalizados Famedic × ActiveCampaign.',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Administration',
                'actions' => [
                    ['label' => 'Tags Manager', 'href' => 'admin.activecampaign.tags', 'disabled' => false],
                    ['label' => 'Analytics', 'href' => 'admin.activecampaign.analytics', 'disabled' => false],
                    ['label' => 'QA vs Prod', 'href' => 'admin.activecampaign.qa-compare', 'disabled' => false],
                ],
                'layout' => 'fields',
                'metric_cards' => [
                    ['label' => 'Campos totales', 'truth' => 'disponible'],
                    ['label' => 'Activos', 'truth' => 'disponible'],
                    ['label' => 'Obligatorios', 'truth' => 'proxy'],
                    ['label' => 'Opcionales', 'truth' => 'proxy'],
                    ['label' => 'Sincronizados', 'truth' => 'disponible'],
                    ['label' => 'Sin uso', 'truth' => 'proxy'],
                ],
                'tables' => [
                    ['title' => 'Catálogo', 'columns' => ['Nombre', 'Tipo', 'Obligatorio', 'Sincronizado', 'Contactos', 'Origen', 'Último uso', 'Estado']],
                ],
                'notes' => [
                    'Composición: getFields() + config fields + dispatches. Sin mutaciones en v1.',
                ],
            ],
            'ecommerce' => [
                'key' => 'ecommerce',
                'title' => 'Ecommerce Intelligence',
                'description' => 'Consola ejecutiva comercial: GMV, conversión y mix Lab / Farmacia / Membresías.',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Commerce',
                'actions' => [
                    ['label' => 'Laboratory Intelligence', 'href' => 'admin.activecampaign.laboratories', 'disabled' => false],
                    ['label' => 'Membership Intelligence', 'href' => 'admin.activecampaign.memberships', 'disabled' => false],
                    ['label' => 'Analytics', 'href' => 'admin.activecampaign.analytics', 'disabled' => false],
                ],
                'layout' => 'dashboard',
                'metric_cards' => [
                    ['label' => 'GMV', 'truth' => 'disponible'],
                    ['label' => 'Ingreso neto', 'truth' => 'proxy'],
                    ['label' => 'Conversión', 'truth' => 'disponible'],
                    ['label' => 'Atribución campañas', 'truth' => 'instrumentacion'],
                ],
                'charts' => [
                    ['title' => 'Tendencias GMV', 'truth' => 'disponible'],
                    ['title' => 'Por canal', 'truth' => 'disponible'],
                ],
                'tables' => [
                    ['title' => 'Distribución por línea', 'columns' => ['Canal', 'Pedidos', 'GMV', 'Participación']],
                    ['title' => 'Top productos', 'columns' => ['Producto', 'Canal', 'Cantidad', 'Ingreso']],
                ],
            ],
            'laboratories' => [
                'key' => 'laboratories',
                'title' => 'Laboratory Intelligence',
                'description' => 'Consola ejecutiva del negocio de laboratorios: ventas, estudios, tendencias e insights.',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Lab',
                'actions' => [
                    ['label' => 'Dashboard', 'href' => 'admin.activecampaign.dashboard', 'disabled' => false],
                    ['label' => 'Funnel Lab', 'href' => 'admin.activecampaign.funnels', 'disabled' => false],
                    ['label' => 'Analytics', 'href' => 'admin.activecampaign.analytics', 'disabled' => false],
                ],
                'layout' => 'dashboard',
                'metric_cards' => [
                    ['label' => 'Ventas', 'truth' => 'disponible'],
                    ['label' => 'Compras', 'truth' => 'disponible'],
                    ['label' => 'Resultados', 'truth' => 'disponible'],
                    ['label' => 'Conversión carrito', 'truth' => 'instrumentacion'],
                ],
                'charts' => [
                    ['title' => 'Tendencias', 'truth' => 'disponible'],
                    ['title' => 'Por laboratorio / ciudad', 'truth' => 'disponible'],
                ],
                'tables' => [
                    ['title' => 'Top laboratorios', 'columns' => ['Lab', 'Ingresos', 'Órdenes', 'Crecimiento', 'Participación']],
                    ['title' => 'Top estudios', 'columns' => ['Estudio', 'Cantidad', 'Ingreso', 'Crecimiento']],
                ],
            ],
            'memberships' => [
                'key' => 'memberships',
                'title' => 'Membership Intelligence',
                'description' => 'Consola ejecutiva del negocio de membresías: activas, altas, ingresos, beneficiarios y tendencias.',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Lifecycle',
                'actions' => [
                    ['label' => 'Dashboard', 'href' => 'admin.activecampaign.dashboard', 'disabled' => false],
                    ['label' => 'Funnel Membresías', 'href' => 'admin.activecampaign.funnels', 'disabled' => false],
                    ['label' => 'Analytics', 'href' => 'admin.activecampaign.analytics', 'disabled' => false],
                ],
                'layout' => 'dashboard',
                'metric_cards' => [
                    ['label' => 'Activas', 'truth' => 'disponible'],
                    ['label' => 'Nuevas', 'truth' => 'proxy'],
                    ['label' => 'Ingresos', 'truth' => 'disponible'],
                    ['label' => 'Renovaciones', 'truth' => 'instrumentacion'],
                ],
                'charts' => [
                    ['title' => 'Tendencias', 'truth' => 'disponible'],
                    ['title' => 'Por tipo / ciudad', 'truth' => 'proxy'],
                ],
                'tables' => [
                    ['title' => 'Distribución por tipo', 'columns' => ['Tipo', 'Altas', 'Ingresos']],
                ],
            ],
            'notifications' => [
                'key' => 'notifications',
                'title' => 'Notification Center',
                'description' => 'Centro de prioridades: consolida señales de Dashboard, Event Center, Health y Automation.',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Ops',
                'actions' => [
                    ['label' => 'Ir al CRM', 'href' => 'admin.activecampaign.contacts', 'disabled' => false],
                    ['label' => 'Event Center', 'href' => 'admin.activecampaign.events', 'disabled' => false],
                ],
                'layout' => 'list',
                'metric_cards' => [
                    ['label' => 'Críticas', 'truth' => 'disponible'],
                    ['label' => 'Advertencias', 'truth' => 'disponible'],
                    ['label' => 'Información', 'truth' => 'disponible'],
                    ['label' => 'Resueltas', 'truth' => 'disponible'],
                ],
                'tables' => [
                    ['title' => 'Bandeja', 'columns' => ['Prioridad', 'Título', 'Paciente', 'Origen', 'Fecha', 'Estado']],
                ],
            ],
            'alerts' => [
                'key' => 'alerts',
                'title' => 'Alerts Center',
                'description' => 'Consola de alertas inteligentes: operativas, comerciales, lab, membresías y automatizaciones.',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Ops',
                'actions' => [
                    ['label' => 'Health Center', 'href' => 'admin.activecampaign.health', 'disabled' => false],
                    ['label' => 'Event Center', 'href' => 'admin.activecampaign.events', 'disabled' => false],
                    ['label' => 'Notification Center', 'href' => 'admin.activecampaign.notifications', 'disabled' => false],
                ],
                'layout' => 'list',
                'metric_cards' => [
                    ['label' => 'Críticas', 'truth' => 'disponible'],
                    ['label' => 'Advertencias', 'truth' => 'disponible'],
                    ['label' => 'Resueltas', 'truth' => 'disponible'],
                    ['label' => 'Tiempo resolución', 'truth' => 'instrumentacion'],
                ],
                'tables' => [
                    ['title' => 'Bandeja', 'columns' => ['Nivel', 'Título', 'Origen', 'Paciente', 'Fecha', 'Estado']],
                ],
            ],
            'logs' => [
                'key' => 'logs',
                'title' => 'Logs Center',
                'description' => 'Consola de investigación de incidentes (dispatches, Health, Automation, Alertas).',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Administration',
                'actions' => [
                    ['label' => 'Event Center', 'href' => 'admin.activecampaign.events', 'disabled' => false],
                    ['label' => 'Alerts Center', 'href' => 'admin.activecampaign.alerts', 'disabled' => false],
                    ['label' => 'Health Center', 'href' => 'admin.activecampaign.health', 'disabled' => false],
                ],
                'layout' => 'logs',
                'tables' => [
                    ['title' => 'Listado', 'columns' => ['Fecha', 'Hora', 'Nivel', 'Origen', 'Módulo', 'Evento', 'Estado']],
                ],
                'notes' => [
                    'Capa de composición: no lee laravel.log. Payload sanitizado. Panel ejecutivo diferido.',
                ],
            ],
            'health' => [
                'key' => 'health',
                'title' => 'Health Center',
                'description' => 'Scorecard de salud de Marketing Intelligence y la integración ActiveCampaign.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Salud',
                'actions' => [
                    ['label' => 'Ver alertas', 'href' => 'admin.activecampaign.alerts', 'disabled' => false],
                    ['label' => 'Comparador QA/Prod', 'href' => 'admin.activecampaign.qa-compare', 'disabled' => false],
                    ['label' => 'Configuración', 'href' => 'admin.activecampaign.settings', 'disabled' => false],
                ],
                'layout' => 'health',
                'metric_cards' => [
                    ['label' => 'Score general', 'truth' => 'proxima_fase'],
                    ['label' => 'Integración ON', 'truth' => 'disponible'],
                    ['label' => 'Tasa de error 7d', 'truth' => 'disponible'],
                    ['label' => 'Webhooks inbound', 'truth' => 'instrumentacion'],
                ],
                'charts' => [
                    ['title' => 'Histórico de score', 'truth' => 'proximamente'],
                ],
                'tables' => [
                    ['title' => 'Checklist de salud', 'columns' => ['Control', 'Estado', 'Detalle']],
                ],
            ],
            'qa-compare' => [
                'key' => 'qa-compare',
                'title' => 'QA vs Production',
                'description' => 'Consola de comparación entre ambientes para detectar drifts de configuración (solo lectura).',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Governance',
                'actions' => [
                    ['label' => 'Configuration Center', 'href' => 'admin.activecampaign.settings', 'disabled' => false],
                    ['label' => 'Health Center', 'href' => 'admin.activecampaign.health', 'disabled' => false],
                    ['label' => 'Integrations Hub', 'href' => 'admin.activecampaign.integrations', 'disabled' => false],
                ],
                'layout' => 'qa-compare',
                'metric_cards' => [
                    ['label' => 'Estado QA', 'truth' => 'proxy'],
                    ['label' => 'Estado Producción', 'truth' => 'proxy'],
                    ['label' => 'Diferencias', 'truth' => 'proxy'],
                    ['label' => 'Integraciones distintas', 'truth' => 'proxy'],
                    ['label' => 'Feature Flags distintas', 'truth' => 'proxy'],
                    ['label' => 'Críticas', 'truth' => 'proxy'],
                ],
                'tables' => [
                    ['title' => 'Comparador', 'columns' => ['Nombre', 'Categoría', 'QA', 'Producción', 'Estado']],
                ],
                'notes' => [
                    'No compara servidores reales. Solo presencia/ausencia. Remoto = requiere instrumentación.',
                ],
            ],
            'settings' => [
                'key' => 'settings',
                'title' => 'Configuration Center',
                'description' => 'Consola de gobernanza: visualiza y analiza la configuración de Marketing Intelligence (solo lectura).',
                'phase' => 'disponible',
                'phase_label' => 'Disponible',
                'badge' => 'Governance',
                'actions' => [
                    ['label' => 'Health Center', 'href' => 'admin.activecampaign.health', 'disabled' => false],
                    ['label' => 'Integrations Hub', 'href' => 'admin.activecampaign.integrations', 'disabled' => false],
                    ['label' => 'QA vs Prod', 'href' => 'admin.activecampaign.qa-compare', 'disabled' => false],
                ],
                'layout' => 'configuration',
                'metric_cards' => [
                    ['label' => 'Configuraciones totales', 'truth' => 'disponible'],
                    ['label' => 'Críticas', 'truth' => 'disponible'],
                    ['label' => 'Feature Flags', 'truth' => 'disponible'],
                    ['label' => 'Integraciones', 'truth' => 'disponible'],
                    ['label' => 'Pendientes', 'truth' => 'disponible'],
                    ['label' => 'Estado general', 'truth' => 'proxy'],
                ],
                'tables' => [
                    ['title' => 'Inventario', 'columns' => ['Nombre', 'Categoría', 'Valor', 'Origen', 'Ambiente', 'Estado', 'Última actualización']],
                ],
                'notes' => [
                    'Solo lectura. Secretos sanitizados. Cambios vía release/.env.',
                ],
            ],
        ];
    }

    /**
     * @return list<array{key: string, label: string, route: string}>
     */
    public static function menu(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'admin.activecampaign.dashboard'],
            ['key' => 'analytics', 'label' => 'Analytics', 'route' => 'admin.activecampaign.analytics'],
            ['key' => 'contacts', 'label' => 'Contactos', 'route' => 'admin.activecampaign.contacts'],
            ['key' => 'customer-journey', 'label' => 'Customer Journey', 'route' => 'admin.activecampaign.customer-journey'],
            ['key' => 'automations', 'label' => 'Automation Center', 'route' => 'admin.activecampaign.automations'],
            ['key' => 'funnels', 'label' => 'Funnels Intelligence', 'route' => 'admin.activecampaign.funnels'],
            ['key' => 'events', 'label' => 'Event Center', 'route' => 'admin.activecampaign.events'],
            ['key' => 'tags', 'label' => 'Tags Manager', 'route' => 'admin.activecampaign.tags'],
            ['key' => 'fields', 'label' => 'Custom Fields Manager', 'route' => 'admin.activecampaign.fields'],
            ['key' => 'ecommerce', 'label' => 'Ecommerce Intelligence', 'route' => 'admin.activecampaign.ecommerce'],
            ['key' => 'laboratories', 'label' => 'Laboratory Intelligence', 'route' => 'admin.activecampaign.laboratories'],
            ['key' => 'memberships', 'label' => 'Membership Intelligence', 'route' => 'admin.activecampaign.memberships'],
            ['key' => 'notifications', 'label' => 'Notification Center', 'route' => 'admin.activecampaign.notifications'],
            ['key' => 'alerts', 'label' => 'Alerts Center', 'route' => 'admin.activecampaign.alerts'],
            ['key' => 'logs', 'label' => 'Logs Center', 'route' => 'admin.activecampaign.logs'],
            ['key' => 'health', 'label' => 'Health Center', 'route' => 'admin.activecampaign.health'],
            ['key' => 'integrations', 'label' => 'Integrations Hub', 'route' => 'admin.activecampaign.integrations'],
            ['key' => 'qa-compare', 'label' => 'QA vs Production', 'route' => 'admin.activecampaign.qa-compare'],
            ['key' => 'settings', 'label' => 'Configuration Center', 'route' => 'admin.activecampaign.settings'],
        ];
    }

    /**
     * Navegación agrupada (UX) — mismas rutas que menu(), sin nuevas pantallas.
     *
     * @return list<array{key: string, label: string, icon: string, items: list<array{key: string, label: string, route: string}>}>
     */
    public static function menuGroups(): array
    {
        $byKey = collect(self::menu())->keyBy('key');

        $pick = static function (array $keys) use ($byKey): array {
            return collect($keys)
                ->map(static fn (string $key) => $byKey->get($key))
                ->filter()
                ->values()
                ->all();
        };

        return [
            [
                'key' => 'executive',
                'label' => 'Executive Intelligence',
                'icon' => 'PresentationChartLineIcon',
                'items' => $pick(['dashboard', 'analytics', 'funnels']),
            ],
            [
                'key' => 'customer',
                'label' => 'Customer Intelligence',
                'icon' => 'UsersIcon',
                'items' => $pick(['contacts', 'customer-journey']),
            ],
            [
                'key' => 'business',
                'label' => 'Business Intelligence',
                'icon' => 'BuildingStorefrontIcon',
                'items' => $pick(['laboratories', 'memberships', 'ecommerce']),
            ],
            [
                'key' => 'operations',
                'label' => 'Operations Center',
                'icon' => 'ClipboardDocumentListIcon',
                'items' => $pick([
                    'automations',
                    'events',
                    'health',
                    'alerts',
                    'logs',
                    'notifications',
                ]),
            ],
            [
                'key' => 'governance',
                'label' => 'Platform Governance',
                'icon' => 'ShieldCheckIcon',
                'items' => $pick([
                    'integrations',
                    'tags',
                    'fields',
                    'settings',
                    'qa-compare',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        $pages = self::pages();

        if (! isset($pages[$key])) {
            abort(404);
        }

        return $pages[$key];
    }
}
