\---

document\_type: master\_pending\_decision\_register
document\_name: Registro Maestro de Pendientes y Decisiones FAMEDIC-Leo
version: "0.1"
status: working\_draft
language: es-MX
priority\_levels:
P0: critical\_blocker
P1: high
P2: medium
P3: low
closure\_rule: "No cerrar sin decisión, implementación cuando aplique, pruebas y evidencia"
governance:
cadence: weekly
source\_of\_truth: master\_register
---

# Registro Maestro de Pendientes y Decisiones FAMEDIC–Leo

**Versión de trabajo 0.1**

## 1\. Propósito

Este documento consolida en un solo registro los pendientes, decisiones abiertas, dependencias, riesgos y responsables identificados en la documentación funcional, técnica, operativa, jurídica, comercial y de seguridad de FAMEDIC y Leo.

Su objetivo es convertirse en la herramienta central de seguimiento para dirección, producto, tecnología, operaciones, atención, seguridad, privacidad, jurídico, comercial, marketing, finanzas y QA.

El registro debe permitir identificar qué falta definir, quién debe decidir, qué documentos o funciones afecta, qué riesgo existe si no se resuelve, qué depende de esa decisión, qué bloquea la liberación, cuál es el criterio de cierre y qué debe priorizarse para llevar a Leo a producción.

## 2\. Principios de gestión

### PEN-001. Una sola fuente de seguimiento

Los pendientes no deben mantenerse únicamente dentro de documentos separados, correos o conversaciones. Todo pendiente relevante debe registrarse en este control maestro.

### PEN-002. Todo pendiente debe tener responsable

Ningún pendiente debe conservarse sin área responsable, dueño de decisión, siguiente acción y criterio de cierre.

### PEN-003. Distinguir decisión de ejecución

Una decisión responde qué se hará. Una tarea responde cómo y cuándo se implementará. Ambas deben registrarse por separado cuando corresponda.

### PEN-004. Priorizar por riesgo y dependencia

Debe priorizarse primero aquello que bloquea producción, afecta seguridad o privacidad, puede generar cargos o pérdidas, afecta resultados o datos clínicos, impide autenticación o trazabilidad, genera riesgo jurídico o bloquea múltiples módulos.

### PEN-005. No declarar resuelto sin evidencia

Un pendiente solo puede cerrarse cuando exista decisión documentada, aprobación, configuración, contrato de API, evidencia de prueba, documento actualizado, despliegue, responsable confirmado y criterio de aceptación cumplido.

## 3\. Tipos de registro

|Tipo|Definición|
|-|-|
|Decisión|Requiere una definición de negocio, técnica, operativa o jurídica|
|Pendiente técnico|Requiere diseño, configuración o desarrollo|
|Pendiente funcional|Requiere definir comportamiento o alcance|
|Pendiente operativo|Requiere proceso, responsable o protocolo|
|Pendiente jurídico|Requiere validación legal, privacidad o consentimiento|
|Pendiente de seguridad|Requiere controles, autorizaciones o mitigaciones|
|Pendiente comercial|Requiere definir oferta, precio o comunicación|
|Pendiente financiero|Requiere cálculo, política o validación económica|
|Pendiente de datos|Requiere fuente, calidad, catálogo o gobierno|
|Pendiente de QA|Requiere pruebas, evidencias o criterios|
|Riesgo|Situación que puede afectar el proyecto|
|Dependencia|Elemento que condiciona otra tarea o decisión|
|Supuesto|Condición utilizada temporalmente sin validación definitiva|

## 4\. Estados

|Estado|Definición|
|-|-|
|Identificado|Registrado, sin análisis suficiente|
|Pendiente de decisión|Requiere resolución de responsable|
|En análisis|Se está evaluando|
|Definido|Decisión tomada, falta implementación|
|En desarrollo|En construcción|
|En pruebas|En validación|
|Bloqueado|No puede avanzar por una dependencia|
|Aprobado|Validado por responsables|
|Implementado|Configurado o desplegado|
|Cerrado|Cumple criterio de cierre|
|Descartado|Se decidió no continuar|
|Pospuesto|Fuera de la fase actual|
|Reabierto|Se detectó nueva evidencia o cambio|

## 5\. Prioridades

* **P0 — Crítica:** debe resolverse antes de cualquier liberación.
* **P1 — Alta:** bloquea una función importante o genera riesgo significativo.
* **P2 — Media:** afecta experiencia, eficiencia o escalabilidad, pero puede no bloquear el lanzamiento mínimo.
* **P3 — Baja:** puede resolverse después de la primera liberación.

## 6\. Criterios de bloqueo

Un pendiente se considera bloqueante cuando impide autenticación, autorización, consulta de datos dinámicos, acceso seguro a resultados o facturas, trazabilidad, protección de datos, escalamiento, operación de atención, uso legal del canal, conciliación financiera o funcionamiento estable de APIs.

## 7\. Estructura del registro

|Campo|Descripción|
|-|-|
|pending\_id|Identificador único|
|title|Nombre breve|
|type|Tipo de pendiente|
|module|Módulo afectado|
|description|Descripción completa|
|decision\_required|Decisión necesaria|
|impact|Crítico, alto, medio o bajo|
|priority|P0–P3|
|risk|Riesgo asociado|
|dependency|Elemento del que depende|
|blocks\_release|Sí o no|
|affected\_documents|Documentos impactados|
|owner\_area|Área responsable|
|decision\_owner|Persona o rol que decide|
|execution\_owner|Persona o rol que implementa|
|target\_date|Fecha objetivo|
|status|Estado|
|next\_action|Próximo paso|
|acceptance\_criteria|Condición de cierre|
|evidence|Evidencia de resolución|
|notes|Observaciones|



