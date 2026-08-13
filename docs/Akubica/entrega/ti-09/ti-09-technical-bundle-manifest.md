# TI-09 - Technical Bundle Manifest

Runtime probado/desplegado: Forge release `75401580`, SHA `0b46a096a3902630e6ae0e3088c266c790d31b0f`.

La documentacion puede quedar en un HEAD posterior por evidencias TI-07/TI-08/TI-09. Esos commits documentales no fueron desplegados y no modifican el runtime validado.

## Archivos incluidos

| Archivo | Proposito | SHA-256 | Release/SHA asociado | Sanitizado |
|---|---|---|---|---|
| `docs/Akubica/akubica-openapi.yaml` | Contrato OpenAPI autoritativo v1.2.3 | `6ace97823aa528171f0d5c34c8ec9674d5730c429db2a95c18dc0c560f5b3749` | runtime `75401580` / `0b46a096a3902630e6ae0e3088c266c790d31b0f` | Si |
| `docs/Akubica/openapi-changelog-v1.2.2.md` | Changelog TI-02 | `60a3502c260f192320700bcf39b0867de8609cbe0ff82787bcd218c69028d45c` | documental | Si |
| `docs/Akubica/openapi-changelog-v1.2.3.md` | Changelog TI-03 | `210c1cef4f21a7d2396892c2eb3d17e6e1754c44df019a176bdccba4451bed0c` | documental | Si |
| `postman/Famedic-Akubica-API-v1.postman_collection.json` | Coleccion Postman final OpenAPI v1.2.3 | `641320fc0ef92ca761dafb478489317b63cdcef5f875d25735104b7ab2684320` | documental/client-side | Si |
| `postman/Famedic-Akubica-QA.postman_environment.json` | Environment QA/UAT sanitizado | `5dd9566d56b17c4832ae00c12f01b5c63e9fab870d6ba3f2189eaa2a02ffd0ad` | N/A | Si |
| `postman/Famedic-Akubica-Local.postman_environment.json` | Environment local sanitizado | `19bba18a6f5a610041825c230de9c9b0a60d83a62df3fbabbd15c53d9497bab9` | N/A | Si |
| `postman/Famedic-Akubica-Staging.postman_environment.json` | Environment staging sanitizado | `43fa0ec7b3e0bf688d1e3d0ec4d7e050366c77e8f9eaa84de6402f6304853599` | N/A | Si |
| `postman/Famedic-Akubica-Production.postman_environment.json` | Environment production sanitizado con guard de writes | `1cc6ca831da809a9a96ef96f15d5cc95ca7ce14e13125b23426382c774360c63` | N/A | Si |
| `postman/README.md` | Guia practica Postman | `a8e33de7f26f4c657aa4fc544a087c15bd404ce0a635f16704457e7eb788957a` | documental/client-side | Si |
| `docs/Akubica/ti-04-retry-readback-matrix.md` | Matriz retry/read-back TI-04 | `6a05f4e4ebafbf43286308ed5501560ee830a182c6ec7c273f1f31338a7cea99` | documental | Si |
| `docs/Akubica/api-v1-errors.md` | Contrato de errores API V1 | `4f885c8e83f532d0c2d2c8ec539af84d299d802b8b79511d0671951687556395` | runtime `75401580` / `0b46a096a3902630e6ae0e3088c266c790d31b0f` | Si |
| `docs/Akubica/p1-a6-errors-correlation.md` | Documentacion correlation/retryable | `9085f9103267e38142cb71a83a94342b1f0c4a7ee2e9cab57cf87b89626a0928` | documental | Si |
| `docs/Akubica/evidencias/ti-03-sample-brands.json` | Evidencia catalogo brands TI-03 | `924b2ac57013c08f1fae50fa3f1810cde86a06393602b34d81dd7ec677feb0bb` | documental | Si |
| `docs/Akubica/evidencias/ti-03-sample-categories.json` | Evidencia catalogo categories TI-03 | `338d8411c743120d993e28a8d6761c09a872351f3c6c9994b1b37461522fd644` | documental | Si |
| `docs/Akubica/evidencias/ti-03-sample-stores.json` | Evidencia catalogo stores TI-03 | `74da10f61f6cfa2f88019fd41d85a2d45bcd04816ec64453ea8b8757f58376ab` | documental | Si |
| `docs/Akubica/evidencias/ti-03-sample-test-detail.json` | Evidencia catalogo detail TI-03 | `1d56e6aeb777cc41128911b9bd7db7bf9392e41ba72582da060bc184778102de` | documental | Si |
| `docs/Akubica/evidencias/ti-03-sample-tests.json` | Evidencia catalogo tests TI-03 | `3af36e5d7754cf17c71a781259cea7920011a581a0dc02e53bb09c0458a69ae4` | documental | Si |
| `docs/Akubica/evidencias/ti-05-staging-snapshot-before.md` | Evidencia hardening before TI-05 | `fd5fb9dd207d9aafc570865773bdc7474d599c6707cfe24b709e797463447639` | staging/documental | Si |
| `docs/Akubica/evidencias/ti-05-staging-snapshot-after.md` | Evidencia hardening after TI-05 | `64d0fb64c888408bd7cb0dded6058617de011ecbbb613b42f7d5896b8caa278d` | staging/documental | Si |
| `docs/Akubica/evidencias/ti-05-hardening-checklist.md` | Checklist hardening TI-05 | `6b77c406cb4655c088f632b9c5eb131a97690c09e35c4cc50055d72a3e0a3487` | documental | Si |
| `docs/Akubica/evidencias/ti-06-directed-tests-report.md` | Reporte pruebas dirigidas P0 TI-06 | `58722fac81e33b182b892f8a9ec8cba14aa2b061ad7b7ba69f7f480fe004b247` | documental | Si |
| `docs/Akubica/evidencias/ti-07-staging-deploy-runtime.md` | Evidencia deploy/runtime TI-07 | `9d895a4dd676c1c845ecc6856760755ce5fe97234ce4ff2bfdb4bd9f985483e1` | runtime `75401580` / `0b46a096a3902630e6ae0e3088c266c790d31b0f` | Si |
| `docs/Akubica/evidencias/ti-08-final-regression-report.md` | Reporte regresion final TI-08 | `41dc63469f46ff5bc0d0bce9bb8e5d61963d7ea748fe91766aaa5700c85d8564` | runtime `75401580` / `0b46a096a3902630e6ae0e3088c266c790d31b0f` | Si |
| `docs/Akubica/evidencias/ti-08-final-regression-output.txt` | Output regresion final TI-08 | `9f3609fbb36505b2d0322702f44a73f5a1dc4b80f9b549158f99c3c136ed5e13` | runtime `75401580` / `0b46a096a3902630e6ae0e3088c266c790d31b0f` | Si |
| `docs/Akubica/evidencias/eul-d08-regresion-api-v1-d7e61b4-20260811.md` | Baseline historico API V1 755/755 | `7d6440655ebcaf218e49d54a65d405ac6b4ccdc885960826f5b0413ccc9a8233` | baseline `d7e61b49f2458213f80b6346a323a6b3aa54189f` | Si |
| `docs/Akubica/eul-u01-fixtures-sinteticos-uat.md` | Runbook fixtures sinteticos UAT EUL-U01 | `f31c5af3e20a7b334ed4896bd9ea980a90b50ce9fa7254e44b34fc1eadfeed6d` | TI-10/pre-UAT | Si |

## Archivos de control

Estos archivos describen y verifican el bundle, pero no se listan en la tabla anterior para evitar autorreferencias de checksum:

- `docs/Akubica/entrega/ti-09/README.md`
- `docs/Akubica/entrega/ti-09/ti-09-technical-bundle-manifest.md`
- `docs/Akubica/entrega/ti-09/SHA256SUMS.txt`

`SHA256SUMS.txt` incluye los archivos de control verificables y todos los archivos de la tabla, excepto a si mismo.

## Exclusiones

No incluir `.env` reales, secretos, passwords, bearer tokens, OTP reales, grants, cookies, DSN, signed URLs reales, PII, SQLite temporal, JUnit privado TI-08, `vendor`, `node_modules`, caches, logs no sanitizados, ZIP/RAR historicos no curados ni `Zone.Identifier`.
