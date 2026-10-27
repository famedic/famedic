# Changelog documental — Akúbica v1.3.0

## Compatibilidad

La especificación publicada continúa siendo OpenAPI v1.2.3. Esta entrega no renombra ni elimina operationId existentes y no introduce duplicados.

## Añadido/documentado

- Precios comparativos de laboratorio desde catálogo, expresados en centavos.
- Planes y estado protegido de membresía.
- Separación explícita del carrito `medical_services`.
- Beneficios médicos mediante cupón o saldo a favor, con recálculo server-side.
- `GET /checkout/prepare?service_line=medical_services` como operación de preparación sin pago.
- Reconocimiento telefónico pre-OTP, contexto interno y elevación post-OTP sin exponer contexto como Bearer.
- `POST /integrations/atix/phone-lookups` con HMAC-SHA256, timestamp, nonce, idempotencia, replay, correlación y anti-enumeración.

## Restricciones

Leo no paga ni genera payment links. Laboratorio, farmacia y servicio médico son líneas independientes. Esta entrega no autoriza compras, citas, farmacia, pagos, activaciones ni producción.

## Nota de integración ATIX

Los valores de `channel` y `audience` deben venir de la configuración del sandbox; los ejemplos de esta entrega son sintéticos. El secreto HMAC se suministra únicamente desde el gestor seguro del ambiente y nunca se versiona.
