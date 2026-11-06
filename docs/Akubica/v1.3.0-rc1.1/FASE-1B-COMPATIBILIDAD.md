# Fase 1B — cierre de compatibilidad contractual

Fecha: 5 de noviembre de 2026. **Estado final: PARCIAL / BLOQUEADO para cierre integral.** Correcciones y pruebas locales PASS; validación integral OpenAPI 3.1 pendiente de herramienta aprobada instalada. RC1.1 no está lista para liberación. Fase 0 administrativa permanece ABIERTA.

Se utilizaron como referencias los entregables de Fase 1, la propuesta de beneficios y el diagnóstico, contrastados con el código actual. Los documentos de Fase 1 conservan los resultados y hash de ese momento; este informe y `FASE-1B-VALIDACION.json` describen el candidato actualizado.

## Entregables y estado por bloque

| Bloque | Resultado verificable | Entregable |
|---|---|---|
| A E.164 | **PASS local**: contrato y FormRequest rechazan las entradas inválidas probadas, incluido LF final después de TrimStrings; entradas E.164 canónicas válidas preservadas | Candidato actualizado; FormRequest; pruebas PHP/Python; FASE-1B-VALIDACION.json |
| B Nullable | **PASS local del alcance**: 26 campos auditados, 22 uniones con null respaldadas, cuatro anotaciones retiradas sin ampliar tipos; cero nullable residuales | MATRIZ-NULLABLE-26.md y FASE-1B-NULLABLE.json |
| C OpenAPI integral | **BLOCKED**, no PASS: herramienta aprobada no instalada, sin descargas. Controles locales complementarios PASS | FASE-1B-OPENAPI-INTEGRAL.json y VALIDACION-OPENAPI31-PENDIENTE.md |
| D Históricos | **PASS de preservación/comparación**; aprobación formal de baseline 61 sigue PENDIENTE | MATRIZ-COMPATIBILIDAD-FASE-1B.md y diffs JSON |
| E Beneficios | **Matriz preparada**, D01–D10 siguen PENDIENTES de aprobación; hipótesis no convertidas en decisiones | MATRIZ-DECISIONES-D01-D10.md |

El contrato actualizado sigue en `docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml`: OpenAPI 3.1.0, versión 1.3.0-rc1.1 y **67 operaciones**. No se añadieron operaciones de beneficios opacos ni se cambió ningún operationId respecto de RC1.

## A. Corrección mínima de E.164

Archivo funcional modificado exclusivamente: `app/Http/Requests/Api/V1/Integrations/AtixPhoneLookupRequest.php`.

- Se añadió el modificador PCRE `/D` al patrón existente para que `$` no acepte un salto de línea final.
- Se recupera únicamente `phone` del JSON original en `prepareForValidation`. Es necesario porque el middleware global TrimStrings puede convertir un valor inválido con LF/espacios en un E.164 válido antes de ejecutar el FormRequest. No se cambia el body firmado ni se modifica el middleware global.
- Los demás campos, reglas, autorización y callbacks del FormRequest se mantienen. No se modifican controllers, HMAC, OTP, rutas, modelos ni lógica de beneficios.
- El candidato conserva el patrón con un solo escape de `+` y la restricción explícita contra espacios en blanco. Se actualizó su descripción para reflejar la corrección, sin cambiar históricos.

Prueba independiente con clases reales de Laravel: `scripts/tests/validate_atix_form_request.php` aplica TrimStrings y ConvertEmptyStringsToNull y después preparación/reglas del FormRequest real mediante un contenedor aislado. No inicia la aplicación, no usa rutas/HMAC/OTP, DB o red y no imprime teléfonos.

La matriz principal prueba **8 positivos** (8–15 dígitos, primer dígito no cero) y **20 negativos** (límites inválidos, cero inicial, ausencia de `+`, letras, barra invertida, espacios, tabulador, LF/CR/CRLF, NBSP, NUL y tipos no string). Los resultados coinciden entre JSON Schema y la validación PHP aislada. Hay además pruebas separadas positivas y negativas para evitar que un rechazo general pase como éxito.

Compatibilidad: se conservan entradas canónicas válidas. Se rechazan de forma deliberada consumidores que enviaban entradas fuera de E.164 y dependían del trim del servidor. La garantía probada corresponde al body JSON declarado en OpenAPI; no se certifican formatos de transporte no declarados. No se ejecutó una solicitud HTTP real ni se declara probado el build desplegado.

## B. Resolución dirigida de los 26 nullable