## 8\. Decisiones críticas de lanzamiento

### PEN-LAN-001. Alcance exacto de Leo en la primera versión

* **Tipo:** Decisión funcional
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir lista cerrada de capacidades habilitadas en producción
* **Decisión requerida:** Aprobar qué consulta, modifica, prepara y queda fuera de alcance
* **Riesgo:** Liberar funciones sin control o dejar vacíos operativos
* **Dependencia:** Catálogo de intenciones; APIs; seguridad; QA
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Dirección
* **Siguiente acción:** Aprobar alcance MVP
* **Criterio de cierre:** Lista aprobada con estado Habilitada en producción

### PEN-LAN-002. Fecha y estrategia de liberación

* **Tipo:** Decisión ejecutiva
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir piloto, segmento, canal y despliegue por fases
* **Decisión requerida:** Aprobar plan de liberación y reversión
* **Riesgo:** Lanzamiento descoordinado
* **Dependencia:** PEN-LAN-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Dirección/Producto
* **Siguiente acción:** Definir fases y criterios de rollback
* **Criterio de cierre:** Plan firmado con responsables y fechas

### PEN-LAN-003. Canal inicial de Leo

* **Tipo:** Decisión funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir WhatsApp, portal o ambos
* **Decisión requerida:** Aprobar canal inicial y validación de sesión entre canales
* **Riesgo:** Duplicidad de sesión y control de identidad insuficiente
* **Dependencia:** Sesiones; jurídico; arquitectura
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Tecnología/Jurídico
* **Siguiente acción:** Comparar riesgos por canal
* **Criterio de cierre:** Canal inicial aprobado y documentado



## 9\. Identidad, autenticación y sesión

### PEN-AUT-001. Duración de sesión

* **Tipo:** Pendiente de seguridad
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir duración total, inactividad, renovación y cierre
* **Decisión requerida:** Aprobar política de sesión
* **Riesgo:** Acceso indebido o fricción excesiva
* **Dependencia:** Arquitectura de identidad
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/Tecnología
* **Siguiente acción:** Proponer política y probar escenarios
* **Criterio de cierre:** Política aprobada y configurada

### PEN-AUT-002. Sesiones múltiples

* **Tipo:** Pendiente de seguridad
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir concurrencia, notificaciones y revocación
* **Decisión requerida:** Aprobar política de sesiones simultáneas
* **Riesgo:** Uso no autorizado o confusión de sesión
* **Dependencia:** PEN-AUT-001
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Seguridad/Tecnología
* **Siguiente acción:** Diseñar opciones
* **Criterio de cierre:** Política documentada y probada

### PEN-AUT-003. Recuperación de contraseña

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir canal, vigencia, reintentos, bloqueo y auditoría
* **Decisión requerida:** Aprobar flujo de recuperación
* **Riesgo:** Secuestro o pérdida de cuenta
* **Dependencia:** Proveedor de identidad
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Seguridad
* **Siguiente acción:** Documentar flujo end-to-end
* **Criterio de cierre:** Flujo probado con evidencia

### PEN-AUT-004. Revalidación de correo y teléfono

* **Tipo:** Pendiente de seguridad
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir verificación cuando cambien datos de acceso
* **Decisión requerida:** Aprobar factor y secuencia de revalidación
* **Riesgo:** Secuestro de cuenta
* **Dependencia:** OTP y sesión
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/Producto
* **Siguiente acción:** Diseñar casos de cambio
* **Criterio de cierre:** Controles implementados y probados

### PEN-AUT-005. Política de OTP

* **Tipo:** Pendiente de seguridad
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir duración, intentos, bloqueo, reenvío y asociación a acción
* **Decisión requerida:** Aprobar política OTP SMS
* **Riesgo:** Acceso indebido a resultados o facturas
* **Dependencia:** Proveedor SMS
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/Tecnología
* **Siguiente acción:** Proponer parámetros
* **Criterio de cierre:** Política aprobada, configurada y probada

### PEN-AUT-006. Cambio de canal

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir validación al pasar portal↔WhatsApp
* **Decisión requerida:** Aprobar transferencia o reautenticación
* **Riesgo:** Herencia indebida de sesión
* **Dependencia:** PEN-LAN-003; PEN-AUT-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Seguridad
* **Siguiente acción:** Diseñar secuencia segura
* **Criterio de cierre:** Flujo aprobado y probado



## 10\. Registro y cuenta

### PEN-REG-001. Registro completo mediante Leo

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir datos capturados por Leo y por componente seguro
* **Decisión requerida:** Aprobar alcance de registro y consentimientos
* **Riesgo:** Exposición de datos o abandono
* **Dependencia:** Jurídico; OTP; formularios
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Jurídico
* **Siguiente acción:** Diseñar formulario seguro
* **Criterio de cierre:** Registro probado y documentos legales actualizados

### PEN-REG-002. Duplicidad de cuenta

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir prioridad teléfono/correo, recuperación y fusión
* **Decisión requerida:** Aprobar regla de duplicidad
* **Riesgo:** Cuentas duplicadas o inaccesibles
* **Dependencia:** Identidad
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Operaciones
* **Siguiente acción:** Mapear escenarios
* **Criterio de cierre:** Regla implementada y probada

### PEN-REG-003. Eliminación de cuenta

