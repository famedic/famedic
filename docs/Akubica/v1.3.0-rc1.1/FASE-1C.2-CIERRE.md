# Fase 1C.2 — Cierre de los 33 errores estructurales

Estado final: **PASS** para el alcance de Fase 1C.2. El bloqueo estructural queda resuelto: validación integral con cero errores y regresiones finales satisfactorias. No constituye aprobación de liberación ni certifica el runtime desplegado.

## Corrección implementada y comparación

Se comprobó el SHA-256 original, el inventario exacto de 33 errores y que cada objeto tenía exclusivamente `$ref` y `headers`. Todas las cabeceras hermanas coincidían semánticamente con las del componente referenciado. Se eliminaron únicamente esos 33 bloques redundantes (99 líneas); se preservaron las referencias y todos los componentes. No hubo diferencias que requirieran detener la corrección.

- Antes: `a737a0c8380ac470f20d95bc3f08739cf2cd38a71092e1e80c4b1a959905010f`.
- Después: `523a566bab22fffb4c0ad8fbb29d79cc4823e99769494b1fd135f6639094f9a4`.
- Respuestas efectivas equivalentes: 33/33; `X-Correlation-ID` conservado en todas las respuestas donde existía.
- Operaciones: 67 antes y después; ningún método/ruta ni `operationId` añadido, retirado o cambiado.
- Cuerpos, códigos HTTP, campos, ejemplos, descripciones y restricciones de esquemas conservados.
- El manifiesto permite reconstruir los bytes originales mediante los textos eliminados y sus posiciones. Las pruebas exigen su hash original y comparan el árbol completo tras retirar exclusivamente los 33 `headers` autorizados.

## Validación integral oficial

Entorno existente: `/home/developer/.local/share/famedic-validation/openapi31-0.9.0-py3144`. Herramienta: `openapi-spec-validator==0.9.0`; clase explícita `OpenAPIV31SpecValidator`.

Resultado **PASS**, errores **0**, categorías `{}`. Ejecución `fase-1c2-47fe6ba5-cb0f-45e7-84ed-bf89d9da2766`. La evidencia vincula el resultado al hash nuevo y registra comando, entorno, fechas y código de salida. No hubo descargas ni reinstalaciones. El reporte FAIL previo y los reportes BLOCKED permanecen sin sobrescribir.

## Regresiones

| Control | Resultado final |
| --- | --- |
| Python Fases 0, 1, 1B, 1C y 1C.2 | 80/80 PASS, ocho pruebas nuevas |
| Node | 4/4 PASS |
| Ejemplos | 30/30 PASS |
| Fixtures sintéticos de respuestas | 20/20 PASS |
| E.164 contrato/FormRequest aislado | PASS: 8 positivos y 20 negativos |
| Nulabilidad | PASS: sin anotaciones heredadas pendientes en el candidato |
| Referencias, estructura local, composición y metadatos de esquemas | PASS |
| Autenticación declarada vs rutas | PASS: 67 operaciones, sin prueba del despliegue |
| Postman: métodos/rutas y espejo completo | PASS: 21 requests / 11 operaciones |
| Integridad histórica y evidencia previa | PASS |
| `git diff --check` | PASS |

La primera ejecución de regresiones detectó dos fallos en el nuevo auxiliar de pruebas: resolvía una sola referencia y no contemplaba componentes que referencian otro componente. Se corrigió el auxiliar para resolver la cadena completa, sin cambios adicionales al contrato. Se conserva esa ejecución en `FASE-1C.2-REGRESIONES.json`; el resultado definitivo está en `FASE-1C.2-REGRESIONES-FINAL.json`. La prueba negativa reintroduce `$ref` + `headers` en memoria y verifica que el control falla.

El validador local conserva una etiqueta estática que menciona que el validador integral no está disponible; esa etiqueta no describe el entorno aprobado actual y no se utiliza como evidencia integral. El resultado oficial independiente está en `FASE-1C.2-OPENAPI-INTEGRAL.json`.

## Integridad y compatibilidad histórica

| Contrato histórico | Operaciones | SHA-256 conservado |
| --- | --- | --- |
| v1.2.3 del commit `6ffb35c7cdaee26e8deb09398c85ff9bcf7c46e5` | 61 | `6ace97823aa528171f0d5c34c8ec9674d5730c429db2a95c18dc0c560f5b3749` |
| Archivo actual etiquetado v1.2.3 | 67 | `c46ec1f832f2c8a22bdc9cefc2341ab1afc63e01f94dc6cd3946a5ae34e4e2d2` |
| RC1 histórico | 67 | `bbf110ffb55f9092b7a9678561509e11bfb0eb644251efc2b44ceec3a8d1398b` |

Las comparaciones completas de los tres contratos contra RC1.1 se incluyen en `FASE-1C.2-VALIDACION-LOCAL.json`. Las diferencias acumuladas de fases anteriores siguen documentadas en las matrices de Fases 1/1B. En esta fase no se introduce un cambio de respuesta efectiva; consumidores que inspeccionen el YAML directamente deberán resolver el `$ref` para consultar las cabeceras. Los contratos históricos se mantienen intactos, incluidos sus defectos históricos.

## Riesgos y dependencias pendientes

