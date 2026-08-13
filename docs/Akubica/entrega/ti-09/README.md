# TI-09 - Bundle tecnico Akubica

Este directorio define el paquete tecnico curado para el cierre FAMEDIC -> Akubica del Asistente virtual Leo. No duplica los artefactos principales: el manifest referencia rutas relativas del repositorio y `SHA256SUMS.txt` permite verificar integridad desde la raiz del repo.

## Identidad del cierre

- Contrato autoritativo: `docs/Akubica/akubica-openapi.yaml`
- OpenAPI: `v1.2.3`
- Operaciones contractuales: `61`
- Runtime validado en staging: Forge release `75401580`
- SHA runtime desplegado/probado: `0b46a096a3902630e6ae0e3088c266c790d31b0f`
- Regresion final TI-08: `767/767 PASS`, `3969 assertions`, `0 failures`, `0 skipped`

Los commits documentales posteriores a `0b46a096a3902630e6ae0e3088c266c790d31b0f` agregan evidencias, Postman y este bundle; no fueron desplegados y no modifican el runtime validado.

## Contenido

- `ti-09-technical-bundle-manifest.md`: inventario curado con proposito, hash, release/SHA asociado y sanitizacion.
- `SHA256SUMS.txt`: checksums SHA-256 de los archivos del paquete.
- Contrato OpenAPI y changelogs `1.2.2`/`1.2.3`.
- Coleccion Postman final, README y environments sanitizados.
- Documentacion de retry/read-back, errores y correlation.
- Evidencias TI-03, TI-05, TI-06, TI-07, TI-08 y baseline historico `755/755`.
- Runbook EUL-U01 de fixtures sinteticos UAT, como referencia documental; TI-10 sigue siendo gate pre-UAT si permanece abierto.

## Verificacion

Ejecutar desde la raiz del repositorio:

```bash
sha256sum -c docs/Akubica/entrega/ti-09/SHA256SUMS.txt
```

El resultado esperado es `OK` para todos los archivos listados.

## Exclusiones

No forman parte del bundle: `.env` reales, secretos, passwords, bearer tokens, OTP reales, grants, cookies, DSN, signed URLs reales, PII, SQLite temporal, JUnit privado TI-08, `vendor`, `node_modules`, caches, logs no sanitizados, ZIP/RAR historicos no curados ni archivos `Zone.Identifier`.

## Gates

TI-01..TI-09 cubren el cierre tecnico principal. TI-10 fixtures/control de staging pre-UAT y TI-11 observabilidad pre-UAT son gates separados si continuan abiertos.

Este bundle no autoriza produccion y no autoriza UAT por si mismo.
