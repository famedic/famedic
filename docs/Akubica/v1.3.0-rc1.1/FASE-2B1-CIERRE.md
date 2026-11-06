# Fase 2B 1 Resultado y revisión pendiente

**Estado final: BLOCKED. La protección de Leo no está implementada ni acreditada.** Se completó el diagnóstico, el diseño de identidad/capacidades, las matrices de protección/legacy, la automatización parcial de aceptación y las ejecuciones locales permitidas. Se detuvo la mutación funcional porque no consta aprobación del modelo de emisión/binding/canal ni de D08. Esta condición procede de los bloques B/C/D y del cierre de la petición Fase 2B-1, no de una aprobación automática ni de una interpretación que convierta las propuestas de Fase 2A en reglas aceptadas.

## Resultados principales

- **P07 FAIL:** un PAT sintético no clasificado, con ability solo lectura, obtuvo 200 y creó un payment link en memoria; el objetivo propuesto era 403 CONSUMER_NOT_AUTHORIZED, sin efectos.
- **P08 FAIL:** las variantes sin token/inválido/revocado/expirado devolvieron 401 sin efectos; la variante sesión web después de login real devolvió 500 INTERNAL_ERROR al llegar con TransientToken. El router local ejecuta Authenticate antes de UseApiTokenGuard.
- **P01–P06 y P09–P12 BLOCKED:** no se inventó una identidad Leo/native mediante header, rol, usuario o mock. Los diez casos están registrados como SKIPPED/BLOCKED; no tienen implementación integrada completa y no se consideran PASS.
- Cuatro positivos/caracterizaciones de Bearer actual pasan: abilities vacías/read-only/wildcard crean link, y replay persistido no duplica mientras una clave nueva sí genera otro. No equivalen al positivo native verificado de P12.
- Laravel existente: **200 PASS, 13 FAIL, 3 ERROR** en 216 casos; incluye 24 pruebas de payment links PASS. Regresiones Python **80 PASS**, Node **4 PASS**. Ejemplos **30/30** y fixtures **20/20** válidos; contrato local PASS y diff sin errores de whitespace.

No se atribuye regresión completa PASS ni se afirma que Leo ya esté impedido de pagar o de usar legacy. La evidencia de cero efectos se limita a las solicitudes realmente observadas; P07 precisamente incumple ese requisito. No se ejecutó ninguna solicitud contra staging/producción.

## Entregables y trazabilidad

| Solicitud | Entrega y estado |
| --- | --- |
| Diagnóstico de identidad actual, emisores, abilities, usuario/canal/PAT y wildcard | [Identidad y capacidades](FASE-2B1-IDENTIDAD-Y-CAPACIDADES.md), diagnóstico confirmado y propuesta pendiente. |
| Vínculo de consumidor y capacidades explícitas | Mismo documento: registro servidor, cliente/canal autenticados, binding por token_id y delegación. Sin implementación de esquema/emisor. |
| Cambios locales implementados | Tests de aceptación/caracterización y runner temporal. Sin cambios funcionales de aplicación. Lista completa en integridad. |
| Matriz de protección de payment links y efectos | [Protección y legacy](FASE-2B1-PROTECCION-Y-LEGACY.md), cadena completa e impacto de recibos/links anteriores. |
| Matriz de seis endpoints legacy | Mismo documento: exposición real, ownership, consentimiento ausente y propuesta de denegación a Leo pendiente D08. |
| P01–P12 y positivos | [Resultados](FASE-2B1-PRUEBAS-Y-RESULTADOS.md) y [matriz JSON](FASE-2B1-P01-P12.json), FAIL/BLOCKED explícitos. |
| Evidencia de cero efectos | Observaciones mínimas por solicitud en [ejecución final](FASE-2B1-EJECUCION-FINAL.json); P07 un link, P08 cero mutaciones con fallo HTTP en sesión. |
| Regresiones y compatibilidad | Ejecución final y [análisis por suite](FASE-2B1-REGRESIONES-ANALISIS.json), sin ocultar los 16 fallos/errores restantes. |
| Delta contractual propuesto | Protección y legacy: nuevos 403/capacidades/prueba de canal son observables, aunque ApiErrorResponse genérico los admita; versionado separado y transición de clientes pendientes. |
| Inventario real de rutas/middleware | [67 rutas locales](FASE-2B1-RUTAS-AUTH.json); 52 con auth:sanctum y orden resuelto de middleware. |
| Lista de archivos e integridad | [Evidencia de integridad](FASE-2B1-INTEGRIDAD.json), hashes antes/después y fuentes. |
| Pendientes de aprobación | Tabla siguiente. No se creó una aprobación por omisión. |

