# Fase 1C — ejecucion autorizada de validacion integral

Fecha: 5 de noviembre de 2026. **Resultado final: FAIL.** La herramienta ya esta instalada y la validacion integral se ejecuto con autorizacion confirmada. El contrato presenta 33 errores; no se modifico para obtener PASS.

## Autorizacion e instalacion comprobadas

El usuario confirmo expresamente la autorizacion del responsable tecnico para pip 26.3, los 20 paquetes fijados y descargas verificadas por hash, exclusivamente en entornos Python aislados de WSL.

- WSL Ubuntu, CPython 3.14.4, x86_64, glibc 2.43; contrato y lock coinciden con los hashes aprobados.
- Se verificaron **21 wheels por SHA-256** antes de ejecutarlos: uno de pip 26.3 y los 20 del lock. No se descargaron o compilaron sdists ni paquetes adicionales.
- Se crearon dos venv nuevos fuera del repositorio, sin system-site-packages: instalador pip y validador. El Python global conserva sus versiones previas y no contiene pip/openapi-spec-validator nuevos.
- Ante ausencia de pip/ensurepip globales, el wheel verificado de pip se uso temporalmente desde su archivo para instalar pip en su propio venv; despues ese instalador provisiono el venv del validador desde los wheels locales, con hashes y sin indice remoto en la instalacion.
- pip check: exit 0. Las 20 versiones coinciden con el manifiesto; importaciones verificadas, incluida OpenAPIV31SpecValidator. El entorno objetivo contiene exactamente 20 distribuciones; pip reside en el venv de instalador separado.
- Las ruedas y logs operativos se conservaron bajo permisos restringidos en /home/developer/.local/share/famedic-validation, fuera de Git. No se incorporan paquetes binarios a los entregables.

## Versiones realmente instaladas

| Paquete | Version instalada |
|---|---|
| pip, instalador separado | 26.3 |
| annotated-types | 0.8.0 |
| attrs | 26.1.0 |
| jsonschema | 4.26.0 |
| jsonschema-path | 0.5.0 |
| jsonschema-specifications | 2025.9.1 |
| lazy-object-proxy | 1.13.0 |
| openapi-schema-validator | 0.9.0 |
| openapi-spec-validator | 0.9.0 |
| pathable | 0.6.0 |
| pydantic | 2.14.3 |
| pydantic-core | 2.50.4 |
| pydantic-settings | 2.16.2 |
| python-dotenv | 1.2.4 |
| pyyaml | 6.0.3 |
| referencing | 0.37.0 |
| rfc3339-validator | 0.1.4 |
| rpds-py | 2026.9.1 |
| six | 1.17.0 |
| typing-extensions | 4.16.0 |
| typing-inspection | 0.4.5 |

## Ejecucion integral y evidencia

Se ejecuto scripts/validate-openapi31-full.py con el Python del venv, AKUBICA_APPROVED_OPENAPI_VALIDATOR_VERSION=0.9.0 y backend jsonschema. Se uso explicitamente la clase OpenAPIV31SpecValidator, sin llamadas a staging ni uso de credenciales reales.

- Resultado: **FAIL**, codigo de salida **1**, validation_executed=true.
- Errores: **33**, categoria **unevaluatedProperties** en objetos Reference Object de respuestas.
- Hash exacto del contrato: `a737a0c8380ac470f20d95bc3f08739cf2cd38a71092e1e80c4b1a959905010f`.
- Evidencia original de la corrida: FASE-1C-OPENAPI-INTEGRAL-AUTORIZADA.json.
- Registro de instalacion/versiones/regresiones: FASE-1C-EJECUCION-AUTORIZADA.json.
- Categorias y ubicaciones: FASE-1C-ERRORES-CONTRACTUALES.json; analisis causal: FASE-1C-ANALISIS-ERRORES.json.

El campo downloads_performed=false del reporte del wrapper significa que el wrapper validador no descarga dependencias. La instalacion autorizada anterior si descargo 21 wheels verificados, registrados por separado; no se oculta esa descarga.

Los reportes BLOCKED anteriores siguen intactos:

| Evidencia historica | SHA-256 conservado |
|---|---|
| FASE-1B-OPENAPI-INTEGRAL.json | `e6aee02f4ad1fc3605fc6aafa518a97c581a61c2f1650e2609d815e79e773583` |
| FASE-1C-OPENAPI-INTEGRAL.json | `74c43b6d4732ab76b0ab210f5fe4d74562d4fab288b2d95ac158f51bc6b2f0c1` |

## Investigacion de los 33 errores

Cada objeto afectado tiene las claves `$ref` y `headers`. El esquema Reference Object que aplica el validador permite `$ref`, `summary` y `description`, y cierra propiedades restantes. Por ello rechaza `headers` como hermano de `$ref`. Es un defecto estructural de la referencia de respuesta, no una autorizacion para abrir globalmente los schemas.

Se comprobaron dos hechos adicionales sin modificar el candidato:

- Los 33 objetos son identicos a los del RC1 historico; se preservaron ambos archivos.
- Los 33 componentes de respuesta referenciados ya contienen **las mismas definiciones de headers** que sus hermanos redundantes.

Un fixture minimo independiente reproduce un error con `$ref` + `headers`; un Response Object completo con headers produce cero errores en ese fixture. Ese resultado sintetico no es un PASS del contrato original.