* **Tipo:** Pendiente jurídico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir validación, retención, anonimización y excepciones
* **Decisión requerida:** Aprobar procedimiento de eliminación
* **Riesgo:** Incumplimiento de privacidad
* **Dependencia:** Retención; ARCO
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Privacidad
* **Siguiente acción:** Diseñar política y flujo
* **Criterio de cierre:** Procedimiento aprobado y evidencia de prueba



## 11\. Resultados

### PEN-RES-001. Vigencia de ligas seguras

* **Tipo:** Decisión técnica
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Confirmar vigencia objetivo de una hora
* **Decisión requerida:** Aprobar TTL y revocación
* **Riesgo:** Exposición o indisponibilidad
* **Dependencia:** Arquitectura de ligas
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/Tecnología
* **Siguiente acción:** Validar una hora con jurídico y operación
* **Criterio de cierre:** TTL aprobado y configurado

### PEN-RES-002. Número de aperturas y descargas

* **Tipo:** Pendiente técnico
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir límites, revocación y eventos
* **Decisión requerida:** Aprobar política de uso de liga
* **Riesgo:** Compartición o bloqueo injustificado
* **Dependencia:** PEN-RES-001
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Seguridad/Producto
* **Siguiente acción:** Proponer límites
* **Criterio de cierre:** Política implementada y probada

### PEN-RES-003. Protocolo de resultado no cargado

* **Tipo:** Pendiente operativo
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir responsable, validación con laboratorio y cierre
* **Decisión requerida:** Aprobar protocolo operativo
* **Riesgo:** Afectación de atención y confianza
* **Dependencia:** Escalamiento
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Operaciones/Atención
* **Siguiente acción:** Asignar responsable
* **Criterio de cierre:** Protocolo aprobado y casos de prueba

### PEN-RES-004. Resultado de paciente equivocado

* **Tipo:** Pendiente de seguridad
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Formalizar revocación, evidencia, notificación e investigación
* **Decisión requerida:** Aprobar protocolo de incidente P0
* **Riesgo:** Exposición de datos clínicos
* **Dependencia:** PEN-SEG-004
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/Privacidad
* **Siguiente acción:** Diseñar playbook
* **Criterio de cierre:** Playbook aprobado y simulacro ejecutado



## 12\. Facturación

### PEN-FAC-001. Flujo final de solicitud de factura

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir perfil fiscal, CFDI, periodo, estados y errores
* **Decisión requerida:** Aprobar flujo end-to-end
* **Riesgo:** Errores fiscales y mala experiencia
* **Dependencia:** APIs; perfiles fiscales
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Facturación/Producto
* **Siguiente acción:** Documentar estados
* **Criterio de cierre:** Flujo probado y aprobado

### PEN-FAC-002. Facturación fuera de periodo

* **Tipo:** Decisión operativa
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir excepciones, aprobador y respuesta
* **Decisión requerida:** Aprobar política excepcional
* **Riesgo:** Incumplimiento o promesas indebidas
* **Dependencia:** Política fiscal
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Facturación/Jurídico
* **Siguiente acción:** Definir casos permitidos
* **Criterio de cierre:** Política aprobada

### PEN-FAC-003. Corrección de factura

* **Tipo:** Pendiente operativo
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir cancelación, sustitución y plazos
* **Decisión requerida:** Aprobar procedimiento
* **Riesgo:** Errores fiscales no corregidos
* **Dependencia:** PEN-FAC-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Facturación/Jurídico
* **Siguiente acción:** Documentar procedimiento
* **Criterio de cierre:** Procedimiento aprobado y probado

### PEN-FAC-004. Retención de perfiles fiscales y constancias

* **Tipo:** Pendiente jurídico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir plazo, base legal, acceso y eliminación
* **Decisión requerida:** Aprobar política de retención fiscal
* **Riesgo:** Incumplimiento y exposición de datos
* **Dependencia:** Retención general
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Facturación/Seguridad
* **Siguiente acción:** Emitir política
* **Criterio de cierre:** Política aprobada e implementada



## 13\. Pagos y ODESSA

### PEN-PAG-001. Visualización de saldo Ahorro a la Vista

* **Tipo:** Decisión funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir si Leo muestra saldo y con qué enmascaramiento
* **Decisión requerida:** Aprobar visibilidad y frecuencia
* **Riesgo:** Exposición financiera
* **Dependencia:** Integración ODESSA
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Producto/Seguridad
* **Siguiente acción:** Validar con ODESSA
* **Criterio de cierre:** Regla aprobada y probada

### PEN-PAG-002. Vinculación con ODESSA

* **Tipo:** Pendiente técnico
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir titularidad, revocación y errores
* **Decisión requerida:** Aprobar flujo de vinculación
* **Riesgo:** Acceso a saldo ajeno
* **Dependencia:** Identidad ODESSA
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Tecnología/Seguridad
* **Siguiente acción:** Documentar contrato
* **Criterio de cierre:** Vinculación probada

### PEN-PAG-003. Alta de tarjetas

* **Tipo:** Pendiente técnico
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Implementar proveedor, tokenización y componente seguro
* **Decisión requerida:** Aprobar proveedor y alcance
* **Riesgo:** Riesgo PCI y fraude
* **Dependencia:** Proveedor de pagos
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Tecnología/Pagos
* **Siguiente acción:** Evaluar proveedor
* **Criterio de cierre:** Flujo seguro certificado y probado

### PEN-PAG-004. Pago operado por Leo

* **Tipo:** Decisión funcional
* **Prioridad:** P3
* **Impacto:** Bajo
* **Descripción:** Definir idempotencia, antifraude, conciliación y reversas
* **Decisión requerida:** Aprobar si entra a roadmap
* **Riesgo:** Doble cargo o fraude
* **Dependencia:** PEN-PAG-003; arquitectura financiera
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Dirección/Producto
* **Siguiente acción:** Mantener fuera de alcance inicial
* **Criterio de cierre:** Aprobación formal para fase futura

