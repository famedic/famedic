# Informe de pruebas locales RC2

## Entorno

Worktree aislado `codex/rc2-local-20261110`, base `9c060c81e6eda7871e2c902563e3c9e0daac74dc`. MySQL 8.0.46 en el contenedor exclusivo `rc2-local-mysql-20261110`, red `rc2-local-net-20261110` y base desechable `famedic_rc2_local_20261110_testing`. Las integraciones ActiveCampaign, Algolia, Vonage y pagos permanecieron desactivadas o simuladas.

## Resultados

- Pest focalizado: **20 PASS**, **0 FAIL**, **0 ERROR ambiental**, **0 NOT_RUN**; 137 assertions.
- Control offline de autorización: **24 PASS**, **0 FAIL**.
- Regresión adicional acotada: **52 PASS**, **3 FAIL** en idempotencia OTP por conteos afectados por registros preexistentes de la base clonada; se clasifican como contaminación del fixture local, no como defecto RC2.
- Migración opaca: aplicada en MySQL local después de configurar `log_bin_trust_function_creators=1` solo dentro del contenedor exclusivo.
- Triggers: **9/9** verificados.
- B24: controles de continuidad deshabilitados; no se certificó transporte QA.
- B02: **BLOCKED**, sin fuente autorizada de paquetes y precios.

## Cobertura local

La batería cubre carrito médico, beneficios aplicados, retiro y recálculo, idempotencia, aislamiento médico/laboratorio, checkout de preparación, no activación de membresías, ausencia de payment links y autorización sintética de Leo. La suite no sustituye las fases A/B de Newman en staging.

## Defecto corregido

Se corrigió únicamente el fixture `createOlabTest` para generar `gda_id` sintético único. También se corrigió la ruta del autoloader del script offline para usar el `vendor` del worktree. No se cambiaron reglas de negocio ni contratos históricos.

## Seguridad

No se usaron credenciales reales, RDS, QA, producción, SMS, pagos ni webhooks. El resultado no es `STAGING_PASS` y no declara resuelto ningún scanner de seguridad autorizado.