La matriz registra por campo: evidencia, si null se emite o se admite como entrada, cambio, aceptación antes/después y consumidores potenciales. El hash del candidato previo guardado en `FASE-1B-NULLABLE.json` coincide con el de Fase 1.

**22 campos normalizados a unión del tipo original con null:** seis de MembershipStatus; categoría/indicaciones de listado; uuid/issued_at de factura; seis de pricing; descripción/categoría de detalle; dos entradas fiscales; referencias adicionales de dirección; motivo de cancelación.

**Cuatro anotaciones retiradas, conservando tipo y opcionalidad:** first_name de reconocimiento y resource_type/resource_id/resend_available_at de OtpChallenge. Los productores legítimos revisados omiten o emiten un valor no nulo; no se amplían tipos sin evidencia. Esto no certifica datos corruptos ni todas las ramas de un servidor no inspeccionado.

No se modificaron indiscriminadamente definiciones: la transformación usa un registro de exactamente 26 rutas revisadas. Se conservaron enum, límites, formatos, required y cierre de propiedades de cada nodo. En solicitudes nullable se documenta admisión de entrada, no se confunde con que una respuesta emita null.

Además de lectura de código, `scripts/tests/validate_nullable_serializers.php` ejecuta en memoria servicios/serializers existentes: confirma seis null de pricing, uuid/issued_at nulos de factura pendiente y omisión de first_name cuando no hay nombre. Usa modelos en memoria sin boot de observers y un binding de DB que falla si se intenta acceder. No se consultaron registros ni se ejecutaron migraciones. En campos basados en columnas nullable se leyó la definición de migración, sin suponer que coincida con el esquema desplegado.

Riesgo para clientes: SDK y consumidores estrictos pueden requerir tipos union y distinguir ausencia de null; generadores que interpretaban nullable como OpenAPI 3.0 pueden cambiar tipos para los cuatro campos retirados. Los payloads de negocio del servidor no se cambian en esta fase. No hay inventario completo de consumidores externos localmente, por lo que no se certifica compatibilidad de todos los clientes.

## C. Validación OpenAPI y autenticación

El validador integral candidato es openapi-spec-validator con soporte OpenAPI 3.1; no se encontró instalación aprobada utilizable. La entrada `scripts/validate-openapi31-full.py` devolvió **BLOCKED, código 3** dentro de WSL. No instaló ni descargó nada. El procedimiento, verificación de versión aprobada y comando están en `VALIDACION-OPENAPI31-PENDIENTE.md`.

Los controles locales complementarios pasan: parseo sin claves duplicadas, refs locales, parámetros, request bodies, schemas inline/componentes, allOf/oneOf, los 26 nullable, ejemplos, operationId y seguridad declarada. Los fixtures positivos deben cumplir el schema específico y, cuando aplica, una sola rama de oneOf: una respuesta genérica no otorga PASS funcional.

Se revisaron declaraciones de autenticación de las **67 operaciones** contra los grupos de rutas locales: operaciones públicas, Bearer para protegidas, HMAC para lookup ATIX y sus seis headers obligatorios. Se prueban negativos de scheme inexistente, scopes inapropiados y body sin content. Esto no prueba autenticación desplegada, políticas condicionales de step-up o denegación efectiva de payment-link para Leo; siguen fuera de esta fase.

## D. Preservación y compatibilidad

Se verificaron los hashes de los tres históricos sin restaurarlos ni sobrescribirlos:

| Referencia | Operaciones | SHA-256 |
|---|---:|---|
| Histórica v1.2.3 en commit 6ffb35c7cdaee26e8deb09398c85ff9bcf7c46e5 | 61 | `6ace97823aa528171f0d5c34c8ec9674d5730c429db2a95c18dc0c560f5b3749` |
| Archivo actual etiquetado v1.2.3 | 67 | `c46ec1f832f2c8a22bdc9cefc2341ab1afc63e01f94dc6cd3946a5ae34e4e2d2` |
| Candidato RC1 histórico | 67 | `bbf110ffb55f9092b7a9678561509e11bfb0eb644251efc2b44ceec3a8d1398b` |

La matriz y el JSON comparan todos contra RC1.1 y separan cambios directos, dependencias, schemas y posibles breaking changes. Las seis altas respecto de la revisión de 61 ya estaban en RC1; no se eliminó ni renombró ninguna operación frente a RC1. Las refs históricas ForbiddenError y TooManyRequestsError siguen registradas como no resueltas en la revisión de 61; no se alteró el histórico para hacerlas pasar.

Aprobación formal de la baseline histórica de 61: **PENDIENTE**. Tampoco se certifica la discrepancia de la etiqueta 1.2.3/67 como un contrato aprobado.

