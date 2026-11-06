# Validación integral OpenAPI 3.1 — control pendiente

Estado real de esta ejecución: **BLOCKED**, herramienta integral aprobada no instalada. No se descargaron dependencias y no se presenta el validador propio como sustituto de un validador integral.

## Inventario local comprobado

En el Python WSL usado por el proyecto están disponibles jsonschema 4.19.2, PyYAML 6.0.3 y referencing 0.36.2. No se encontraron openapi-spec-validator, openapi-schema-validator, prance o schemathesis en ese intérprete; tampoco un ejecutable openapi-spec-validator, redocly, swagger-cli, spectral o vacuum en el PATH WSL revisado. Los manifiestos del proyecto y la búsqueda local de herramientas no aportaron una instalación aprobada de un validador integral. Esto no constituye un inventario de todos los entornos de otros equipos.

Herramienta candidata para aprobación: **openapi-spec-validator**, proyecto `python-openapi/openapi-spec-validator`, clase pública `OpenAPIV31SpecValidator`. Seleccionar y aprobar una versión exacta que soporte OpenAPI 3.1, junto con dependencias y licencia, mediante el procedimiento del equipo. No se afirma una versión «más reciente» ni se aprueba una instalación en este informe.

## Cómo ejecutar, sin instalación automática

La entrada local `scripts/validate-openapi31-full.py`:

1. Devuelve BLOCKED/código 3 si no está instalada la herramienta; no usa pip, npm, npx ni descargas.
2. Si existe, compara la versión instalada con la versión que el responsable haya aprobado explícitamente en `AKUBICA_APPROVED_OPENAPI_VALIDATOR_VERSION`. Esa variable solo identifica la versión; no reemplaza la aprobación del equipo ni contiene secretos.
3. Bloquea referencias externas del contrato; el candidato actual solo utiliza refs locales.
4. Invoca `OpenAPIV31SpecValidator(spec).iter_errors()` y devuelve FAIL/código 1 ante errores; PASS/código 0 solo si esa ejecución integral termina sin errores.
5. Imprime conteos/categorías, sin payloads ni mensajes de excepción que puedan contener valores. La salida JSON de esta fase está en `FASE-1B-OPENAPI-INTEGRAL.json` y conserva el resultado BLOCKED.

Comando que se ejecutó localmente y quedó bloqueado por ausencia de herramienta:

```text
python3 -B scripts/validate-openapi31-full.py --report docs/Akubica/v1.3.0-rc1.1/FASE-1B-OPENAPI-INTEGRAL.json
```

Después de aprobar y provisionar la versión en un entorno controlado, el administrador debe establecer la variable anterior al valor exacto aprobado y repetir el comando. Provisión o descarga requieren autorización separada; no se ejecutaron aquí. Conservar la evidencia anterior y registrar fecha, versión, hash del candidato y resultado de la nueva ejecución. Una ejecución integral PASS no cierra por sí sola seguridad, compatibilidad de clientes o runtime.

## Controles complementarios ejecutados ahora

| Área | Control offline ejecutado | Límite |
|---|---|---|
| Referencias | Todas las refs del candidato se resuelven localmente; refs externas bloqueadas | No prueba integración HTTP |
| Parámetros | Duplicados, path params requeridos, headers ATIX y esquemas | No reemplaza todas las restricciones del metaschema OpenAPI |
| Request bodies | Content no vacío, schemas inline, ejemplos y solicitudes nullable | No ejecuta rutas de negocio |
| Response schemas | JSON Schema 2020-12, fixtures por respuesta específica | No se acepta pasar únicamente por respuesta genérica |
| allOf/oneOf | Composición OTP, variantes disjuntas y negativos/híbridos | Cobertura sintética, sin capturas nuevas de staging |
| Nullable | Matriz dirigida de 26 campos; 22 admiten null, 4 no acreditados no se amplían | No se consultó DB para medir ocurrencia |
| Ejemplos | 30 ejemplos válidos, 20 fixtures de respuestas y negativos | No es validación integral de metadatos OpenAPI |
| operationId | 67 IDs preservados frente a RC1, sin altas/bajas | Baseline histórica pendiente de aprobación formal |
| Seguridad declarada | 67 operaciones contra grupos de rutas locales; ATIX requiere sus seis headers; schemes y scopes coherentes | No acredita enforcement desplegado, step-up condicional ni denegación de payment-link a Leo |

**Criterio de cierre del bloque C:** conservar PASS local, obtener herramienta/version aprobada instalada, ejecutar validación integral sin errores y vincular resultado al SHA-256 exacto del candidato. Hasta entonces el cierre integral de Fase 1B no es PASS.
