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
                'title' => 'Funnels',
                'description' => 'Embudos de conversión: registro, carrito, compra, membresía y créditos.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Conversión',
                'actions' => [
                    ['label' => 'Embudo laboratorio', 'disabled' => true],
                    ['label' => 'Embudo farmacia', 'disabled' => true],
                    ['label' => 'Exportar', 'disabled' => true],
                ],
                'layout' => 'funnel',
                'metric_cards' => [
                    ['label' => 'Tasa registro → carrito', 'truth' => 'proxy'],
                    ['label' => 'Tasa carrito → compra', 'truth' => 'proxy'],
                    ['label' => 'Recuperación abandono', 'truth' => 'disponible'],
                ],
                'charts' => [
                    ['title' => 'Embudo principal', 'truth' => 'proxima_fase'],
                    ['title' => 'Atribución de campaña', 'truth' => 'instrumentacion'],
                ],
                'notes' => [
                    'Las etapas se alimentarán con proxies de dominio y sync auditado.',
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
                'title' => 'Tags',
                'description' => 'Diccionario de tags legacy y FM-* usados por la integración.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Taxonomía',
                'actions' => [
                    ['label' => 'Solo conflictivos', 'disabled' => true],
                    ['label' => 'Comparar QA/Prod', 'href' => 'admin.activecampaign.qa-compare', 'disabled' => false],
                ],
                'layout' => 'cards',
                'metric_cards' => [
                    ['label' => 'Tags legacy', 'truth' => 'proxy'],
                    ['label' => 'Tags cupones', 'truth' => 'disponible'],
                    ['label' => 'Sin resolver', 'truth' => 'instrumentacion'],
                ],
                'tables' => [
                    ['title' => 'Catálogo de tags', 'columns' => ['Nombre / ID', 'Familia', 'Uso', 'Estado']],
                ],
            ],
            'fields' => [
                'key' => 'fields',
                'title' => 'Campos personalizados',
                'description' => 'Mapa de custom fields de identidad y cupones sincronizados con ActiveCampaign.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Datos',
                'actions' => [
                    ['label' => 'Comparar ambientes', 'href' => 'admin.activecampaign.qa-compare', 'disabled' => false],
                    ['label' => 'Copiar nombres', 'disabled' => true],
                ],
                'layout' => 'list',
                'tables' => [
                    ['title' => 'Campos', 'columns' => ['Campo', 'Referencia', 'Grupo', 'Usado por', 'Estado']],
                ],
            ],
            'ecommerce' => [
                'key' => 'ecommerce',
                'title' => 'Ecommerce',
                'description' => 'Órdenes de laboratorio y farmacia con lente de marketing.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Commerce',
                'actions' => [
                    ['label' => 'Filtrar canal', 'disabled' => true],
                    ['label' => 'Exportar', 'disabled' => true],
                ],
                'layout' => 'dashboard',
                'metric_cards' => [
                    ['label' => 'Órdenes laboratorio', 'truth' => 'proxy'],
                    ['label' => 'Órdenes farmacia', 'truth' => 'proxy'],
                    ['label' => 'Ticket promedio', 'truth' => 'proxy'],
                    ['label' => 'Sync confirmado', 'truth' => 'instrumentacion'],
                ],
                'charts' => [
                    ['title' => 'Órdenes por día', 'truth' => 'proxy'],
                ],
                'tables' => [
                    ['title' => 'Órdenes recientes', 'columns' => ['ID', 'Canal', 'Total', 'Fecha', 'Señal marketing']],
                ],
            ],
            'laboratories' => [
                'key' => 'laboratories',
                'title' => 'Laboratorios',
                'description' => 'Pipeline clínico-marketing: compra, muestra, resultados y factura.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Lab',
                'actions' => [
                    ['label' => 'Ver notificaciones lab', 'disabled' => true],
                    ['label' => 'Exportar', 'disabled' => true],
                ],
                'layout' => 'funnel',
                'metric_cards' => [
                    ['label' => 'Compra', 'truth' => 'proxy'],
                    ['label' => 'Muestra', 'truth' => 'proxy'],
                    ['label' => 'Resultados', 'truth' => 'proxy'],
                    ['label' => 'Factura', 'truth' => 'proxy'],
                ],
                'charts' => [
                    ['title' => 'Etapas del pipeline', 'truth' => 'proxima_fase'],
                ],
                'tables' => [
                    ['title' => 'Notificaciones recientes', 'columns' => ['Pedido', 'Tipo', 'Email enviado', 'Tag']],
                ],
            ],
            'memberships' => [
                'key' => 'memberships',
                'title' => 'Membresías',
                'description' => 'Altas y bajas de membresía como señales de lifecycle marketing.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Lifecycle',
                'actions' => [
                    ['label' => 'Filtrar estado', 'disabled' => true],
                    ['label' => 'Exportar', 'disabled' => true],
                ],
                'layout' => 'dashboard',
                'metric_cards' => [
                    ['label' => 'Activas', 'truth' => 'proxy'],
                    ['label' => 'Nuevas', 'truth' => 'proxy'],
                    ['label' => 'Terminadas', 'truth' => 'proxy'],
                    ['label' => 'Sync confirmado', 'truth' => 'instrumentacion'],
                ],
                'charts' => [
                    ['title' => 'Altas vs bajas', 'truth' => 'proxy'],
                ],
                'tables' => [
                    ['title' => 'Membresías del periodo', 'columns' => ['Cliente', 'Inicio', 'Fin', 'Estado', 'Señal']],
                ],
            ],
            'alerts' => [
                'key' => 'alerts',
                'title' => 'Alertas',
                'description' => 'Inbox de problemas operativos: fallos de sync, backlog y configuración.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Ops',
                'actions' => [
                    ['label' => 'Solo abiertas', 'disabled' => true],
                    ['label' => 'Reconocer todas', 'disabled' => true],
                ],
                'layout' => 'list',
                'metric_cards' => [
                    ['label' => 'Abiertas', 'truth' => 'disponible'],
                    ['label' => 'Críticas', 'truth' => 'disponible'],
                    ['label' => 'Reconocidas hoy', 'truth' => 'proxima_fase'],
                ],
                'tables' => [
                    ['title' => 'Alertas', 'columns' => ['Severidad', 'Mensaje', 'Cuándo', 'Acción']],
                ],
            ],
            'logs' => [
                'key' => 'logs',
                'title' => 'Logs',
                'description' => 'Explorador de actividad de sincronización y auditoría.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Audit',
                'actions' => [
                    ['label' => 'Exportar', 'disabled' => true],
                    ['label' => 'Filtrar fallidos', 'disabled' => true],
                ],
                'layout' => 'list',
                'tables' => [
                    ['title' => 'Historial', 'columns' => ['Tiempo', 'Evento', 'Email', 'Status', 'Intentos']],
                ],
                'notes' => [
                    'Primera fuente: dispatches de cupones/promos. Unificación con logs de archivo en fase posterior.',
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
                'title' => 'Comparador QA vs Producción',
                'description' => 'Detecta drifts de flags, tags y campos entre ambientes.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'QA',
                'actions' => [
                    ['label' => 'Exportar diff', 'disabled' => true],
                    ['label' => 'Solo drifts', 'disabled' => true],
                ],
                'layout' => 'list',
                'tables' => [
                    ['title' => 'Flags', 'columns' => ['Clave', 'QA', 'Prod', 'Estado']],
                    ['title' => 'Tags', 'columns' => ['Clave', 'QA', 'Prod', 'Estado']],
                    ['title' => 'Campos', 'columns' => ['Clave', 'QA', 'Prod', 'Estado']],
                ],
            ],
            'settings' => [
                'key' => 'settings',
                'title' => 'Configuración',
                'description' => 'Centro de control legible de flags, catálogos y fuentes de datos.',
                'phase' => 'proxima_fase',
                'phase_label' => 'Disponible en la siguiente fase',
                'badge' => 'Config',
                'actions' => [
                    ['label' => 'Abrir Health', 'href' => 'admin.activecampaign.health', 'disabled' => false],
                    ['label' => 'Abrir Comparador', 'href' => 'admin.activecampaign.qa-compare', 'disabled' => false],
                    ['label' => 'Editar in-app', 'disabled' => true],
                ],
                'layout' => 'settings',
                'tables' => [
                    ['title' => 'Estado general', 'columns' => ['Flag', 'Valor', 'Notas']],
                    ['title' => 'Fuentes de datos', 'columns' => ['Fuente', 'Cobertura', 'Estado']],
                ],
                'notes' => [
                    'La edición productiva se hará vía release. La UI in-app quedará como lectura en v1.',
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
            ['key' => 'automations', 'label' => 'Automatizaciones', 'route' => 'admin.activecampaign.automations'],
            ['key' => 'funnels', 'label' => 'Funnels', 'route' => 'admin.activecampaign.funnels'],
            ['key' => 'events', 'label' => 'Eventos', 'route' => 'admin.activecampaign.events'],
            ['key' => 'tags', 'label' => 'Tags', 'route' => 'admin.activecampaign.tags'],
            ['key' => 'fields', 'label' => 'Campos', 'route' => 'admin.activecampaign.fields'],
            ['key' => 'ecommerce', 'label' => 'Ecommerce', 'route' => 'admin.activecampaign.ecommerce'],
            ['key' => 'laboratories', 'label' => 'Laboratorios', 'route' => 'admin.activecampaign.laboratories'],
            ['key' => 'memberships', 'label' => 'Membresías', 'route' => 'admin.activecampaign.memberships'],
            ['key' => 'alerts', 'label' => 'Alertas', 'route' => 'admin.activecampaign.alerts'],
            ['key' => 'logs', 'label' => 'Logs', 'route' => 'admin.activecampaign.logs'],
            ['key' => 'health', 'label' => 'Health Center', 'route' => 'admin.activecampaign.health'],
            ['key' => 'qa-compare', 'label' => 'QA vs Producción', 'route' => 'admin.activecampaign.qa-compare'],
            ['key' => 'settings', 'label' => 'Configuración', 'route' => 'admin.activecampaign.settings'],
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