## E. Decisiones de beneficios

Se preparó D01–D10 con regla actual, hipótesis propuesta, impacto Laravel/Akúbica, riesgo, decisión pendiente y pruebas necesarias. Las ocho hipótesis solicitadas son explícitamente de diseño. La regla actual de un beneficio y preview sin consumo ofrece base reutilizable, pero no aprueba por sí misma el flujo nuevo.

No se crearon referencias opacas, credenciales, mecanismos de confirmación, endpoints, reservas o consumo de saldo. Tampoco se alteró la ruta de payment links. Revisar y aprobar la matriz antes de autorizar Fase 2.

## Pruebas ejecutadas

```text
python3 -B -m unittest discover -s scripts/tests -p 'test_*.py' -q
node --test scripts/tests/test-newman-secure.cjs
python3 -B scripts/validate-akubica-rc1-artifacts.py --report docs/Akubica/v1.3.0-rc1.1/FASE-1B-VALIDACION.json
python3 -B scripts/validate-openapi31-full.py --report docs/Akubica/v1.3.0-rc1.1/FASE-1B-OPENAPI-INTEGRAL.json
php -l app/Http/Requests/Api/V1/Integrations/AtixPhoneLookupRequest.php
git diff --check
```

| Resultado | Evidencia |
|---|---|
| **69 pruebas Python PASS**, cero fallos | 31 Fase 0 + 25 Fase 1 + 13 nuevas Fase 1B |
| **4 pruebas Node PASS**, cero fallos | Configuración offline del runner; Newman no se invocó |
| **73 pruebas locales PASS** | Son métodos de prueba con subcasos; no son requests de staging |
| 30 ejemplos válidos y 20 fixtures de respuesta válidos | Validador local; sin usar reportes comprometidos |
| E.164 alineado | 8 positivos y 20 negativos; middleware y FormRequest reales aislados |
| Nullable | 26/26 resueltos; 22 null admitidos y cuatro no ampliados; restricciones preservadas |
| Seguridad declarada | 67 operaciones y headers ATIX, con negativos sintéticos |
| OpenAPI integral | **BLOCKED**, no se cuenta como PASS |
| Sintaxis PHP / diff | Sin errores |

No se ejecutaron suites con RefreshDatabase, bases persistentes ni pruebas de negocio con credenciales reales. El checker de date-time sigue cubriendo la representación con segundos/zona del runtime; la validación integral pendiente no se reemplaza por ese checker local.

## Archivos cambiados en Fase 1B

- Único archivo funcional permitido: `app/Http/Requests/Api/V1/Integrations/AtixPhoneLookupRequest.php`.
- Candidato: `docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml`.
- Herramientas: `scripts/akubica_contract_validation.py`, `scripts/validate-akubica-rc1-artifacts.py`, nuevo `scripts/akubica_nullable_audit.py`, nuevo `scripts/validate-openapi31-full.py`.
- Pruebas: actualización de expectativa E.164 en `scripts/tests/test_akubica_contract_rc11.py`; nuevos `test_akubica_contract_rc11_phase1b.py`, `validate_atix_form_request.php`, `validate_nullable_serializers.php` en ese directorio.
- Documentación/JSON: este informe y las siete matrices/evidencias/procedimientos de Fase 1B enlazados por nombre arriba.
- `.gitignore`: excepciones precisas para estos artefactos revisables; raw, estado operativo y evidencias privadas siguen excluidos.

Se preservaron las modificaciones anteriores y los informes históricos de Fase 0/1. Fuera del FormRequest autorizado no se modificó lógica Laravel. No hubo commits, push, despliegues, migraciones, staging, llamadas a servicios externos, uso de credenciales comprometidas ni ZIP final.

## Gate final y dependencias

1. **Cierre integral de Fase 1B bloqueado** hasta disponer de herramienta aprobada y ejecutar validación integral sin errores contra el SHA exacto actual.
2. La adopción de clientes/SDK y la aprobación formal de baseline 61 requieren revisión; no están implícitas en los PASS locales.
3. D01–D10 siguen pendientes. Confirmar acumulación, monto, preview/reserva, consentimiento, TTL, DTO/privacidad, alcance, legacy, continuidad/permisos y catálogo de errores antes de implementar.
4. Fase 0 sigue abierta: PAT y contención HMAC no verificados. No distribuir, probar staging ni usar material expuesto.

**Detenerse al finalizar. No iniciar Fase 2 ni declarar RC1.1 lista para liberación.**
