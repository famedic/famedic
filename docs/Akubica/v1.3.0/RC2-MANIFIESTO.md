# Manifiesto RC2 local

## Identidad

- Rama de integración: `prep/akubica-technical-integration-rc11`.
- Revisión vigente: **Batch 1 · rev7** (IT-0029, revisión correctiva acotada), contrato `1.3.0-rc2`.
- Base de comparación: `92e17741c82a518ca41cfa6c0b52f4fb3da41ede` (merge de rev6; Producto aceptó su alcance contractual y funcional).
- Commit de rev7: se identifica en el manifiesto externo del paquete de entrega (`BATCH1-REV7-COMPACTA-*`, `MANIFIESTO.md`), junto con los sha256 de los artefactos. Este archivo no puede contener el SHA de su propio commit.
- Referencia histórica desplegada en QA: `3fb5feb84afdc212d7680abedb13eac37fee4db8` (release Forge 83302559). El diff de Batch 1 respecto a ese build es sólo documental. Rev7 no se despliega: no tiene build ni release propios.
- El commit, el build y el release de Batch 2 se registran al desplegar su build, que debe usar como referencia contractual la rev7 congelada.
- [Histórico, sustituido en rev7] "Candidato para Batch 2: el último merge de Batch 1 (T8)".
- Antecedente histórico: preparación local en `codex/rc2-local-20261110` sobre `9c060c81e6eda7871e2c902563e3c9e0daac74dc`.
- Estado: rev7 lista para verificación de cierre. Batch 1 no está congelado por decisión propia y nada está certificado en staging.

## Inventario

- `akubica-openapi-v1.3.0-rc2.json`: contrato principal, 76 operaciones (64 paths).
- `r30-first-party-v1.3.0.json`: suplemento first party, 4 operaciones, fuera de Leo.
- `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` y `…-rc2-NEWMAN.environment.template.json`: **colección de certificación** de 69 solicitudes, con guardas de ejecución (ver `RC2-NEWMAN-LEEME.md`). Rev6: B25 exige `leo_negative_writes_authorized` igual a `"1"`; la plantilla trae la variable en `"0"`. Rev7: guarda endurecida (URL efectiva, HTTPS, lista cerrada de hosts QA, banderas exactas y `qa_writes_authorized` para las 15 escrituras positivas); la plantilla agrega `qa_writes_authorized` vacía.
- `Famedic-Akubica-v1.3.0-rc2-LEO-FUNCIONAL.postman_collection.json` y `…-rc2-LEO-FUNCIONAL.environment.template.json`: **colección funcional diferenciada de Leo** (Batch 1 · rev3; rev4), 48 solicitudes en 7 carpetas. Cubre los flujos obligatorios de `RC2-MATRIZ-PERMISOS-LEO.md` que faltaban en la NEWMAN. Corre sólo en staging, sobre el build de Batch 2 y con autorización. Plantilla sin secretos (ver `RC2-LEO-FUNCIONAL-LEEME.md`). Rev4: referencia opaca de perfil fiscal (L13b→L13, LF-L13g nueva), B21x explicitado (perfil, capacidades, línea y marca) y cuerpos de B20/B21x alineados con `LaboratoryCartItemCreateLegacy`; la NEWMAN sigue en 69 solicitudes.
- `RC2-LEO-FUNCIONAL-LEEME.md` y `RC2-LEO-FUNCIONAL-COBERTURA.csv`: guardas, precondiciones y tabla de cobertura de los flujos obligatorios de Leo.
- `RC2-INVENTARIO-CUERPOS.md` y `RC2-INVENTARIO-CUERPOS.csv` (rev5): cuerpos de las 69 + 48 solicitudes contra el OpenAPI, una fila por solicitud (CONFORME, INVALIDO_DELIBERADO, DISCREPANCIA_PENDIENTE o N/A). Rev5 corrige B06b, B25 y LF-L17a y marca los inválidos deliberados; no cambia el OpenAPI. Rev6: sin cambios de clasificación; la fila B25 agrega la comprobación de la bandera en los efectos del pre-request. Rev7: sin cambios de cuerpos ni de clasificación.
- `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json`: **antecedente offline**. Tiene 92 solicitudes (76 operaciones principales y 4 R30) y su guard las omite siempre; el sufijo `-90` del nombre es histórico.
- `Famedic-Akubica-v1.3.0-rc2-synthetic.environment.json`: solo URLs reservadas, sin secretos.
- `RC2-MATRIZ-PERMISOS-LEO.md`: matriz de permisos de Leo (Batch 1 · T3; conflictos C1, C2, C3 y C6 resueltos).
- `RC2-MATRIZ-TRAZABILIDAD-46.csv` (en el repo, en `RC2-PREPARACION-QA/A-DOCUMENTACION/`, no en este directorio): escenarios A01–A12, B01–B26 y R01–R08. Rev7: Pest "20 PASS / 137 assertions" rotulado como histórico no reproducido; B22 normalizada.
- `RC2-MATRIZ-ESCENARIOS-CLASIFICACION.csv` / `.md`: clasificación de lanzamiento S01–S16 (Batch 1 · T8).
- `RC2-PAQUETE-TECNICO-RDS-RUBEN.md`: DDL, prerrequisitos, respaldo, rollback y validaciones. Su anexo `RC2-TRIGGERS-LITERAL.sql` es **externo** a esta carpeta: está en el repositorio, en `RC2-PREPARACION-QA/B-RDS-RUBEN/`.
- `openapi-changelog-v1.3.0-rc2.md`: changelog RC2 vigente. `openapi-changelog-v1.3.0.md` es el histórico previo a RC2.
- `RC2-FE-DE-ERRATAS-REV7.md`: fe de erratas de evidencias históricas (no se reescriben).

## Estado de evidencia (histórico de la preparación local; la certificación de Batch 2 no ha iniciado)

> **Aclaración rev7 (`RC2-FE-DE-ERRATAS-REV7.md`, E-01):** el registro Pest de esta sección es evidencia histórica de la preparación local, **no reproducida** y pendiente de revalidación. La selección homogénea vigente (IT-0023; 6 archivos, mismas condiciones en `3fb5feb8` y en el candidato) reporta 58 fallos y 27 warnings en 85 pruebas: **no es PASS**. Ese resultado se limita a esa selección y no se extrapola a la suite.

- Local (histórico, no reproducido): 20 PASS y 137 assertions en Pest.
- Autorización offline: 24 PASS.
- Staging/Newman/UAT: NOT_RUN.
- B02: BLOCKED por falta de fuente autorizada (histórico; criterio vigente en `RC2-B02-BLOQUEO-FUENTE-PAQUETES.md`, Batch 1 · T6).
- B24: BLOCKED para certificación operativa porque la continuidad permanece deshabilitada.

## Integridad

Los checksums de los artefactos del candidato están en el `SHA256SUMS` y el manifiesto del paquete de entrega de Batch 1 (el `RC2-CHECKSUMS.txt` histórico de la preparación local es un anexo externo **no disponible**: no está en esta carpeta ni en el repositorio). Los archivos se generaron sin secretos, OTP, Bearer, HMAC, cookies, PII ni respuestas reales.
