# Flujo seguro de staging — Akúbica v1.3.0

## Preflight

1. Confirmar que el release activo corresponde a la rama `feature/akubica-api-v1.3.0` y al commit esperado por la ventana de despliegue.
2. Consultar `php artisan migrate:status` en staging, sin `migrate:fresh`, sin rollback y sin borrar datos.
3. Confirmar que `tax_profiles.default_owner_key` es columna ordinaria nullable y que las migraciones ATIX están en estado `Ran`.
4. Verificar que el environment de Postman no contiene valores sensibles persistidos.

## Orden de ejecución

1. Catálogo público de laboratorio: solo lectura.
2. Planes de membresía: solo lectura.
3. ATIX: generar nuevos `conversation_id`, `channel_event_id`, nonce, `X-Correlation-ID` e `Idempotency-Key` por ejecución.
4. Login/OTP de una cuenta sintética controlada, si el fixture ya fue provisto por QA.
5. Estado de membresía y carrito `medical_services` con Bearer de prueba.
6. Aplicar y retirar beneficio médico usando un cupón o saldo de prueba.
7. Ejecutar `checkout/prepare` y verificar que no haya side effects de pago.

## ATIX

La colección calcula HMAC-SHA256 sobre el método, ruta, timestamp, nonce y hash SHA-256 del JSON exacto enviado. El secreto se lee de `atix_hmac_secret` en runtime y no se muestra. Ejecutar por separado: válido, firma inválida, timestamp expirado, nonce repetido, canal inválido, replay, conflicto y teléfono desconocido.

## Criterios de seguridad

- No enviar OTP, Bearer, teléfonos completos o secretos a reportes.
- No solicitar ni aceptar `account_context_token` como sustituto de Bearer.
- No ejecutar payment links, pagos, activaciones, farmacia ni laboratorio transaccional.
- Para `checkout/prepare`, registrar solo HTTP, correlation ID, fecha y la presencia de `can_continue_to_payment_platform=false`.

## Evidencia

Registrar por caso: endpoint, `operationId`, HTTP, `correlation_id`, fecha, build/commit y observación sanitizada. Una falta de fixture o configuración se clasifica como pendiente; no como fallo funcional.
