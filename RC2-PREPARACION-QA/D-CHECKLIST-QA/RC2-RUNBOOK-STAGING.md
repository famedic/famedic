# Runbook de staging RC2

## Candidato

- Rama de trabajo: `codex/rc2-local-20261110`
- Base: `9c060c81e6eda7871e2c902563e3c9e0daac74dc`
- SHA del commit candidato: registrar el SHA final del commit local antes de solicitar la ventana.
- Rama observada para staging: `feature/akubica-api-v1.3.0`, `b61c0cf526773994a75ec018eb0e10eb94f333c4`.
- Compatibilidad: la rama observada es anterior y no contiene las migraciones RC2 de noviembre ni la composición de 74 operaciones; no desplegarla como RC2.

## Prerrequisitos

1. Rubén confirma snapshot/backup restaurable de QA.
2. Seguridad entrega secretos HMAC, credencial de máquina y OTP por vault.
3. El DBA confirma `log_bin=ON` y habilita `log_bin_trust_function_creators=1` durante la ventana controlada.
4. Se autoriza el fixture de paquetes/precios para B02 o se mantiene el caso bloqueado.
5. Se autoriza transporte B24 o se mantiene deshabilitado.

## Secuencia

1. Registrar SHA y artefactos del candidato.
2. Desplegar código en staging sin activar pagos, payment links ni B24.
3. Ejecutar `2026_11_08_220000_create_opaque_medical_benefit_store.php` y las migraciones RC2 posteriores en orden.
4. Verificar tablas, columnas, claves, índices y exactamente nueve triggers.
5. Ejecutar limpieza de caché y reinicio de workers/procesos según el procedimiento de la plataforma.
6. Ejecutar smoke tests de salud, autorización, carrito médico, checkout prepare y denegaciones de Leo.
7. Ejecutar Newman Fase A y después Fase B con el mismo build y environment autorizado.
8. Sanitizar JSON/JUnit y enlazar cada resultado a la matriz de 46 escenarios.

## GO / NO GO

GO solo si backup, migración, triggers, smoke tests, Fase A, Fase B, Seguridad y fixtures están aprobados. NO GO si falta `log_bin_trust_function_creators`, si el conteo de triggers no es nueve, si aparece PII/secreto, si B02 no tiene fuente autorizada o si B24 no tiene autorización de transporte.

## Recuperación

Detener el despliegue ante cualquier divergencia de esquema, error de autorización, efecto financiero, PII o trigger adicional. Preservar logs sanitizados y evidencia del SHA. No ejecutar `down()` ni rollback destructivo sin aprobación explícita del DBA y del responsable de la ventana.
