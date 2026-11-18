# Akúbica API v1.3.0 — Entrega de integración

Entrega versionada para la integración de Akúbica con FAMEDIC. La documentación histórica permanece sin cambios; esta carpeta usa como fuente técnica el código, `docs/Akubica/akubica-openapi.yaml` y la regresión actual de la rama de integración `prep/akubica-technical-integration-rc11` (candidato de Batch 2 registrado en `RC2-MANIFIESTO.md`).

## Alcance

Fuente de las capacidades de Leo: `RC2-MATRIZ-PERMISOS-LEO.md` (plan v1.0, con las decisiones D-1 y D-2). "Existe" se refiere al build QA `3fb5feb8`; nada está certificado todavía, porque la certificación es de Batch 2.

| Capacidad de Leo | Fila | Estado en el plan | Implementación |
|---|---|---|---|
| Reconocimiento ATIX (HMAC, nonce, timestamp, idempotencia, anti-enumeración), OTP y Bearer | L01–L02 | Aprobado | Existe |
| Catálogo y precios comparativos (`price_cents`, `currency`) | L03 | Aprobado | Existe |
| Planes, estado de membresía y carrito `medical_services` | L04 | Aprobado | Existe |
| `GET /checkout/prepare` (sólo preparación; semántica T4) y continuidad de membresía | L05 | Aprobado | Existe; la continuidad sólo en staging, nunca en producción |
| **Carrito de laboratorio** por línea y marca (`cart:laboratory:*`) y su continuidad (D-2) | L05–L06 | **Aprobado en plan** | **Implementación Batch 2** (hoy 403) |
| **Pedidos y familiares propios**, metadatos de resultados y facturas, step-up y liga segura, solicitud de factura, perfil fiscal mínimo (D-1) | L07–L14, L13b | **Aprobado en plan** | **Implementación Batch 2** (hoy 403) |
| **Beneficios directos** (aplicar o retirar cupón o saldo desde Leo) | L16 (C6) | **Diferido** | No es capacidad de Leo. El usuario ve y aplica los beneficios en el checkout de FAMEDIC; Leo sólo informa |
| Pagos y payment link | L17 | Prohibido | Siempre 403; sólo prueba negativa B25 |
| Citas, farmacia y resto de la superficie privada | L18–L20 | Prohibido o first-party | Citas en el checkout FAMEDIC; farmacia 503; el resto 403 |

## Límites operativos

La integración no mezcla laboratorio, farmacia y servicio médico:

| Línea | Alcance de esta entrega |
|---|---|
| Laboratorio | Catálogo y precios: existe. Carrito por línea y marca y su continuidad: aprobado en plan, implementación Batch 2. Compras y citas: sólo en el checkout first-party de FAMEDIC. |
| Farmacia | Fuera de alcance; no se consultan ni modifican carritos de farmacia. |
| Servicio médico | Membresías, carrito `medical_services`, preparación de checkout y continuidad (staging). Beneficios: los aplica el usuario en el checkout de FAMEDIC; la aplicación directa por Leo está diferida (L16). |

Leo no paga, no tokeniza tarjetas, no genera payment links y no activa membresías desde esta integración. La única llamada relacionada en la colección es la prueba negativa de seguridad B25 (`POST /checkout/payment-link`, S14), que se ejecuta sólo para comprobar HTTP 403 y que no haya liga ni cobro.

## Compatibilidad

La línea base histórica OpenAPI v1.2.3 (`docs/Akubica/akubica-openapi.yaml`) permanece intacta. El contrato de esta entrega es `akubica-openapi-v1.3.0-rc2.json` (76 operaciones). Los `operationId` existentes se conservan; `atixPhoneLookup` es el operationId del endpoint ATIX ya presente en el OpenAPI actual. No se agregan operationId duplicados.

## Archivos

- `akubica-api-v1.3.0-consumption-guide.md`: guía de consumo y seguridad.
- `akubica-v1.3.0-checklist-endpoints.md`: matriz de endpoints y criterios.
- `akubica-v1.3.0-staging-flow.md`: orden seguro de staging.
- `akubica-openapi-v1.3.0-rc2.json`: contrato principal RC2 con 76 operaciones (64 paths).
- `r30-first-party-v1.3.0.json`: suplemento first party separado con cuatro operaciones R30; no es capacidad de Leo.
- `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` y `…-rc2-NEWMAN.environment.template.json`: **colección funcional** de 69 solicitudes, con guardas de ejecución. Plantilla sin secretos (ver `RC2-NEWMAN-LEEME.md`).
- `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json`: **antecedente offline** de 92 solicitudes que cubre las 76 operaciones principales y las 4 R30. Su guard omite siempre todas las peticiones y no es ejecutable. El sufijo `-90` es histórico.
- `Famedic-Akubica-v1.3.0-rc2-synthetic.environment.json`: environment sin secretos y con URLs reservadas.
- `RC2-MATRIZ-TRAZABILIDAD-46.csv` (en el repo, en `RC2-PREPARACION-QA/A-DOCUMENTACION/`, no en este directorio): trazabilidad histórica de los 46 escenarios.
- `RC2-MATRIZ-ESCENARIOS-CLASIFICACION.csv` / `.md`: clasificación de los 46 escenarios (obligatorio para lanzamiento, diferido o no aplicable) y su S01–S16 del plan (Batch 1 · T8).
- `RC2-MATRIZ-PERMISOS-LEO.md`: matriz de permisos de Leo por capacidad y operación (Batch 1 · T3). Distingue lo que existe hoy de lo que implementa Batch 2.
- `openapi-changelog-v1.3.0.md`: cambios documentales y compatibilidad.

## Uso seguro

1. Usar la colección funcional `…-rc2-NEWMAN` con su plantilla de entorno, bajo sus guardas. La rc2-90 sólo sirve para revisar cobertura offline.
2. Definir `base_url`, `token` y, para ATIX, las variables sensibles desde el gestor seguro del ambiente; no guardarlas en este archivo.
3. Usar únicamente cuentas, cupones y teléfonos de prueba controlados.
4. Ejecutar primero catálogo, planes y estado; después el carrito médico. Leo no aplica beneficios directamente (L16, diferido).
5. Validar que `checkout/prepare` devuelve `assistant_can_pay=false`, `can_continue_on_platform=true` y `can_continue_to_payment_platform=false` (semántica de Batch 1 · T4 en la guía de consumo) e invocar payment links sólo como la prueba negativa B25.
