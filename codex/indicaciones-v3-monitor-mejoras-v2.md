# Indicaciones IA v3 Mejoras del monitor y siguiente fase de correo

Base: `f3ea57f9346c63842a64721e859833037b78143c`. Rama: `codex/indicaciones-ia-v3-mejoras-v2`. Fecha: 16 de octubre de 2026.

El monitor reorganiza la información existente sin ampliar su universo: compras, incluidas las eliminadas, con resumen persistido o ejecución de preparación asociada. Shadow permanece fuera porque no tiene persistencia consultable. No se modifican reglas R01–R18, prompts, pagos, GDA ni envíos.

## Cambios visuales

Cinco KPI principales (total, AUTO, FALLBACK, revisión, fallos técnicos); métricas restantes desplegables. Búsqueda, fechas, decisión y laboratorio visibles; siete filtros avanzados plegables. Aplicación explícita mediante formulario, limpieza completa y URL compartible. Tabla de cinco columnas en desktop y tarjetas en móvil, manteniendo paginación de 25 compras.

El detalle muestra compra, representación actual del paciente, decisión/revisión, preparación presentada, originales, reglas, diagnóstico técnico e historial persistido. Los JSON se consultan bajo demanda. El retorno conserva filtros y página mediante parámetros locales permitidos. Las compras eliminadas tienen detalle autorizado y no enlazan a una pantalla de compra que no las admite.

## Correcciones semánticas separadas

| Dato | Comportamiento anterior | Contrato implementado |
| --- | --- | --- |
| Ejecución OpenAI | Filtro podía coincidir con cualquier ejecución histórica | Con resumen: ejecución vinculada y del mismo dominio, feature y compra. Sin resumen: última ejecución de preparación por ID. La fila, filtros y métricas usan esa misma selección. |
| FALLBACK sin llamada | Podía heredar una ejecución histórica | Sin ejecución relevante; las anteriores quedan en historial. |
| Pendientes KPI | Toda compra sin resumen, incluso fallida | Sin resumen y ejecución queued/processing. |
| Fallos técnicos | Principalmente resúmenes con fallo | Categoría technical_ai_failure o ejecución relevante failed; incluye fallos sin resumen. |
| Modo pending | Etiqueta ambigua | Se conserva el filtro histórico sin resumen, con etiqueta explícita «Sin resumen (incluye fallos)». No equivale al KPI Pendientes. |
| AUTO/FALLBACK/revisión | Decisión persistida | Se conserva. No acredita que el presenter la esté mostrando; el detalle presenta ambas capas. |
| Llamada al proveedor | Existencia de ejecución equivalía a llamada | queued=false; succeeded=true; processing/failed=desconocido, porque no demuestran una llamada HTTP. |

Los indicadores no son categorías exclusivas y pueden superponerse. La interfaz explica su significado. El universo no incorpora Jobs v3 sin registro: no se inventa un estado pendiente.

El listado resuelve ejecuciones con subconsulta agrupada y agregados SQL; no consulta una ejecución por fila ni carga todos los IDs de resumen en PHP. El historial entrega hasta 20 ejecuciones y señala si existen más. No expone request/response payloads. Los errores crudos se sustituyen por un mensaje seguro y el ID de ejecución. El JSON usa claves de primer nivel permitidas y descarta recursivamente campos sensibles. El contenido clínico autorizado del paciente se conserva.

## Contrato del correo único integrado

El correo único ya está integrado y permanece OFF por defecto. Su arquitectura, correcciones P1/P2 y límites operativos están documentados en `indicaciones-v3-correo-unico.md`; la guía de release es `indicaciones-v3-release-staging.md`. El monitor no modifica por sí mismo los envíos.

- Un correo automático de confirmación por compra nueva a partir de la activación autorizada.
- AUTO vigente y validado cuando esté disponible; originales completos ante FALLBACK, OFF, fallo, indisponibilidad o vencimiento.
- Espera de contenido configurable; valor inicial de 15 segundos validado localmente y pendiente de aceptación operativa en staging.
- Intención durable por compra, plazo absoluto y selección/envío coordinados de manera atómica.
- Resultado IA tardío disponible en portal/PDF, sin segundo correo automático IA.
- Aviso interno conservado y deduplicado, con semántica independiente de entrega de correo.
- Confirmación completa con y sin cita. Concierge independiente para cambios posteriores reales.
- Reenvío administrativo explícito y auditado.
- Entregas ambiguas sin reintento automático; requieren reconciliación o reenvío autorizado.
- Capacidad independiente para coordinación de correo y generación IA. Prioridad en una sola cola no interrumpe un Job bloqueado.
- Recuperación de intenciones omitidas sin enviar correos históricos indiscriminadamente.

Las pruebas locales aisladas de concurrencia, vencimiento, caída de worker, recuperación e idempotencia ya pasaron, incluida la reproducción MySQL corregida de P1. Antes de habilitar el envío, completar la revisión operativa de staging. Verificar la disponibilidad real de workers mediante acceso autorizado; esta revisión local no acredita la configuración de Forge. Diferenciar aceptación del transporte de entrega en bandeja.

## Validación y límites

Las pruebas PHP del monitor se ejecutan con SQLite en memoria y contenedor sin red; no utilizan bases compartidas. La cobertura incluye permisos de listado/detalle, filtros, paginación, ejecuciones históricas, fallos sin resumen, sanitización y consultas constantes para 1 y 25 filas. Las pruebas JavaScript verifican el contrato de filtros y sus etiquetas/fechas. El build Vite valida integración de frontend.

La revisión visual usa las páginas React y el SidebarLayout compartido con datos sintéticos y un adaptador local de Inertia/navegación. No equivale a una sesión autenticada de staging ni a una prueba end-to-end completa. Se comprueba desktop, móvil y reflow equivalente a un viewport reducido al 50 %. El zoom real del navegador al 200 % requiere revisión manual si el control del navegador no lo aplica.