### PEN-PAG-005. Política de reembolsos

* **Tipo:** Pendiente operativo
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir plazos, estados, evidencia y excepciones
* **Decisión requerida:** Aprobar política
* **Riesgo:** Afectación económica y reclamos
* **Dependencia:** Conciliación
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Finanzas/Operaciones
* **Siguiente acción:** Consolidar reglas
* **Criterio de cierre:** Política aprobada y publicada



## 14\. Laboratorios

### PEN-LAB-001. Endpoints y contratos de laboratorios

* **Tipo:** Pendiente técnico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir búsqueda, alias, precio, preparación, cobertura, cita y sucursal
* **Decisión requerida:** Aprobar contrato API completo
* **Riesgo:** Información dinámica incorrecta
* **Dependencia:** Arquitectura de integración
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Tecnología/Producto
* **Siguiente acción:** Completar inventario
* **Criterio de cierre:** Contratos documentados y probados

### PEN-LAB-002. Fuente de equivalencias

* **Tipo:** Pendiente de datos
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir propietario, actualización y validación
* **Decisión requerida:** Aprobar fuente maestra de alias
* **Riesgo:** Estudios no encontrados o mal mapeados
* **Dependencia:** PEN-LAB-001
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Operaciones/Producto
* **Siguiente acción:** Asignar owner de catálogo
* **Criterio de cierre:** Fuente aprobada y proceso de actualización

### PEN-LAB-003. Cobertura por localidad

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir nivel geográfico y fallback
* **Decisión requerida:** Aprobar precisión de cobertura
* **Riesgo:** Cotización errónea
* **Dependencia:** API de cobertura
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Operaciones
* **Siguiente acción:** Modelar niveles
* **Criterio de cierre:** Regla implementada y probada

### PEN-LAB-004. Tiempos de entrega

* **Tipo:** Pendiente de datos
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Definir si se integran a API y quién mantiene
* **Decisión requerida:** Aprobar política de comunicación
* **Riesgo:** Promesas incorrectas
* **Dependencia:** Laboratorios aliados
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Operaciones
* **Siguiente acción:** Validar disponibilidad por proveedor
* **Criterio de cierre:** Fuente y mensaje aprobados

### PEN-LAB-005. Descuentos y lenguaje comercial

* **Tipo:** Pendiente jurídico
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Validar redacción vigente
* **Decisión requerida:** Aprobar copy comercial
* **Riesgo:** Publicidad engañosa
* **Dependencia:** Jurídico/Marketing
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Marketing/Jurídico
* **Siguiente acción:** Aprobar frase estándar
* **Criterio de cierre:** Copy aprobado y versionado



## 15\. Carrito, checkout y citas

### PEN-CAR-001. Confirmación de cambios de carrito

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir confirmación, resumen y errores parciales
* **Decisión requerida:** Aprobar reglas transaccionales
* **Riesgo:** Cambios no deseados
* **Dependencia:** Idempotencia
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Producto
* **Siguiente acción:** Diseñar casos
* **Criterio de cierre:** Reglas probadas

### PEN-CIT-001. Flujo definitivo de concierge

* **Tipo:** Pendiente operativo
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir activación, horario, transferencia y regreso al checkout
* **Decisión requerida:** Aprobar flujo operativo
* **Riesgo:** Cita incompleta y abandono
* **Dependencia:** Escalamiento; checkout
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Operaciones/Producto
* **Siguiente acción:** Mapear journey
* **Criterio de cierre:** Flujo probado y responsable asignado

### PEN-CIT-002. Estado cita pendiente

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir bloqueo de pago, vigencia y notificaciones
* **Decisión requerida:** Aprobar estados y transiciones
* **Riesgo:** Pago sin cita
* **Dependencia:** PEN-CIT-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Operaciones
* **Siguiente acción:** Diseñar máquina de estados
* **Criterio de cierre:** Estados implementados y probados

### PEN-CHK-001. Checkout por Leo

* **Tipo:** Decisión funcional
* **Prioridad:** P3
* **Impacto:** Bajo
* **Descripción:** Mantener fuera de alcance inicial
* **Decisión requerida:** Aprobar posposición
* **Riesgo:** Complejidad y riesgo financiero
* **Dependencia:** Pagos; seguridad
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Dirección/Producto
* **Siguiente acción:** Documentar fase futura
* **Criterio de cierre:** Posposición aprobada



## 16\. Familiares, pacientes y menores

### PEN-FAM-001. Reglas definitivas de elegibilidad

* **Tipo:** Pendiente funcional
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir parentesco, edades, integrantes y excepciones
* **Decisión requerida:** Aprobar reglas de negocio
* **Riesgo:** Altas indebidas o rechazos incorrectos
* **Dependencia:** Planes
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Operaciones
* **Siguiente acción:** Consolidar reglas
* **Criterio de cierre:** Reglas aprobadas y probadas

### PEN-FAM-002. Tratamiento de menores

* **Tipo:** Pendiente jurídico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir consentimiento, representación y acceso
* **Decisión requerida:** Aprobar política de menores
* **Riesgo:** Riesgo jurídico y de privacidad
* **Dependencia:** PEN-JUR-001; PEN-REG-003
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Privacidad
* **Siguiente acción:** Preparar política
* **Criterio de cierre:** Política aprobada y aplicada

### PEN-PAC-001. Autorización de pacientes frecuentes

