# Fase 1C — instalación aislada pendiente de autorización

Estado: **NO AUTORIZADA / NO EJECUTADA**. No ejecutar los comandos de instalación hasta recibir autorización expresa para aprovisionar el instalador y descargar/instalar las dependencias propuestas. Esta preparación no equivale a esa autorización.

## Versión y alcance propuestos

- Validador: `openapi-spec-validator==0.9.0`, licencia declarada Apache-2.0, clase explícita `OpenAPIV31SpecValidator` para OpenAPI 3.1. Evitar alias de «última versión v3», que en esta publicación también contempla OpenAPI 3.2.
- Python objetivo comprobado localmente: CPython **3.14.4**, Linux x86_64, glibc **2.43**. El requisito declarado del validador es Python >=3.10,<4.0 y el proyecto incluye Python 3.14 en sus clasificadores. Esto acredita compatibilidad declarada, no una importación ejecutada en el entorno nuevo.
- Dependencias objetivo: las **20 versiones exactas** del archivo `FASE-1C-requirements-propuesto.lock`, incluidos validador y PyYAML requerido por el wrapper local. El manifiesto `FASE-1C-DEPENDENCIAS-PROPUESTAS.json` registra requirements, licencias declaradas, URLs y SHA-256 de wheels compatibles según tags locales.
- Instalador propuesto: **pip 26.3**, licencia MIT. Debe existir en un intérprete de herramientas aprobado, señalado por `AKUBICA_INSTALLER_PYTHON`; no se encontró uno utilizable en el intérprete local inspeccionado. Aprovisionarlo también requiere autorización. No modificar el Python global ni dependencias de Laravel para obtenerlo.

Los metadatos de PyPI se consultaron por HTTPS, sin descargar wheels, sdists ni código de dependencias. La comprobación de cierre de metadatos cubre 34 requisitos activos y 20 paquetes. No es un lock generado por pip ni una instalación probada: el resolver, los artefactos y sus importaciones deben verificarse después de autorizar. Si el instalador detecta conflicto, falta un wheel o un hash no coincide, **detenerse**; no quitar hashes, actualizar versiones automáticamente ni permitir builds desde fuentes.

## Precondiciones

1. Autorizar expresamente el validador 0.9.0, el conjunto bloqueado de 20 paquetes y el instalador pip 26.3. Revisar licencias declaradas del manifiesto; no se ha efectuado una auditoría jurídica del contenido de cada wheel ni del código vendorizado de pip.
2. Disponer de un intérprete de instalador aprobado con pip 26.3. La variable de ruta debe apuntar a ese intérprete, no a una ruta supuesta. Si falta, detenerse y coordinar su aprovisionamiento autorizado.
3. Mantener CPython 3.14.4 y la plataforma objetivo. Para otra plataforma se necesita revisar y aprobar un lock nuevo; no sustituir wheels silenciosamente.
4. Comprobar que el contrato conserva SHA-256 `a737a0c8380ac470f20d95bc3f08739cf2cd38a71092e1e80c4b1a959905010f`. Un cambio exige nueva identidad de validación.
5. Usar rutas nuevas para entorno y caché fuera del repositorio. No reutilizar ni borrar entornos existentes.

## Comandos posteriores a la autorización (no ejecutados)

Desde WSL Ubuntu, tras asignar `AKUBICA_INSTALLER_PYTHON` a un intérprete realmente aprobado:

```bash
set -euo pipefail
umask 077
: "${AKUBICA_INSTALLER_PYTHON:?Falta el intérprete aprobado con pip 26.3}"

AKUBICA_REPO=/home/developer/projects/famedic
AKUBICA_TOOL_ENV="$HOME/.local/share/famedic-validation/openapi31-0.9.0-py3144"
AKUBICA_WHEELHOUSE="$HOME/.local/share/famedic-validation/wheels-openapi31-0.9.0-py3144"
AKUBICA_LOCK="$AKUBICA_REPO/docs/Akubica/v1.3.0-rc1.1/FASE-1C-requirements-propuesto.lock"

test ! -e "$AKUBICA_TOOL_ENV"
test ! -e "$AKUBICA_WHEELHOUSE"
python3 -I -c 'import platform; assert platform.python_version() == "3.14.4"'
"$AKUBICA_INSTALLER_PYTHON" -I -c 'import importlib.metadata; assert importlib.metadata.version("pip") == "26.3"'

# venv está disponible; --without-pip evita depender del ensurepip ausente.
python3 -I -m venv --without-pip "$AKUBICA_TOOL_ENV"
mkdir -p "$AKUBICA_WHEELHOUSE"

# Esta descarga requiere autorización; solo wheels y hashes fijados.
PIP_CONFIG_FILE=/dev/null "$AKUBICA_INSTALLER_PYTHON" -m pip --isolated \
  --python "$AKUBICA_TOOL_ENV/bin/python" download \
  --index-url https://pypi.org/simple --only-binary=:all: --require-hashes \
  --no-cache-dir --dest "$AKUBICA_WHEELHOUSE" --requirement "$AKUBICA_LOCK"

# Instalación desde el conjunto descargado y verificado, sin índice remoto.
PIP_CONFIG_FILE=/dev/null "$AKUBICA_INSTALLER_PYTHON" -m pip --isolated \
  --python "$AKUBICA_TOOL_ENV/bin/python" install \
  --no-index --find-links "$AKUBICA_WHEELHOUSE" --only-binary=:all: \
  --require-hashes --no-cache-dir --requirement "$AKUBICA_LOCK"

"$AKUBICA_INSTALLER_PYTHON" -m pip --isolated \
  --python "$AKUBICA_TOOL_ENV/bin/python" check
```

