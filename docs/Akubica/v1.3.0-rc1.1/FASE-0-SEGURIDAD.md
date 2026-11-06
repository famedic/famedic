# Akúbica API RC1.1 — Fase 0: seguridad de evidencias

Fecha: 5 de noviembre de 2026. Estado: implementación local verificada; **contención administrativa pendiente**. No avanzar automáticamente a otras fases ni distribuir RC1.

## Referencia y alcance

Se contrastó el diagnóstico `DIAGNOSTICO-AKUBICA-v1.3.0-rc1.1.md` con los scripts, rutas Sanctum, configuración ATIX y archivos reales del repositorio. Esta fase modifica exclusivamente herramientas de evidencia y ejecución de pruebas; no modifica OpenAPI, carrito, cupones, membresías, checkout ni autenticación de la aplicación.

No se iniciaron Laravel, Newman, servidores, bases de datos ni conexiones externas. No hubo revocación, rotación, migraciones, commits, push ni despliegues. Las únicas ejecuciones fueron inspecciones locales y pruebas con fixtures sintéticos. Los ZIP creados por las pruebas son temporales, sintéticos y se eliminan al terminar; no se construyó un paquete final RC1.1.

## Inventario del incidente y restricciones locales

Se reconfirmaron tres reportes con SHA-256 `f49a7f9626d253f76c2bb9af157ea7ac74af7c9779307c664334d1084dd9914e`:

- `docs/Akubica/v1.3.0-rc1/newman-staging-report-sanitized.json`.
- `akubica-v1.3.0-rc1-entrega/newman-staging-report-sanitized.json`.
- Copia del mismo archivo en la carpeta de entrega de Downloads indicada para la auditoría.

El ZIP `akubica-v1.3.0-rc1-entrega.zip` conserva el mismo reporte en su interior. Se verificó leyendo el miembro en memoria; no se extrajo ni copió el contenido sensible a archivos nuevos. El hash del reporte coincide con el diagnóstico.

Las dos copias de WSL y el ZIP quedaron con permisos `0600` (propietario). En la copia de Windows se deshabilitó herencia y se verificaron dos reglas de acceso: cuenta actual y SYSTEM. No se cambiaron los bytes de estos artefactos ni se eliminaron históricos. Estas restricciones son locales: no retiran copias ya compartidas, sincronizadas, respaldadas o conservadas en otros equipos.

El builder RC1 anterior queda retirado y devuelve error sin tocar sus salidas. La nueva herramienta no toma ese paquete como fuente. Los reportes operativos futuros quedan en `.private-akubica-evidence/`, ignorado por Git, directorios `0700` y archivos `0600`. No se creó estado operativo nuevo en esta ejecución.

## Cambios implementados

### Proyección de Newman por lista permitida

`scripts/sanitize-newman-report.py` y `scripts/evidence_security.py` construyen un documento nuevo; no intentan limpiar recursivamente y redistribuir el reporte original.

Solo exportan:

- ID sintético de ejecución, caso y aserción, asignados por posición y sin reutilizar IDs de usuario o de conversación.
- Método y ruta contractual parametrizada; se eliminan valores de path, host y query.
- HTTP, código de error declarado en las descripciones de respuestas del contrato, duración y resultado PASS/FAIL/SKIP.

El cuerpo se decodifica en memoria solo para obtener el código contractual de error. No se copia request/response, variables, headers, cookies, scripts, buffers, nombres de casos ni mensajes de aserción. Una ruta no reconocida, respuesta no analizable, error no declarado, falta de aserciones, duración inválida o fallo global sin correspondencia bloquean la salida. Los archivos de salida se crean de forma exclusiva: no se sobrescriben reportes previos.

El argumento opcional de contrato permite usar en el futuro el candidato RC1.1. El default apunta a RC1 existente sin modificarlo. Un código válido de runtime omitido en el contrato bloqueará la exportación hasta reconciliar el contrato; no se convierte en éxito ni se publica su texto libre.

El summary usa el mismo formato permitido de ejecución/casos. **Cambio de formato intencional:** ya no contiene `run`, `environment`, `stats` ni `failures` originales de Newman. Los consumidores de evidencia deberán usar el formato nuevo; las pruebas funcionales de la aplicación permanecen intactas.

### JUnit