* **Tipo:** Pendiente jurídico
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir consentimiento, acceso a resultados y eliminación
* **Decisión requerida:** Aprobar relación titular-paciente
* **Riesgo:** Acceso a datos de terceros
* **Dependencia:** PEN-FAM-002
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Producto
* **Siguiente acción:** Diseñar consentimiento
* **Criterio de cierre:** Flujo aprobado y probado



## 17\. Farmacia

### PEN-FAR-001. Condiciones de reactivación de Farmacia

* **Tipo:** Decisión estratégica
* **Prioridad:** P3
* **Impacto:** Bajo
* **Descripción:** Definir procesador, operación, proveedores y QA
* **Decisión requerida:** Aprobar gate de reactivación
* **Riesgo:** Reactivar sin controles
* **Dependencia:** Proveedor de pagos
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Dirección/Producto
* **Siguiente acción:** Mantener suspendida
* **Criterio de cierre:** Checklist de reactivación aprobado

### PEN-FAR-002. Soporte histórico de Farmacia

* **Tipo:** Pendiente operativo
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Definir equipo, canales y datos disponibles
* **Decisión requerida:** Aprobar protocolo de soporte histórico
* **Riesgo:** Casos antiguos sin atención
* **Dependencia:** Datos históricos
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Operaciones/Atención
* **Siguiente acción:** Asignar responsables
* **Criterio de cierre:** Protocolo aprobado

### PEN-FAR-003. Registro de interés en Farmacia

* **Tipo:** Pendiente jurídico
* **Prioridad:** P3
* **Impacto:** Bajo
* **Descripción:** Definir consentimiento y finalidad
* **Decisión requerida:** Aprobar captura de interés
* **Riesgo:** Uso indebido de datos
* **Dependencia:** Aviso de privacidad
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Marketing/Jurídico
* **Siguiente acción:** Mantener deshabilitado
* **Criterio de cierre:** Consentimiento y flujo aprobados



## 18\. Soporte y escalamiento

### PEN-SOP-001. Sistema de registro de escalamientos

* **Tipo:** Pendiente técnico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir herramienta, estados, colas y auditoría
* **Decisión requerida:** Aprobar solución operativa
* **Riesgo:** Casos sin seguimiento ni trazabilidad
* **Dependencia:** Integración CRM/WhatsApp
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Operaciones/Tecnología
* **Siguiente acción:** Seleccionar herramienta
* **Criterio de cierre:** Sistema implementado y probado

### PEN-SOP-002. Responsables por módulo

* **Tipo:** Pendiente operativo
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Asignar responsables para cuenta, pagos, pedidos, resultados, factura y privacidad
* **Decisión requerida:** Aprobar RACI
* **Riesgo:** Escalamientos sin dueño
* **Dependencia:** PEN-SOP-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Dirección/Operaciones
* **Siguiente acción:** Construir RACI
* **Criterio de cierre:** RACI aprobado

### PEN-SOP-003. Horario de días festivos

* **Tipo:** Pendiente operativo
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Definir calendario especial
* **Decisión requerida:** Aprobar horario festivo
* **Riesgo:** Expectativas incorrectas
* **Dependencia:** Calendario anual
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Operaciones/Atención
* **Siguiente acción:** Definir calendario
* **Criterio de cierre:** Horario publicado y versionado

### PEN-SOP-004. SLA interno

* **Tipo:** Pendiente operativo
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Definir tiempos internos por prioridad
* **Decisión requerida:** Aprobar SLA interno
* **Riesgo:** Casos críticos sin tiempos internos
* **Dependencia:** PEN-SOP-002
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Operaciones
* **Siguiente acción:** Proponer SLA por P0-P3
* **Criterio de cierre:** SLA aprobado internamente



## 19\. Privacidad y jurídico

### PEN-JUR-001. Aviso de Privacidad para Leo

* **Tipo:** Pendiente jurídico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Actualizar para WhatsApp, Leo, APIs, memoria, terceros y menores
* **Decisión requerida:** Aprobar nueva versión
* **Riesgo:** Lanzamiento sin cobertura jurídica
* **Dependencia:** Decisiones de memoria y canal
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Privacidad
* **Siguiente acción:** Cerrar alcance de tratamiento
* **Criterio de cierre:** Versión aprobada y publicada

### PEN-JUR-002. Términos y Condiciones actualizados

* **Tipo:** Pendiente jurídico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Cubrir uso de Leo, autenticación, resultados, pagos y servicios suspendidos
* **Decisión requerida:** Aprobar nueva versión
* **Riesgo:** Riesgo contractual
* **Dependencia:** PEN-LAN-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico
* **Siguiente acción:** Actualizar documento
* **Criterio de cierre:** Versión aprobada y publicada

### PEN-JUR-003. Consentimiento de primer uso

* **Tipo:** Pendiente jurídico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir texto, versión, aceptación, rechazo y evidencia
* **Decisión requerida:** Aprobar mecanismo de consentimiento
* **Riesgo:** Tratamiento sin consentimiento válido
* **Dependencia:** PEN-JUR-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Producto
* **Siguiente acción:** Redactar copy y evento de auditoría
* **Criterio de cierre:** Consentimiento implementado y trazable

### PEN-JUR-004. Memoria conversacional

* **Tipo:** Pendiente jurídico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir finalidad, base, plazo y derechos
* **Decisión requerida:** Aprobar política de memoria
* **Riesgo:** Conservación indebida de datos
* **Dependencia:** PEN-JUR-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Producto
* **Siguiente acción:** Definir categorías y plazos
* **Criterio de cierre:** Política implementada