No se usa `--system-site-packages`, sudo, apt, `--upgrade`, `get-pip.py` ni instalación global. El mecanismo `pip --python` puede administrar un venv que no contiene pip; está documentado por pip. **En este equipo todavía falta el instalador aprobado**, por lo que estos comandos no constituyen una instalación disponible ni lista para ejecutar sin ese requisito.

## Verificar versiones e imports antes de validar

Solo después de una instalación autorizada, comprobar todas las versiones fijadas contra el manifiesto, no únicamente el paquete raíz. Este control no imprime datos del contrato:

```bash
"$AKUBICA_TOOL_ENV/bin/python" -I - "$AKUBICA_REPO" <<'PY'
import importlib.metadata, json, sys
from pathlib import Path
root = Path(sys.argv[1])
manifest = json.loads((root / 'docs/Akubica/v1.3.0-rc1.1/FASE-1C-DEPENDENCIAS-PROPUESTAS.json').read_text())
for entry in manifest['packages']:
    assert importlib.metadata.version(entry['name']) == entry['version']
from openapi_spec_validator import OpenAPIV31SpecValidator
import yaml
print('Entorno fijado: versiones e imports verificados')
PY
```

Si aparece un error de importación nativa o de resolución, registrar el bloqueo y revisar el lock. No declarar compatibilidad de instalación a partir de metadatos solamente.

## Ejecutar la validación integral autorizada

Usar un resultado nuevo para conservar tanto el BLOCKED de Fase 1B como el de Fase 1C. No se activa el entorno ni se modifica el shell de la aplicación. Se reduce el entorno heredado y se ejecuta desde el directorio de herramientas, lejos del `.env` del proyecto:

```bash
cd "$AKUBICA_TOOL_ENV"
env -i HOME="$HOME" PATH=/usr/bin:/bin LANG=C.UTF-8 \
  PYTHONNOUSERSITE=1 \
  AKUBICA_APPROVED_OPENAPI_VALIDATOR_VERSION=0.9.0 \
  OPENAPI_SPEC_VALIDATOR_SCHEMA_VALIDATOR_BACKEND=jsonschema \
  "$AKUBICA_TOOL_ENV/bin/python" -B \
  "$AKUBICA_REPO/scripts/validate-openapi31-full.py" \
  --contract "$AKUBICA_REPO/docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml" \
  --report "$AKUBICA_REPO/docs/Akubica/v1.3.0-rc1.1/FASE-1C-OPENAPI-INTEGRAL-AUTORIZADA.json"
```

El wrapper calcula SHA-256 de los mismos bytes que pasa al parser, exige versión instalada aprobada y rechaza refs externas. La variable de versión es un control de coincidencia, **no autorización por sí sola**. El resultado debe indicar `validation_executed=true`, versión instalada, SHA exacto y conteo de errores. No imprime el contrato ni mensajes con valores de sus ejemplos.

Si hay FAIL, conservar evidencia y revisar los errores sin relajar respuestas a schemas genéricos ni modificar históricos automáticamente. Si hay PASS, complementar con las pruebas locales de ejemplos/variantes y revisión de seguridad declarada; no equivale a cierre PAT/HMAC, pruebas de staging ni autorización de liberar RC1.1.

## Fuentes primarias consultadas

- [Publicación 0.9.0, soporte, licencia y requisitos Python](https://pypi.org/project/openapi-spec-validator/0.9.0/).
- [Dependencias de la etiqueta 0.9.0](https://github.com/python-openapi/openapi-spec-validator/blob/0.9.0/pyproject.toml).
- [API explícita para OpenAPI 3.1](https://python-openapi.org/openapi-spec-validator/docs/latest/python.html).
- [pip: administrar otro intérprete, incluido venv sin pip](https://pip.pypa.io/en/stable/topics/python-option/).
- [venv de Python](https://docs.python.org/3/library/venv.html).
- Metadatos por release y licencias de cada dependencia: URLs individuales en el manifiesto propuesto.