## Decisiones concretas requeridas

| Decisión | Propuesta preparada para revisión | Responsable sugerido |
| --- | --- | --- |
| Modelo de consumidor verificable | Cliente backend autenticado (mTLS como candidato), identidad de borde validada, PAT real ligado a consumer/user/delegación/canal; cubrir todos los emisores de login/register. Sin headers públicos como autoridad. | Seguridad e Integraciones. |
| Grants y transición de PAT | Deny Leo prioritario frente a wildcard; permiso explícito para pagos de nativos registrados. Inventariar y clasificar clientes actuales antes de fail-closed para no clasificados. No convertir todos los PAT comunes en native. | Seguridad, Pagos e Integraciones. |
| D08 y allowlist Leo | Denegar las seis rutas legacy de beneficio a Leo; revisar también cart/prepare que reutilizan resources con IDs. Mantener DTO y acceso de consumidores legítimos registrados, sin redacción silenciosa ni bloqueo por user_id. | Producto/API e Integraciones/Akúbica. |
| Orden de guard y política | Guard Bearer antes de Authenticate; consumidor/gate después de autenticar pero antes de replay; defensa en action para llamada directa. Repetir P08 sesión y completar los diez escenarios bloqueados. | Responsable técnico de autenticación/API y Seguridad. |
| Recibos y links anteriores | Denegar replay tras revocación/cambio de política y decidir invalidación de enlaces ya entregados por PAT/consumer prohibidos. Bloquear creación no revoca automáticamente la capability del enlace web. | Pagos y Seguridad. |
| Esquema y regresiones pendientes | Aprobar primero propuesta de tablas consumidor/binding y su migración; resolver fixtures/customer y consulta SQLite de OTP con sus responsables, sin cambiar negocio para «hacer verde» la suite. | Ingeniería/API/DB y propietario OTP. |

No se exige aprobar las reglas financieras D01–D07 para suponer el modelo de identidad: esas decisiones siguen pendientes y no se implementan aquí. El siguiente trabajo, si se autoriza expresamente, debe limitarse al modelo y al gate revisados, no extenderse automáticamente a beneficios, consumo parcial o endpoints opacos.

## Integridad y restricciones

El contrato RC1.1 conserva **67 operaciones** y SHA-256 `523a566bab22fffb4c0ad8fbb29d79cc4823e99769494b1fd135f6639094f9a4`. Se conservan operationId, contratos históricos y evidencia previa. El resultado integral de Fase 1C.2 sigue ligado a esos mismos bytes; no se actualizó OpenAPI para justificar la política propuesta.

Los únicos cambios son cuatro archivos nuevos de pruebas/runner, documentación/evidencia revisable y excepciones exactas de .gitignore. No se modificaron app, routes, config, bootstrap, migraciones existentes, tests anteriores ni lógica de cobro/beneficios. Se aplicaron migraciones existentes solo sobre SQLite :memory: para fixtures; ninguna base persistente fue migrada ni se generó/aplicó un esquema de identidad nuevo. La copia temporal de test excluye una reparación de índices ATIX específica de MySQL, sin alterar su original ni afirmar cobertura de esa migración.

No se crearon tokens reales: los PAT sintéticos y links de prueba solo existieron en la base en memoria. No se usaron credenciales comprometidas, no se descargaron dependencias y no hubo staging, producción, commit, push, deploy o ZIP. Los reportes inicial y final coexisten; no se sobrescribió evidencia antigua.

Fase 0 permanece **ABIERTA**. La nueva rotación HMAC/caché se registra como reportada por el administrador; siguen pendientes rechazo controlado de clave anterior y revocación/verificación del PAT comprometido. No se inicia Fase 2B-2, 2B-3 o 2B-4. Trabajo detenido para revisión.
