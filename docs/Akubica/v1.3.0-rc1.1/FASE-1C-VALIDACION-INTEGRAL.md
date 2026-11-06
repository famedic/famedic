# Fase 1C — validacion integral OpenAPI 3.1

Fecha: 5 de noviembre de 2026. **Resultado: BLOCKED. La validacion integral no se ejecuto.** No existe herramienta aprobada instalada ni autorizacion expresa de instalacion/descarga. No se declara PASS del contrato.

## Herramienta exacta propuesta

Se propone openapi-spec-validator **0.9.0**, licencia declarada **Apache-2.0**, con la clase explicita OpenAPIV31SpecValidator. Las fuentes primarias confirman soporte OpenAPI 3.1 y Python >=3.10,<4.0; Python 3.14 figura en clasificadores. La version tambien soporta 3.2, por lo que no se debe usar un alias que seleccione automaticamente la ultima v3.

Fuentes: [publicacion 0.9.0](https://pypi.org/project/openapi-spec-validator/0.9.0/), [dependencias de la etiqueta](https://github.com/python-openapi/openapi-spec-validator/blob/0.9.0/pyproject.toml), [API OpenAPI 3.1](https://python-openapi.org/openapi-spec-validator/docs/latest/python.html).

## Instalacion disponible y compatibilidad

- En /usr/bin/python3 se comprobo CPython 3.14.4, Linux x86_64, glibc 2.43; jsonschema 4.19.2, PyYAML 6.0.3 y referencing 0.36.2 ya existentes.
- openapi-spec-validator y openapi-schema-validator no estan instalados; tampoco existe declaracion de version aprobada en el entorno revisado.
- Las ubicaciones conocidas de venv del proyecto/usuario y PATH revisados no aportan una instalacion aprobada reutilizable. No se afirma haber inventariado todos los equipos o interpretes ajenos.
- venv esta disponible; pip y ensurepip estan ausentes del interprete inspeccionado. Falta aprovisionar un instalador aprobado; se propone pip 26.3, MIT, sin cambiar Python global.
- La herramienta exige jsonschema >=4.26.0,<5, superior al 4.19.2 local. Por ello la propuesta usa un venv nuevo sin system-site-packages. No se actualizaron dependencias existentes.
- Compatibilidad de 20 wheels segun metadatos/tags verificada para este objetivo. No se importaron ni probaron los paquetes propuestos; la compatibilidad de instalacion queda pendiente.

## Dependencias directas de 0.9.0

| Dependencia | Rango declarado | Version propuesta fijada |
|---|---|---|
| jsonschema | >=4.26.0,<5.0.0 | 4.26.0 |
| jsonschema-path | >=0.5.0,<0.6.0 | 0.5.0 |
| lazy-object-proxy | >=1.7.1,<2.0 | 1.13.0 |
| openapi-schema-validator | >=0.9.0,<0.10.0 | 0.9.0 |
| pydantic | >=2.0.0,<3.0.0 | 2.14.3 |
| pydantic-settings | >=2.0.0,<3.0.0 | 2.16.2 |

PyYAML 6.0.3 se fija ademas como dependencia del wrapper local. El conjunto completo propuesto es:

| Paquete | Version exacta | Licencia declarada |
|---|---|---|
| annotated-types | 0.8.0 | MIT |
| attrs | 26.1.0 | MIT |
| jsonschema | 4.26.0 | MIT |
| jsonschema-path | 0.5.0 | Apache-2.0 |
| jsonschema-specifications | 2025.9.1 | MIT |
| lazy-object-proxy | 1.13.0 | BSD-2-Clause |
| openapi-schema-validator | 0.9.0 | BSD-3-Clause |
| openapi-spec-validator | 0.9.0 | Apache-2.0 |
| pathable | 0.6.0 | Apache-2.0 |
| pydantic | 2.14.3 | MIT |
| pydantic-core | 2.50.4 | MIT |
| pydantic-settings | 2.16.2 | MIT |
| python-dotenv | 1.2.4 | BSD-3-Clause |
| pyyaml | 6.0.3 | MIT |
| referencing | 0.37.0 | MIT |
| rfc3339-validator | 0.1.4 | MIT license |
| rpds-py | 2026.9.1 | MIT |
| six | 1.17.0 | MIT |
| typing-extensions | 4.16.0 | PSF-2.0 |
| typing-inspection | 0.4.5 | MIT |

Las licencias se tomaron de metadatos oficiales por release, con URLs en el manifiesto. No constituyen aprobacion legal ni auditoria de cada archivo o del vendoring del instalador. Se verificaron 34 requisitos activos entre 20 paquetes; no se ejecuto el resolver pip.

## Procedimiento reproducible preparado, pendiente

FASE-1C-INSTALACION-PENDIENTE.md contiene precondiciones y comandos exactos. FASE-1C-requirements-propuesto.lock fija versiones y SHA-256 de wheels; FASE-1C-DEPENDENCIAS-PROPUESTAS.json conserva plataforma, dependencias, licencias y origen. No son una instalacion aprobada ni un lock probado por el resolver.

La propuesta exige autorizacion expresa para aprovisionar pip 26.3 y descargar/instalar los 20 paquetes en un venv nuevo fuera del repo. No se usan sudo, instalaciones globales, system-site-packages, builds desde fuente ni sustitucion de hashes. Si falta el instalador aprobado o hay conflicto, detenerse.

Solo se consultaron documentacion y metadatos publicos por HTTPS. **No se descargaron wheels/sdists ni se instalaron dependencias**, no se creo un venv y no se ejecutaron servicios FAMEDIC.

## Resultado verificable asociado al contrato

Nueva evidencia: FASE-1C-OPENAPI-INTEGRAL.json. Se invoco scripts/validate-openapi31-full.py con el interprete actual y un archivo de resultado nuevo:

- Resultado: **BLOCKED**, codigo de proceso WSL **3**.
- Motivo: APPROVED_FULL_VALIDATOR_NOT_INSTALLED.
- validation_executed=false; downloads_performed=false.
- SHA-256 del candidato: `a737a0c8380ac470f20d95bc3f08739cf2cd38a71092e1e80c4b1a959905010f`.
- Contrato: docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml, sin cambios en esta fase.
- Evidencia BLOCKED anterior de Fase 1B conservada byte por byte: SHA-256 `e6aee02f4ad1fc3605fc6aafa518a97c581a61c2f1650e2609d815e79e773583`.

El wrapper se fortalecio para calcular el hash de los mismos bytes que entrega al parser, registrar que no valido cuando falta herramienta/aprobacion y rechazar sobreescritura de evidencias existentes. No se altero el resultado de Fase 1B.

## Cobertura y pruebas

La estructura completa, referencias, operaciones, parametros, request bodies, responses y schemas se evaluaran con OpenAPIV31SpecValidator **solo despues de autorizar e instalar**. No se atribuye a la fase actual ese control integral. Los PASS locales anteriores de Fase 1B no lo sustituyen.

Se ejecutaron **72 pruebas Python y cuatro Node: 76 pruebas locales sin fallos**, incluidas tres nuevas de hash del input, bloqueo sin aprobacion y proteccion de evidencia historica. Son pruebas de herramientas y regresion con datos sinteticos, no una validacion integral de este candidato con el paquete ausente. No se uso DB ni staging.

## Riesgos y dependencias residuales

1. El contrato aun podria tener errores de metaschema o semantica OpenAPI que el validador local parcial no detecta. No afirmar integridad integral ni ausencia de breaking changes hasta ejecutar la herramienta aprobada.
2. El lock proviene de metadatos y solo cubre CPython 3.14.4/Linux x86_64 del entorno revisado. Resolver instalacion, verificar hashes e imports nativos y revisar licencias tras autorizacion. Para otra plataforma se revisa el lock; no se relajan controles.
3. La aprobacion contractual formal de la baseline de 61 operaciones sigue pendiente; sus referencias historicas rotas se conservan. Los historicos no se modificaron.
4. Los clientes/SDK, comportamiento desplegado y permisos efectivos de Leo no quedan certificados por una validacion de documento. Las decisiones D01-D10 siguen sin aprobar.
5. Fase 0 administrativa permanece abierta por PAT/HMAC; no se utilizaron credenciales reales ni se probo staging. No hay autorizacion de distribucion o liberacion.

## Archivos de esta fase

- Modificado: scripts/validate-openapi31-full.py (identidad y preservacion de evidencia).
- Agregado: scripts/tests/test_openapi31_phase1c.py (tres pruebas sinteticas).
- Modificado: .gitignore, solo excepciones precisas para los cinco entregables de Fase 1C.
- Agregados: este informe, FASE-1C-INSTALACION-PENDIENTE.md, FASE-1C-OPENAPI-INTEGRAL.json, FASE-1C-DEPENDENCIAS-PROPUESTAS.json y FASE-1C-requirements-propuesto.lock.
- Sin cambios de esta fase en Laravel, candidato OpenAPI, contratos historicos o colecciones. Se conservan modificaciones previas de otras fases.

**Detenerse con BLOCKED. Se requiere autorizacion expresa para aprovisionar el instalador y el entorno propuesto antes de completar la validacion. No hubo commit, push, deploy, ZIP ni avance a Fase 2.**
