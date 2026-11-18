# Manifiesto RC2 local

## Identidad

- Rama de integración: `prep/akubica-technical-integration-rc11`.
- Candidato para Batch 2: el último merge de Batch 1 (T8) en `prep/akubica-technical-integration-rc11`. El SHA exacto y los sha256 de los artefactos están en el paquete de entrega de Batch 1 (`BATCH1-ENTREGA-*`). La base es `3fb5feb84afdc212d7680abedb13eac37fee4db8`, que es el build desplegado en QA (release 83302559). El diff respecto a ese build es sólo documental. El commit y el release finales de Batch 2 se registran al desplegar su build.
- Antecedente histórico: preparación local en `codex/rc2-local-20261110` sobre `9c060c81e6eda7871e2c902563e3c9e0daac74dc`.
- Estado: preparación local; no certificado para staging.

## Inventario

- `akubica-openapi-v1.3.0-rc2.json`: contrato principal, 76 operaciones (64 paths).
- `r30-first-party-v1.3.0.json`: suplemento first party, 4 operaciones, fuera de Leo.
- `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` y `…-rc2-NEWMAN.environment.template.json`: **colección funcional** de 69 solicitudes, con guardas de ejecución (ver `RC2-NEWMAN-LEEME.md`).
- `Famedic-Akubica-v1.3.0-rc2-LEO-FUNCIONAL.postman_collection.json` y `…-rc2-LEO-FUNCIONAL.environment.template.json`: **colección funcional diferenciada de Leo** (Batch 1 · rev3; rev4), 48 solicitudes en 7 carpetas. Cubre los flujos obligatorios de `RC2-MATRIZ-PERMISOS-LEO.md` que faltaban en la NEWMAN. Corre sólo en staging, sobre el build de Batch 2 y con autorización. Plantilla sin secretos (ver `RC2-LEO-FUNCIONAL-LEEME.md`). Rev4: referencia opaca de perfil fiscal (L13b→L13, LF-L13g nueva), B21x explicitado (perfil, capacidades, línea y marca) y cuerpos de B20/B21x alineados con `LaboratoryCartItemCreateLegacy`; la NEWMAN sigue en 69 solicitudes.
- `RC2-LEO-FUNCIONAL-LEEME.md` y `RC2-LEO-FUNCIONAL-COBERTURA.csv`: guardas, precondiciones y tabla de cobertura de los flujos obligatorios de Leo.
- `RC2-INVENTARIO-CUERPOS.md` y `RC2-INVENTARIO-CUERPOS.csv` (rev5): cuerpos de las 69 + 48 solicitudes contra el OpenAPI, una fila por solicitud (CONFORME, INVALIDO_DELIBERADO, DISCREPANCIA_PENDIENTE o N/A). Rev5 corrige B06b, B25 y LF-L17a y marca los inválidos deliberados; no cambia el OpenAPI.
- `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json`: **antecedente offline**. Tiene 92 solicitudes (76 operaciones principales y 4 R30) y su guard las omite siempre; el sufijo `-90` del nombre es histórico.
- `Famedic-Akubica-v1.3.0-rc2-synthetic.environment.json`: solo URLs reservadas, sin secretos.
- `RC2-MATRIZ-PERMISOS-LEO.md`: matriz de permisos de Leo (Batch 1 · T3; conflictos C1, C2, C3 y C6 resueltos).
- `RC2-MATRIZ-TRAZABILIDAD-46.csv` (en el repo, en `RC2-PREPARACION-QA/A-DOCUMENTACION/`, no en este directorio): escenarios A01–A12, B01–B26 y R01–R08 (histórico).
- `RC2-MATRIZ-ESCENARIOS-CLASIFICACION.csv` / `.md`: clasificación de lanzamiento S01–S16 (Batch 1 · T8).
- `RC2-PAQUETE-TECNICO-RDS-RUBEN.md`: DDL, prerrequisitos, respaldo, rollback y validaciones.

## Estado de evidencia (histórico de la preparación local; la certificación de Batch 2 no ha iniciado)

- Local: 20 PASS y 137 assertions en Pest.
- Autorización offline: 24 PASS.
- Staging/Newman/UAT: NOT_RUN.
- B02: BLOCKED por falta de fuente autorizada (histórico; criterio vigente en `RC2-B02-BLOQUEO-FUENTE-PAQUETES.md`, Batch 1 · T6).
- B24: BLOCKED para certificación operativa porque la continuidad permanece deshabilitada.

## Integridad

Los checksums de los artefactos del candidato están en el `SHA256SUMS` y el manifiesto del paquete de entrega de Batch 1 (el `RC2-CHECKSUMS.txt` histórico no está en este directorio). Los archivos se generaron sin secretos, OTP, Bearer, HMAC, cookies, PII ni respuestas reales.