- Fase 0 administrativa **ABIERTA**: revocación PAT y rotación HMAC verificadas pendientes.
- Aprobación formal de la baseline histórica de 61 operaciones **PENDIENTE**.
- Coincidencia con runtime desplegado, integración real de consumidores y aplicación efectiva de autenticación **NO VERIFICADAS**; no se ejecutó staging ni se usaron credenciales reales.
- Las hipótesis de beneficios D01–D10 continúan sujetas a aprobación. No se implementaron beneficios ni nuevas operaciones.
- El PASS estructural y las pruebas locales no autorizan liberación, despliegue ni paquete RC1.1.

## Archivos de esta fase

Modificados: `docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml` y `.gitignore` (excepciones exactas para estas evidencias). Nuevo: `scripts/tests/test_openapi31_phase1c2.py` y los ocho entregables siguientes en `docs/Akubica/v1.3.0-rc1.1/`:

- `FASE-1C.2-CORRECCIONES.json`.
- `FASE-1C.2-OPENAPI-INTEGRAL.json`.
- `FASE-1C.2-EJECUCION.json`.
- `FASE-1C.2-REGRESIONES.json`.
- `FASE-1C.2-REGRESIONES-FINAL.json`.
- `FASE-1C.2-VALIDACION-LOCAL.json`.
- `FASE-1C.2-INTEGRIDAD.json`.
- `FASE-1C.2-CIERRE.md`.

La evidencia de integridad incluye la lista exacta comparada contra el estado previo de esta fase y el hash del FormRequest, que no cambió durante esta ejecución. Los cambios preexistentes de otras fases no se atribuyen a Fase 1C.2.

## Inventario de las 33 correcciones

En todas las filas: cabeceras redundantes iguales, referencia y componente preservados, respuesta efectiva equivalente.

| ID | Operación | HTTP | Componente preservado |
| --- | --- | --- | --- |
| error-0001 | `POST /auth/login/request-code` | 503 | `DeliveryOrConfigFailed` |
| error-0002 | `POST /auth/login/verify-code` | 503 | `DeliveryOrConfigFailed` |
| error-0003 | `POST /auth/login/resend-code` | 503 | `FeatureDisabled` |
| error-0004 | `POST /auth/register` | 503 | `DeliveryOrConfigFailed` |
| error-0005 | `POST /auth/register/verify-code` | 503 | `DeliveryOrConfigFailed` |
| error-0006 | `POST /auth/register/resend-code` | 503 | `FeatureDisabled` |
| error-0007 | `DELETE /auth/token` | 401 | `Unauthenticated` |
| error-0008 | `GET /secure-downloads/{token}` | 503 | `FeatureDisabled` |
| error-0009 | `DELETE /cart` | 401 | `Unauthenticated` |
| error-0010 | `GET /cart/totals` | 401 | `Unauthenticated` |
| error-0011 | `DELETE /cart/coupon` | 401 | `Unauthenticated` |
| error-0012 | `GET /checkout/prepare` | 401 | `Unauthenticated` |
| error-0013 | `GET /laboratory-appointments/requirements` | 401 | `Unauthenticated` |
| error-0014 | `GET /orders` | 401 | `Unauthenticated` |
| error-0015 | `GET /orders/results` | 401 | `Unauthenticated` |
| error-0016 | `GET /orders/invoices` | 401 | `Unauthenticated` |
| error-0017 | `GET /orders/{order_id}/products` | 404 | `OrderNotFound` |
| error-0018 | `GET /orders/{order_id}/status` | 404 | `OrderNotFound` |
| error-0019 | `PUT /orders/{order_id}/cancel` | 401 | `Unauthenticated` |
| error-0020 | `GET /orders/{order_id}/results` | 404 | `OrderNotFound` |
| error-0021 | `POST /orders/{order_id}/results/step-up/request` | 503 | `FeatureDisabled` |
| error-0022 | `POST /orders/{order_id}/results/step-up/verify` | 503 | `FeatureDisabled` |
| error-0023 | `POST /orders/{order_id}/results/secure-link` | 503 | `FeatureDisabled` |
| error-0024 | `GET /orders/{order_id}/invoices` | 404 | `OrderNotFound` |
| error-0025 | `POST /orders/{order_id}/invoices/{invoice_id}/step-up/request` | 503 | `FeatureDisabled` |
| error-0026 | `POST /orders/{order_id}/invoices/{invoice_id}/step-up/verify` | 503 | `FeatureDisabled` |
| error-0027 | `POST /orders/{order_id}/invoices/{invoice_id}/secure-link` | 503 | `FeatureDisabled` |
| error-0028 | `GET /orders/{order_id}/invoice-request/status` | 404 | `OrderNotFound` |
| error-0029 | `GET /user/family` | 401 | `Unauthenticated` |
| error-0030 | `GET /user/payment-methods` | 401 | `Unauthenticated` |
| error-0031 | `POST /user/tax-profiles` | 401 | `Unauthenticated` |
| error-0032 | `POST /user/addresses` | 401 | `Unauthenticated` |
| error-0033 | `POST /user/contacts` | 401 | `Unauthenticated` |

Se detiene el trabajo al finalizar esta validación. No se inició Fase 2, ni se realizaron commit, push, deploy, migraciones, staging o generación de paquete.