`scripts/sanitize-newman-junit.py` reconstruye un JUnit mínimo: IDs sintéticos, tiempos, contadores y elementos vacíos failure/error/skipped. Elimina nombres originales, mensajes, trazas, propiedades y system-out/system-err. Conserva los fallos como fallos: no los oculta con un reemplazo de texto. XML desconocido, DTD/entidades, contadores inconsistentes o resultados contradictorios bloquean la exportación.

### Escáner

`scripts/scan-evidence.py` inspecciona JSON, XML/JUnit, Markdown, texto, YAML, colecciones y environments Postman, ZIP y archivos internos. Reconoce claves sensibles y pares key/value, JSON embebido, Buffer, base64 convencional/URL-safe, OTP corto, patrones de Sanctum/Bearer/JWT, correo, teléfono y claves privadas. Examina también ejemplos del contrato y bloques JSON/YAML dentro de Markdown.

Rechaza formatos desconocidos, JSON duplicado o no finito, errores de parseo, límites de tamaño/profundidad/recorrido, archivos privados/raw/estado, symlinks, miembros ZIP inseguros, cifrados o no analizables. No extrae ZIP al disco. Los límites son 8 MiB por archivo y 32 MiB acumulados de inspección; cambiar esos límites requiere revisión, no omitir el archivo que los excede.

El resultado CLI contiene solo PASS/FAIL y categorías fijas; no imprime rutas proporcionadas, valores, líneas, excepciones ni coincidencias. Cualquier hallazgo devuelve código distinto de cero. Los campos de nombres de datos estructurados se bloquean. **Límite explícito:** no existe detección infalible de nombres personales aislados en prosa arbitraria ni de todo secreto sin contexto; el escáner no reemplaza revisión humana de PII. El builder exige constancia de esa revisión y bloquea formatos no reconocidos. Puede bloquear contenido legítimo que requiera depuración o traslado a un inventario de hashes; no hay bypass automático.

Los nombres/descripciones originales de colecciones no se consideran automáticamente seguros. La colección RC1 deberá adaptarse o revisarse en la fase de evidencias; no se ha declarado que el paquete actual pase el escáner.

### Runners sin secretos en argumentos

Los scripts A/B usan `scripts/run-newman-secure.cjs`, que pasa la configuración a la API de Newman en memoria desde el entorno heredado y el estado restringido. No usan `--env-var`, reporter CLI, `npx` ni descargas automáticas. El runner exige origen HTTPS exacto de staging y no sigue redirecciones.

A genera sus identificadores operativos en el proceso y guarda evidencia por corrida. B lee el estado A restringido y solicita OTP sin eco si no llegó por el entorno. No se imprimen challenge, conversación ni OTP. El wrapper combinado se bloquea para evitar reutilizar un OTP de un challenge anterior. Los raw nuevos se conservan bajo acceso restringido; no se borran automáticamente. Es responsabilidad del administrador aplicar su política de retención.

Estas entradas siguen siendo herramientas operativas que pueden acceder a staging cuando una persona las ejecute con autorización; **no se ejecutaron aquí**. La instalación aprobada de Newman debe estar disponible para `require('newman')`; la ausencia bloquea y no instala nada. No se verificó el flujo completo de entrega OTP ni se emitió una credencial. Los secretos en el entorno siguen siendo accesibles a procesos privilegiados: el runner debe ejecutarse en una cuenta y host de confianza, sin depuración, volcados ni historial de valores.

### Builder futuro RC1.1

`scripts/build-akubica-v1.3.0-rc1.1-entrega.py` funciona por defecto en modo comprobación, sin generar paquete. Requiere un inventario cerrado RC1.1, evidencia separada A/B y constancias administrativas. Los flags de aprobación se completan por el responsable autorizado; no se generó una constancia verdadera en esta fase.

Con una autorización futura, `--build` admite únicamente un destino nuevo llamado `akubica-v1.3.0-rc1.1-entrega`. Rechaza salidas existentes y no reutiliza RC1. Escanea la fuente, vuelve a escanear las copias, genera manifiesto y hashes después de finalizar contenido, construye en temporal, inspecciona el ZIP y comprueba igualdad byte a byte de todos los miembros antes de publicar. El SHA del ZIP se escribe afuera. El hash file no se hashea a sí mismo. Si el escaneo falla no se publica destino ni ZIP.

El builder verifica seguridad e inventario, no certifica por sí mismo funcionalidad, compatibilidad o despliegue. Esos gates siguen perteneciendo a las fases posteriores.