**Correccion minima propuesta, NO aplicada:** revisar y retirar del candidato los 33 hermanos headers redundantes, manteniendo el `$ref` y las cabeceras ya definidas en los componentes. Si algun caso futuro necesitara cabeceras distintas, usar un Response Object completo o un componente dedicado y una referencia simple. No quitar restricciones de datos ni alterar contratos historicos. Se requiere una nueva autorizacion de correccion contractual.

| Error | Operacion | HTTP de respuesta | Linea del objeto en el candidato |
|---|---|---:|---:|
| error-0001 | `POST /auth/login/request-code` | 503 | 166 |
| error-0002 | `POST /auth/login/verify-code` | 503 | 214 |
| error-0003 | `POST /auth/login/resend-code` | 503 | 257 |
| error-0004 | `POST /auth/register` | 503 | 325 |
| error-0005 | `POST /auth/register/verify-code` | 503 | 374 |
| error-0006 | `POST /auth/register/resend-code` | 503 | 412 |
| error-0007 | `DELETE /auth/token` | 401 | 437 |
| error-0008 | `GET /secure-downloads/{token}` | 503 | 498 |
| error-0009 | `DELETE /cart` | 401 | 835 |
| error-0010 | `GET /cart/totals` | 401 | 866 |
| error-0011 | `DELETE /cart/coupon` | 401 | 1208 |
| error-0012 | `GET /checkout/prepare` | 401 | 1239 |
| error-0013 | `GET /laboratory-appointments/requirements` | 401 | 1386 |
| error-0014 | `GET /orders` | 401 | 1545 |
| error-0015 | `GET /orders/results` | 401 | 1572 |
| error-0016 | `GET /orders/invoices` | 401 | 1599 |
| error-0017 | `GET /orders/{order_id}/products` | 404 | 1631 |
| error-0018 | `GET /orders/{order_id}/status` | 404 | 1661 |
| error-0019 | `PUT /orders/{order_id}/cancel` | 401 | 1687 |
| error-0020 | `GET /orders/{order_id}/results` | 404 | 1731 |
| error-0021 | `POST /orders/{order_id}/results/step-up/request` | 503 | 1828 |
| error-0022 | `POST /orders/{order_id}/results/step-up/verify` | 503 | 1875 |
| error-0023 | `POST /orders/{order_id}/results/secure-link` | 503 | 1946 |
| error-0024 | `GET /orders/{order_id}/invoices` | 404 | 1986 |
| error-0025 | `POST /orders/{order_id}/invoices/{invoice_id}/step-up/request` | 503 | 2096 |
| error-0026 | `POST /orders/{order_id}/invoices/{invoice_id}/step-up/verify` | 503 | 2151 |
| error-0027 | `POST /orders/{order_id}/invoices/{invoice_id}/secure-link` | 503 | 2230 |
| error-0028 | `GET /orders/{order_id}/invoice-request/status` | 404 | 2260 |
| error-0029 | `GET /user/family` | 401 | 2342 |
| error-0030 | `GET /user/payment-methods` | 401 | 2369 |
| error-0031 | `POST /user/tax-profiles` | 401 | 2425 |
| error-0032 | `POST /user/addresses` | 401 | 2566 |
| error-0033 | `POST /user/contacts` | 401 | 2707 |

Distribucion: 15 respuestas 401, cinco 404 y 13 respuestas 503, en 33 operaciones. Todas pertenecen a la misma categoria detectada. Pueden aparecer otros errores tras corregir los estructurales; no se declara completado el resto de chequeos semanticos del contrato por ausencia de errores adicionales en esta salida.

## Regresiones y limites de los PASS

- **72 pruebas Python PASS** en el venv nuevo con jsonschema 4.26.0 y dependencias aprobadas; cero fallos.
- **Cuatro pruebas Node PASS**; cero fallos. Newman y sus solicitudes de negocio no se ejecutaron.
- **76 regresiones locales PASS**, con datos sinteticos y sin DB/staging. Los ZIP sinteticos temporales propios de las pruebas no son un ZIP de entrega; no se genero paquete final.
- Los PASS de instalacion, imports, dependencias y regresion no sustituyen el resultado **FAIL** de la validacion integral.

## Riesgos y pendientes

1. Los 33 errores estructurales impiden aprobar OpenAPI RC1.1. Corregir solo bajo nueva autorizacion y repetir la validacion sobre un hash nuevo, conservando este FAIL.
2. Revisar posibles errores adicionales despues de eliminar el bloqueo estructural; no asumir que los 33 sean todos los problemas contractuales posibles.
3. La aprobacion formal de la baseline historica de 61 operaciones y pruebas de clientes/SDK siguen pendientes. No se tocaron los historicos.
4. Fase 0 administrativa permanece ABIERTA: revocacion PAT y contencion HMAC no confirmadas. Esta instalacion no autoriza staging, distribucion, cobros o payment links para Leo.
5. D01-D10 y desarrollo de beneficios opacos no se aprobaron ni implementaron. No se inicio otra fase.

## Archivos y ubicaciones de esta ejecucion

En el repositorio se agregaron cuatro JSON de resultado/analisis y este informe (cinco archivos); .gitignore recibe excepciones exactas para hacerlos revisables. No se modificaron scripts, Laravel, el candidato, contratos historicos o informes BLOCKED en esta ejecucion. Se conservan los cambios previos del proyecto.

Fuera del repositorio se agregaron el venv del instalador, el venv del validador, wheelhouse, wheel bootstrap/lock de pip y registros restringidos de autorizacion, hashes, instalacion y regresiones. No hubo instalacion global, commit, push, deploy, migracion ni ZIP final.

**Detenerse con FAIL. No corregir el contrato ni avanzar automaticamente.**