### PEN-JUR-005. Uso de conversaciones

* **Tipo:** Decisión jurídica
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Mantener exclusión de entrenamiento en esta etapa
* **Decisión requerida:** Aprobar política formal
* **Riesgo:** Uso secundario no autorizado
* **Dependencia:** PEN-JUR-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Dirección
* **Siguiente acción:** Formalizar no-entrenamiento
* **Criterio de cierre:** Configuración y documentos alineados

### PEN-JUR-006. Derechos ARCO

* **Tipo:** Pendiente operativo
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir procedimiento, verificación, responsables y plazos internos
* **Decisión requerida:** Aprobar protocolo ARCO
* **Riesgo:** Incumplimiento regulatorio
* **Dependencia:** PEN-JUR-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Jurídico/Privacidad
* **Siguiente acción:** Documentar proceso
* **Criterio de cierre:** Protocolo aprobado y probado



## 20\. Seguridad

### PEN-SEG-001. Matriz definitiva de roles internos

* **Tipo:** Pendiente de seguridad
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir permisos por recurso y ambiente
* **Decisión requerida:** Aprobar RBAC
* **Riesgo:** Acceso excesivo
* **Dependencia:** Inventario de roles
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/Tecnología
* **Siguiente acción:** Construir RACI/RBAC
* **Criterio de cierre:** Roles configurados y auditados

### PEN-SEG-002. Gestión de secretos

* **Tipo:** Pendiente de seguridad
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir almacenamiento, rotación y revocación
* **Decisión requerida:** Aprobar estándar de secretos
* **Riesgo:** Compromiso de sistemas
* **Dependencia:** Arquitectura cloud
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/Tecnología
* **Siguiente acción:** Inventariar secretos
* **Criterio de cierre:** Estándar implementado y evidencia de rotación

### PEN-SEG-003. Detección de datos sensibles en chat

* **Tipo:** Pendiente técnico
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir detección, redacción y no persistencia
* **Decisión requerida:** Aprobar mecanismo de protección
* **Riesgo:** Exposición en conversación o logs
* **Dependencia:** Plataforma IA
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/IA
* **Siguiente acción:** Diseñar filtros
* **Criterio de cierre:** Pruebas negativas aprobadas

### PEN-SEG-004. Protocolo de incidentes

* **Tipo:** Pendiente de seguridad
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir clasificación, contención, notificación y cierre
* **Decisión requerida:** Aprobar playbook
* **Riesgo:** Respuesta tardía o incompleta
* **Dependencia:** PEN-SOP-002
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Seguridad/Jurídico
* **Siguiente acción:** Redactar playbook
* **Criterio de cierre:** Simulacro ejecutado y aprobado

### PEN-SEG-005. Revisión periódica de accesos

* **Tipo:** Pendiente operativo
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir frecuencia y evidencia
* **Decisión requerida:** Aprobar calendario de recertificación
* **Riesgo:** Privilegios obsoletos
* **Dependencia:** PEN-SEG-001
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Seguridad
* **Siguiente acción:** Proponer periodicidad
* **Criterio de cierre:** Primera revisión completada



## 21\. Datos y gobierno documental

### PEN-DAT-001. Fuente de verdad por módulo

* **Tipo:** Pendiente de datos
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Documentar fuente para usuarios, planes, laboratorios, pedidos, resultados y pagos
* **Decisión requerida:** Aprobar catálogo de fuentes
* **Riesgo:** Respuestas inconsistentes
* **Dependencia:** Arquitectura y owners
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Tecnología
* **Siguiente acción:** Construir mapa de fuentes
* **Criterio de cierre:** Mapa aprobado y versionado

### PEN-DAT-002. Control de versiones

* **Tipo:** Pendiente operativo
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir nomenclatura, owner, aprobador y vigencia
* **Decisión requerida:** Aprobar política documental
* **Riesgo:** Uso de documentos obsoletos
* **Dependencia:** Repositorio
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Producto/Operaciones
* **Siguiente acción:** Definir convención
* **Criterio de cierre:** Política aplicada a todos los documentos

### PEN-DAT-003. Etiquetado de contenido histórico

* **Tipo:** Pendiente de datos
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Aplicar estados y exclusión de respuestas activas
* **Decisión requerida:** Aprobar metadatos y filtros
* **Riesgo:** Recuperación de contenido obsoleto
* **Dependencia:** PEN-DAT-002
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Producto/IA
* **Siguiente acción:** Etiquetar corpus
* **Criterio de cierre:** Muestras de recuperación aprobadas

### PEN-DAT-004. Uso de catálogos estáticos

* **Tipo:** Decisión de datos
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Mantenerlos solo para auditoría y pruebas
* **Decisión requerida:** Aprobar regla de exclusión
* **Riesgo:** Precios o cobertura desactualizados
* **Dependencia:** PEN-DAT-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/Tecnología
* **Siguiente acción:** Formalizar regla
* **Criterio de cierre:** Fuentes estáticas fuera de producción



## 22\. APIs y arquitectura

### PEN-API-001. Inventario definitivo de endpoints

* **Tipo:** Pendiente técnico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Consolidar endpoint, método, auth, payload, errores y owner
* **Decisión requerida:** Aprobar inventario
* **Riesgo:** Integraciones incompletas
* **Dependencia:** PEN-DAT-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Tecnología
* **Siguiente acción:** Completar inventario
* **Criterio de cierre:** Inventario aprobado y versionado

### PEN-API-002. Contratos de respuesta

