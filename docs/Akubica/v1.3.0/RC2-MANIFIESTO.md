# Manifiesto RC2 local

## Identidad

- Rama: `codex/rc2-local-20261110`
- Base: `9c060c81e6eda7871e2c902563e3c9e0daac74dc`
- Estado: preparación local; no certificado para staging.

## Inventario

- `akubica-openapi-v1.3.0-rc2.json`: contrato principal, 74 operaciones.
- `r30-first-party-v1.3.0.json`: suplemento first party, 4 operaciones, fuera de Leo.
- `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json`: 90 solicitudes, 74 operaciones principales y 4 R30 suplementarias.
- `Famedic-Akubica-v1.3.0-rc2-synthetic.environment.json`: solo URLs reservadas, sin secretos.
- `RC2-MATRIZ-TRAZABILIDAD-46.csv`: escenarios A01–A12, B01–B26 y R01–R08.
- `RC2-PAQUETE-TECNICO-RDS-RUBEN.md`: DDL, prerrequisitos, respaldo, rollback y validaciones.

## Estado de evidencia

- Local: 20 PASS y 137 assertions en Pest.
- Autorización offline: 24 PASS.
- Staging/Newman/UAT: NOT_RUN.
- B02: BLOCKED por falta de fuente autorizada.
- B24: BLOCKED para certificación operativa porque la continuidad permanece deshabilitada.

## Integridad

Los checksums de los artefactos están en `RC2-CHECKSUMS.txt`. Los archivos se generaron sin secretos, OTP, Bearer, HMAC, cookies, PII ni respuestas reales.
