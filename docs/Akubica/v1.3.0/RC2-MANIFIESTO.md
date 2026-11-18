# Manifiesto RC2 local

## Identidad

- Rama de integración: `prep/akubica-technical-integration-rc11`.
- Candidato para Batch 2: el commit de merge de este cambio documental de Batch 1 (tareas 1–2) sobre `3fb5feb84afdc212d7680abedb13eac37fee4db8`, que es el build desplegado en QA (release 83302559). El diff respecto a ese build es sólo documental. El commit y el release finales de Batch 2 se registran al desplegar su build.
- Antecedente histórico: preparación local en `codex/rc2-local-20261110` sobre `9c060c81e6eda7871e2c902563e3c9e0daac74dc`.
- Estado: preparación local; no certificado para staging.

## Inventario

- `akubica-openapi-v1.3.0-rc2.json`: contrato principal, 76 operaciones (64 paths).
- `r30-first-party-v1.3.0.json`: suplemento first party, 4 operaciones, fuera de Leo.
- `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json`: 92 solicitudes, 76 operaciones principales y 4 R30 suplementarias (el sufijo `-90` del nombre es histórico).
- `Famedic-Akubica-v1.3.0-rc2-synthetic.environment.json`: solo URLs reservadas, sin secretos.
- `RC2-MATRIZ-PERMISOS-LEO.md`: matriz de permisos de Leo (Batch 1 · T3; conflictos C1, C2, C3 y C6 resueltos).
- `RC2-MATRIZ-TRAZABILIDAD-46.csv`: escenarios A01–A12, B01–B26 y R01–R08.
- `RC2-PAQUETE-TECNICO-RDS-RUBEN.md`: DDL, prerrequisitos, respaldo, rollback y validaciones.

## Estado de evidencia

- Local: 20 PASS y 137 assertions en Pest.
- Autorización offline: 24 PASS.
- Staging/Newman/UAT: NOT_RUN.
- B02: BLOCKED por falta de fuente autorizada (histórico; criterio vigente en `RC2-B02-BLOQUEO-FUENTE-PAQUETES.md`, Batch 1 · T6).
- B24: BLOCKED para certificación operativa porque la continuidad permanece deshabilitada.

## Integridad

Los checksums de los artefactos están en `RC2-CHECKSUMS.txt`. Los archivos se generaron sin secretos, OTP, Bearer, HMAC, cookies, PII ni respuestas reales.