* **Tipo:** Pendiente técnico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir success, data, request\_id, error\_code y retryable
* **Decisión requerida:** Aprobar contrato estándar
* **Riesgo:** Manejo inconsistente de errores
* **Dependencia:** PEN-API-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Tecnología
* **Siguiente acción:** Publicar esquema
* **Criterio de cierre:** Contrato implementado y validado

### PEN-API-003. Idempotencia

* **Tipo:** Pendiente técnico
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Aplicar a registro, familiares, carrito, facturas, ligas y pagos futuros
* **Decisión requerida:** Aprobar estándar idempotente
* **Riesgo:** Duplicados y doble cargo
* **Dependencia:** PEN-API-002
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Tecnología
* **Siguiente acción:** Definir llave y ventana
* **Criterio de cierre:** Pruebas de duplicidad aprobadas

### PEN-API-004. Timeout y reintentos

* **Tipo:** Pendiente técnico
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir por consulta, modificación, generación y pago
* **Decisión requerida:** Aprobar política de resiliencia
* **Riesgo:** Operaciones inciertas o duplicadas
* **Dependencia:** PEN-API-003
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Tecnología
* **Siguiente acción:** Clasificar operaciones
* **Criterio de cierre:** Política implementada y monitoreada

### PEN-API-005. Monitoreo y alertas

* **Tipo:** Pendiente técnico
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir métricas de disponibilidad, latencia, errores y seguridad
* **Decisión requerida:** Aprobar tablero y umbrales
* **Riesgo:** Fallas no detectadas
* **Dependencia:** PEN-API-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Tecnología/Seguridad
* **Siguiente acción:** Definir SLI/SLO internos
* **Criterio de cierre:** Alertas activas y probadas



## 23\. QA y pruebas

### PEN-QA-001. Plan integral de pruebas

* **Tipo:** Pendiente de QA
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Cubrir funcional, seguridad, privacidad, APIs, regresión y rendimiento
* **Decisión requerida:** Aprobar plan de pruebas
* **Riesgo:** Liberación sin evidencia
* **Dependencia:** Alcance MVP
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** QA/Producto
* **Siguiente acción:** Consolidar casos
* **Criterio de cierre:** Plan aprobado y ejecutado

### PEN-QA-002. Datos de prueba

* **Tipo:** Pendiente de QA
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Definir uso de datos ficticios y excepciones
* **Decisión requerida:** Aprobar política de datos QA
* **Riesgo:** Exposición de datos reales
* **Dependencia:** Seguridad de ambientes
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** QA/Seguridad
* **Siguiente acción:** Generar dataset sintético
* **Criterio de cierre:** Dataset aprobado y disponible

### PEN-QA-003. Criterios de aceptación

* **Tipo:** Pendiente de QA
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Definir caso exitoso, error, permiso, timeout y escalamiento
* **Decisión requerida:** Aprobar criterios por capacidad
* **Riesgo:** Ambigüedad de liberación
* **Dependencia:** PEN-QA-001
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** Producto/QA
* **Siguiente acción:** Vincular a cada capacidad
* **Criterio de cierre:** Criterios firmados y evidencias completas

### PEN-QA-004. Pruebas negativas de Leo

* **Tipo:** Pendiente de QA
* **Prioridad:** P0
* **Impacto:** Crítico
* **Descripción:** Probar prohibiciones: interpretación, credenciales, precios inventados y pagos no habilitados
* **Decisión requerida:** Aprobar set de pruebas negativas
* **Riesgo:** Comportamiento inseguro o engañoso
* **Dependencia:** Guía; seguridad; histórico
* **Bloquea lanzamiento:** Sí
* **Responsable sugerido:** QA/Seguridad/Producto
* **Siguiente acción:** Construir dataset adversarial
* **Criterio de cierre:** Todas las pruebas críticas aprobadas



## 24\. Comercial y negocio

### PEN-COM-001. Recomendación del Plan Básico

* **Tipo:** Pendiente comercial
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Definir contexto, frecuencia y exclusiones
* **Decisión requerida:** Aprobar regla comercial
* **Riesgo:** Promoción invasiva
* **Dependencia:** Guía conversacional
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Marketing/Producto
* **Siguiente acción:** Diseñar reglas
* **Criterio de cierre:** Regla aprobada y medida

### PEN-COM-002. Promociones

* **Tipo:** Pendiente comercial
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Definir fuente, elegibilidad, vigencia y exclusiones
* **Decisión requerida:** Aprobar gobernanza de promociones
* **Riesgo:** Oferta vencida o inoportuna
* **Dependencia:** API/configuración
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Marketing/Producto
* **Siguiente acción:** Definir feed vigente
* **Criterio de cierre:** Promociones dinámicas y auditables

### PEN-COM-003. Referidos

* **Tipo:** Decisión comercial
* **Prioridad:** P3
* **Impacto:** Bajo
* **Descripción:** Definir si habrá incentivos y condiciones
* **Decisión requerida:** Aprobar o descartar incentivos
* **Riesgo:** Promesas no respaldadas
* **Dependencia:** Finanzas/Jurídico
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Marketing/Finanzas
* **Siguiente acción:** Mantener sin incentivos
* **Criterio de cierre:** Decisión formal documentada

### PEN-COM-004. Leads B2B

* **Tipo:** Pendiente comercial
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Definir datos, CRM, responsable y seguimiento
* **Decisión requerida:** Aprobar flujo comercial
* **Riesgo:** Leads perdidos
* **Dependencia:** CRM
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Comercial/Marketing
* **Siguiente acción:** Diseñar formulario y handoff
* **Criterio de cierre:** Lead trazable de punta a punta



## 25\. Finanzas y viabilidad

### PEN-FIN-001. Costo operativo de Leo