## Archivos modificados o agregados

Modificados: `.gitignore`; `scripts/sanitize-newman-report.py`; `scripts/sanitize-newman-junit.py`; `scripts/build-akubica-v1.3.0-rc1-entrega.sh` (ya era un archivo local sin commit); `scripts/run-newman-rc1-staging.sh`; `scripts/run-newman-rc1-staging-phase-a.sh`; `scripts/run-newman-rc1-staging-phase-b.sh`.

Agregados: `scripts/evidence_security.py`; `scripts/scan-evidence.py`; `scripts/build-akubica-v1.3.0-rc1.1-entrega.py`; `scripts/run-newman-secure.cjs`; `scripts/tests/test_evidence_security.py`; `scripts/tests/test-newman-secure.cjs`; este informe, `PROCEDIMIENTO-ADMINISTRATIVO-FASE-0.md` y `FASE-0-PRUEBAS-SINTETICAS.json`.

La modificación previa de `docs/Akubica/v1.3.0-rc1/README-v1.3.0-rc1.md` se conservó y no forma parte de estas correcciones. Los cambios de permisos a los archivos del incidente se detallan arriba.

## Validación local

Comandos sin Laravel, bases de datos, servicios ni datos reales:

```text
python3 -B -m unittest discover -s scripts/tests -p test_evidence_security.py -q
node --test scripts/tests/test-newman-secure.cjs
bash -n scripts/run-newman-rc1-staging.sh scripts/run-newman-rc1-staging-phase-a.sh scripts/run-newman-rc1-staging-phase-b.sh scripts/build-akubica-v1.3.0-rc1-entrega.sh
node --check scripts/run-newman-secure.cjs
git diff --check
```

Las pruebas verifican las cinco regresiones conocidas; preservación de fallos/skips; rutas parametrizadas; códigos de error no declarados; fallo seguro de JSON/Buffer/XML desconocidos; JUnit con mensajes y errores; contadores; OTP corto/base64; colecciones y environments; ZIP anidado y rutas inseguras; logs sin canarios; no sobreescritura; builder con escaneo fallido o aprobación pendiente; construcción/postscan exclusivamente con archivos sintéticos; configuración Newman sin ejecución de red y rechazo de hosts engañosos. Los resultados finales y categorías sintéticas se registran en el JSON adjunto.

Resultado final: **31 pruebas Python + 4 pruebas Node, 35 PASS y cero fallos**. Verificaciones de sintaxis Bash/Node y `git diff --check` sin errores. El escáner acepta el recibo sintético y bloquea los seis canarios registrados con categorías, sin guardar sus valores. No se ejecutaron suites PHP/Laravel ni se afirma que toda la suite de la aplicación pase.

## PASS/FAIL y pendientes

| Control | Condición de PASS | Estado |
|---|---|---|
| Proyección local | Regresiones, fallos de formato y confidencialidad de salida comprobados con fixtures | PASS en pruebas locales |
| Escáner | Detecta canarios y falla sin imprimir valores; unknown/ZIP inseguros bloqueados | PASS en pruebas locales; requiere revisión humana de prosa |
| Builder | No sobreescribe RC1, falla antes de publicar si escaneo/gate no pasan y revisa ZIP | PASS en pruebas sintéticas; paquete real no construido |
| Runners | Sin secretos en argv ni reporter CLI; origen exacto; pruebas de configuración sin red | PASS local; ejecución Newman real pendiente de autorización |
| Evidencia histórica | Bytes conservados y acceso local restringido | PASS local; copias remotas/distribuidas pendientes de inventario administrativo |
| PAT | Revocación selectiva confirmada y rechazo posterior | PENDIENTE; no se modificó DB |
| Integración HMAC | Rotación/retiro coordinado de la clave afectada y rechazo de la anterior | PENDIENTE; no se generó clave nueva |
| Cierre Fase 0 | Todos los controles anteriores y constancias del administrador | **NO CERRADA** |

Riesgos pendientes: credenciales aún no revocadas/rotadas de manera verificable; copias fuera del alcance local; raw operativos futuros que requieren retención restringida; adaptación posterior de consumidores de evidencia y colección; prueba operativa autorizada de runner/exportación con versión aprobada de Newman; revisión humana de prosa y posibles falsos positivos del escáner. No se solucionaron las observaciones funcionales/contractuales del diagnóstico ni se avanzó a Fase 1.
