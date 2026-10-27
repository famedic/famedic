# Akúbica API v1.3.0 — Entrega de integración

Entrega versionada para la integración de Akúbica con FAMEDIC. La documentación histórica permanece sin cambios; esta carpeta usa como fuente técnica el código, `docs/Akubica/akubica-openapi.yaml` y la regresión actual de la rama `feature/akubica-api-v1.3.0`.

## Alcance

- Catálogo público de laboratorio con precios comparativos en `price_cents` y `currency`.
- Planes y estado de membresía.
- Carrito independiente `service_line=medical_services`.
- Beneficios médicos mediante cupón o saldo a favor, sin enviar importes calculados por el consumidor.
- `GET /checkout/prepare?service_line=medical_services`, exclusivamente de preparación.
- Reconocimiento telefónico pre-OTP y elevación posterior mediante el flujo OTP existente.
- Sandbox ATIX: `POST /integrations/atix/phone-lookups`, HMAC-SHA256, nonce, timestamp, idempotencia, replay, correlación y anti-enumeración.

## Límites operativos

La integración no mezcla laboratorio, farmacia y servicio médico:

| Línea | Alcance de esta entrega |
|---|---|
| Laboratorio | Catálogo y precios comparativos; no se ejecutan compras ni citas. |
| Farmacia | Fuera de alcance; no se consultan ni modifican carritos de farmacia. |
| Servicio médico | Membresías, carrito `medical_services`, beneficios y preparación de checkout. |

Leo no paga, no tokeniza tarjetas, no genera payment links y no activa membresías desde esta integración. La colección no contiene esas operaciones.

## Compatibilidad

El contrato OpenAPI continúa siendo v1.2.3. Los `operationId` existentes se conservan; `atixPhoneLookup` es el operationId del endpoint ATIX ya presente en el OpenAPI actual. No se agregan operationId duplicados.

## Archivos

- `akubica-api-v1.3.0-consumption-guide.md`: guía de consumo y seguridad.
- `akubica-v1.3.0-checklist-endpoints.md`: matriz de endpoints y criterios.
- `akubica-v1.3.0-staging-flow.md`: orden seguro de staging.
- `Famedic-Akubica-v1.3.0.postman_collection.json`: colección sin secretos.
- `Famedic-Akubica-v1.3.0-staging.postman_environment.json`: environment vacío/sintético.
- `openapi-changelog-v1.3.0.md`: cambios documentales y compatibilidad.

## Uso seguro

1. Importar la colección y el environment.
2. Definir `base_url`, `token` y, para ATIX, las variables sensibles desde el gestor seguro del ambiente; no guardarlas en este archivo.
3. Usar únicamente cuentas, cupones y teléfonos de prueba controlados.
4. Ejecutar primero catálogo, planes y estado; después carrito médico y beneficios.
5. Validar que `checkout/prepare` devuelve `can_continue_to_payment_platform=false` y no invocar payment links.