* **Tipo:** Pendiente financiero
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Estimar modelo, WhatsApp, SMS, infraestructura, APIs y soporte
* **Decisión requerida:** Aprobar modelo de costos
* **Riesgo:** Margen negativo
* **Dependencia:** Modelo de ingresos FAMEDIC
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Finanzas/Producto
* **Siguiente acción:** Construir escenarios
* **Criterio de cierre:** Costo unitario y total aprobados

### PEN-FIN-002. Costo por conversación y usuario

* **Tipo:** Pendiente financiero
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Calcular costo unitario por canal y volumen
* **Decisión requerida:** Aprobar métrica base
* **Riesgo:** Desconocer impacto en punto de equilibrio
* **Dependencia:** PEN-FIN-001
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Finanzas
* **Siguiente acción:** Definir drivers
* **Criterio de cierre:** Modelo validado con datos reales o supuestos documentados

### PEN-FIN-003. Ahorro operativo esperado

* **Tipo:** Pendiente financiero
* **Prioridad:** P1
* **Impacto:** Alto
* **Descripción:** Medir reducción de consultas, tiempos y errores
* **Decisión requerida:** Aprobar línea base y meta
* **Riesgo:** No demostrar retorno
* **Dependencia:** Datos de atención
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Finanzas/Operaciones
* **Siguiente acción:** Medir baseline
* **Criterio de cierre:** Metas y metodología aprobadas

### PEN-FIN-004. Impacto en conversión

* **Tipo:** Pendiente financiero
* **Prioridad:** P2
* **Impacto:** Medio
* **Descripción:** Medir cotización→carrito→compra y recuperación
* **Decisión requerida:** Aprobar KPIs de conversión
* **Riesgo:** No capturar valor comercial
* **Dependencia:** Analítica digital
* **Bloquea lanzamiento:** No
* **Responsable sugerido:** Producto/Marketing
* **Siguiente acción:** Instrumentar eventos
* **Criterio de cierre:** KPIs disponibles y revisados



## 26\. Secuencia recomendada de resolución

### Bloque 1. Gobierno y alcance

1. alcance inicial;
2. canal inicial;
3. fases de liberación;
4. responsables;
5. fuente de verdad.

### Bloque 2. Jurídico y privacidad

1. Aviso de Privacidad;
2. Términos y Condiciones;
3. consentimiento;
4. memoria;
5. menores;
6. ARCO;
7. retención.

### Bloque 3. Seguridad

1. sesiones;
2. OTP;
3. roles;
4. recursos;
5. secretos;
6. incidentes;
7. logs.

### Bloque 4. APIs

1. inventario;
2. contratos;
3. errores;
4. idempotencia;
5. reintentos;
6. monitoreo.

### Bloque 5. Operación

1. escalamiento;
2. responsables;
3. resultados;
4. facturación;
5. reembolsos;
6. citas;
7. Farmacia histórica.

### Bloque 6. QA

1. datos de prueba;
2. criterios;
3. pruebas negativas;
4. seguridad;
5. regresión;
6. liberación.

## 27\. Reunión de seguimiento

Se recomienda una reunión semanal con producto, tecnología, operaciones, seguridad, jurídico, atención y QA.

Agenda:

1. P0 abiertos;
2. bloqueos;
3. decisiones pendientes;
4. evidencia recibida;
5. riesgos nuevos;
6. fechas;
7. responsables;
8. documentos afectados;
9. cambios de alcance.

## 28\. Reglas de cierre

Un registro puede marcarse como cerrado solo cuando la decisión fue aprobada, el documento afectado fue actualizado, la implementación fue realizada cuando aplica, las pruebas fueron completadas, existe evidencia, el responsable acepta el cierre y no quedan dependencias abiertas.

## 29\. Indicadores de gestión

### Avance

* porcentaje de P0 cerrados;
* porcentaje total cerrado;
* pendientes por área;
* pendientes bloqueados;
* decisiones vencidas;
* edad promedio;
* tiempo de resolución.

### Riesgo

* P0 sin responsable;
* P0 sin fecha;
* pendientes jurídicos abiertos;
* pendientes de seguridad abiertos;
* funciones con API sin permiso;
* funciones documentadas sin pruebas;
* funciones liberadas con supuestos.

### Ejecución

* decisiones tomadas por semana;
* evidencias recibidas;
* reaperturas;
* cambios de alcance;
* retrasos;
* dependencias críticas.

## 30\. Registro de decisiones

Además de los pendientes, debe conservarse un historial de decisiones con identificador, fecha, decisión, contexto, opciones evaluadas, criterio, aprobadores, documentos afectados, impacto, fecha de revisión y condición de reversión.

## 31\. Resultado esperado

Este registro debe permitir responder qué falta resolver, qué bloquea el lanzamiento, quién es responsable, qué decisión debe tomarse, qué riesgo existe, qué depende de ello, cuándo debe cerrarse, qué evidencia se necesita, qué documento debe actualizarse, qué capacidades pueden liberarse y cuáles deben permanecer deshabilitadas.

## Apéndice IA. Etiquetas de recuperación

```yaml
retrieval\_tags:
  - pendientes
  - decisiones
  - bloqueos
  - riesgos
  - dependencias
  - responsables
  - lanzamiento
  - autenticacion
  - registro
  - resultados
  - facturacion
  - pagos
  - odessa
  - laboratorios
  - citas
  - familiares
  - menores
  - farmacia
  - escalamiento
  - privacidad
  - juridico
  - seguridad
  - datos
  - APIs
  - QA
  - comercial
  - finanzas
  - punto\_equilibrio
```

