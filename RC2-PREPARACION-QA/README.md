# RC2 Preparación QA

Paquete local revisable para QA. No contiene credenciales, datos reales ni una colección desbloqueada.

## Contenido

- `A-DOCUMENTACION`: OpenAPI RC2 con 74 operaciones, R30 separado, delta contractual y matriz de 46 escenarios.
- `B-RDS-RUBEN`: migración fuente, documento técnico y anexo literal de los nueve triggers.
- `C-POSTMAN-OFFLINE`: colección RC2 protegida, environment sintético y copia original protegida.
- `D-CHECKLIST-QA`: checklist de habilitación, informe local y condiciones para Newman.

## Estado

- ATIX H04: assert corregido a HTTP 202.
- B02: `BLOCKED`; falta fuente autorizada de paquetes y precios.
- B24: `BLOCKED`; continuidad permanece deshabilitada.
- Pruebas locales: 20 PASS y 137 assertions.
- Autorización offline: 24 PASS.
- Staging/Newman/UAT: `NOT_RUN`.

La colección ejecutable autorizada para Newman solo podrá generarse después de aprobación de transporte, secretos en vault, fixtures QA, revisión de Seguridad y autorización explícita de la ventana.

La copia `*-offline-protected-original.json` conserva el artefacto previo únicamente para auditoría de procedencia. No debe ejecutarse ni distribuirse; la colección corregida es la que termina en `-rc2-90.postman_collection.json`.
