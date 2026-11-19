# Akúbica API v1.3.0 — Entrega de integración

Entrega versionada para la integración de Akúbica con FAMEDIC. La documentación histórica permanece sin cambios; esta carpeta usa como fuente técnica el código, la línea base histórica `docs/Akubica/akubica-openapi.yaml` (anexo externo a esta carpeta: está en el repositorio, en `docs/Akubica/`) y la rama de integración `prep/akubica-technical-integration-rc11`.

**Estado vigente (Batch 1 · rev7):** contrato `1.3.0-rc2`. Producto aceptó el alcance contractual y funcional del candidato `92e17741`; rev7 es la revisión correctiva acotada y queda **lista para verificación de cierre**. Batch 1 no está congelado por decisión propia. Las pruebas de Batch 1 son locales y offline: no hay certificación de staging, que corresponde a Batch 2. Identidad, base de comparación y referencia histórica de QA en `RC2-MANIFIESTO.md`; el commit exacto de rev7 está en el manifiesto externo del paquete de entrega rev7.

## Alcance

Fuente de las capacidades de Leo: `RC2-MATRIZ-PERMISOS-LEO.md` (plan v1.0, con las decisiones D-1 y D-2, **ratificadas por Producto** en la revisión de Batch 1; texto en la §5b de la matriz). "Existe" se refiere al build QA `3fb5feb8`; nada está certificado todavía, porque la certificación es de Batch 2.

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

Leo no paga, no tokeniza tarjetas, no genera payment links y no activa membresías desde esta integración. Las únicas llamadas relacionadas son pruebas negativas de seguridad: B25 en la NEWMAN y LF-L17a/LF-L17b en la colección Leo (`POST /checkout/payment-link` y `POST /checkout/draft`, S14). Se ejecutan sólo para comprobar HTTP 403 y que no haya liga ni cobro.

## Compatibilidad

La línea base histórica OpenAPI v1.2.3 (`docs/Akubica/akubica-openapi.yaml`, fuera de esta carpeta) permanece intacta. El contrato de esta entrega es `akubica-openapi-v1.3.0-rc2.json` (76 operaciones). Los `operationId` existentes se conservan; `atixPhoneLookup` es el operationId del endpoint ATIX ya presente en el OpenAPI actual. No se agregan operationId duplicados.

## Archivos

- `akubica-api-v1.3.0-consumption-guide.md`: guía de consumo y seguridad.
- `akubica-v1.3.0-checklist-endpoints.md`: matriz de endpoints y criterios.
- `akubica-v1.3.0-staging-flow.md`: orden seguro de staging.
- `akubica-openapi-v1.3.0-rc2.json`: contrato principal RC2 con 76 operaciones (64 paths).
- `r30-first-party-v1.3.0.json`: suplemento first party separado con cuatro operaciones R30; no es capacidad de Leo.
- `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` y `…-rc2-NEWMAN.environment.template.json`: **colección de certificación** de 69 solicitudes (A01–A12, B01–B26, R01–R08), con guardas de ejecución endurecidas en rev7: URL efectiva, sólo HTTPS, lista cerrada de hosts QA, banderas exactas `"1"` y autorización expresa de las escrituras positivas. Plantilla sin secretos (ver `RC2-NEWMAN-LEEME.md`).
- `Famedic-Akubica-v1.3.0-rc2-LEO-FUNCIONAL.postman_collection.json` y `…-rc2-LEO-FUNCIONAL.environment.template.json`: **colección funcional diferenciada de Leo** (Batch 1 · rev3; rev4) de 48 solicitudes. Cubre los flujos obligatorios de la matriz que faltaban en la NEWMAN (step-up, ligas seguras, solicitud y estado de factura, lectura fiscal mínima D-1, pedidos, resultados, facturas, carrito y continuidad de laboratorio D-2) y los negativos de alcance. Sólo staging y el build de Batch 2 (ver `RC2-LEO-FUNCIONAL-LEEME.md` y `RC2-LEO-FUNCIONAL-COBERTURA.csv`). Rev4 concilia las tres observaciones de rev3: Leo solicita factura con el id opaco de la lectura mínima (`LeoInvoiceRequestCreate`; el contrato native se conserva en `NativeInvoiceRequestCreate`), B21x es el caso realmente no autorizado (marca sin `cart:laboratory:write`, 403) y su positivo autorizado se traza a L06/S07 (LF-L06b), y B20/B21x envían `laboratory_test_id` entero sin `quantity`. Rev5 deja conformes los cuerpos de B06b, B25 y LF-L17a (B25/LF-L17a con `brand`, credencial Leo y 403) y marca A07 y los demás negativos de validación como INVALIDO_DELIBERADO. El inventario completo de cuerpos está en `RC2-INVENTARIO-CUERPOS.md` / `.csv`.
- `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json`: **antecedente offline** de 92 solicitudes que cubre las 76 operaciones principales y las 4 R30. Su guard omite siempre todas las peticiones y no es ejecutable. El sufijo `-90` es histórico.
- `Famedic-Akubica-v1.3.0-rc2-synthetic.environment.json`: environment sin secretos y con URLs reservadas.
- `RC2-MATRIZ-TRAZABILIDAD-46.csv` (en el repo, en `RC2-PREPARACION-QA/A-DOCUMENTACION/`, no en este directorio): trazabilidad de los 46 escenarios obligatorios. Rev7: los registros Pest "20 PASS / 137 assertions" quedan rotulados como evidencia histórica no reproducida y la fila B22 queda normalizada.
- `RC2-MATRIZ-ESCENARIOS-CLASIFICACION.csv` / `.md`: clasificación de los 46 escenarios (obligatorio para lanzamiento, diferido o no aplicable) y su S01–S16 del plan (Batch 1 · T8).
- `RC2-MATRIZ-PERMISOS-LEO.md`: matriz de permisos de Leo por capacidad y operación (Batch 1 · T3). Distingue lo que existe hoy de lo que implementa Batch 2.
- `openapi-changelog-v1.3.0-rc2.md`: **changelog RC2 vigente** (incluye rev7).
- `openapi-changelog-v1.3.0.md`: changelog histórico de v1.3.0 previo a RC2 (antecedente; no describe RC2).
- `RC2-MANIFIESTO.md`: identidad, inventario y estado de la entrega.
- `RC2-FE-DE-ERRATAS-REV7.md`: aclaraciones vinculadas a evidencias históricas, que se conservan sin reescribir.

## Uso seguro

1. Usar la colección de certificación `…-rc2-NEWMAN` con su plantilla de entorno, bajo sus guardas. Sobre el build de Batch 2, usar además la colección Leo `…-rc2-LEO-FUNCIONAL` con su plantilla. La rc2-90 sólo sirve para revisar cobertura offline.
2. Definir `base_url`, `token` y, para ATIX, las variables sensibles desde el gestor seguro del ambiente; no guardarlas en este archivo.
3. Usar únicamente cuentas, cupones y teléfonos de prueba controlados.
4. Ejecutar primero catálogo, planes y estado; después el carrito médico. Leo no aplica beneficios directamente (L16, diferido).
5. Validar que `checkout/prepare` devuelve `assistant_can_pay=false`, `can_continue_on_platform=true` y `can_continue_to_payment_platform=false` (semántica de Batch 1 · T4 en la guía de consumo) e invocar payment links sólo como la prueba negativa B25.
