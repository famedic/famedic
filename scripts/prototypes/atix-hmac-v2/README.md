# Prototipo local ATIX HMAC V2 — Fase 2B-1E

Experimento **no aprobado como contrato ni habilitado en runtime**. No hay Composer/autoload de app, provider, ruta HTTP, secret operativo, emisor PAT o migración nuevos. Requiere solo PHP/PDO SQLite, Python estándar y dependencias Laravel ya instaladas para las regresiones. PHP probado localmente 8.5.4; no se afirma validación en otras versiones/infraestructura.

## Componentes y separación

- Prototype.php: canonicalización, verificador HMAC-SHA256, nonce SQLite efímero y predicado de binding sintético.
- cli.php: arnés stdin/stdout exclusivamente local; no servidor. Registry, reloj y contexto son inputs del experimento, jamás autoridad de una futura petición pública.
- generate_vectors.py y vectors.json: firmador Python independiente y ocho vectores congelados, reproducibles; no importan PHP ni generan expected usando el verificador. Secretos y cadenas PAT son **públicos, sintéticos e inutilizables como credenciales reales**.
- test_v2.py: pruebas cruzadas Python→PHP, alteraciones, límites temporales, fallos SQLite reales, concurrencia y separación de binding/política.
- H04RealV1Test.php: usa AtixHmacService real sin cambiarlo; reproduce defecto H04 y evalúa retención suficiente mediante config exclusiva de pruebas. No llama controlador ni ejecuta negocio.
- run_laravel_local.py: regresiones existentes phone-lookups, H04 y pruebas puras de política/fingerprint; ambiente vacío, DB solo SQLite :memory:, caches/storage temporales, bloqueo de proveedores y sockets/cURL/mail externos.

El comprobador de binding es un predicado sobre fixtures, no persistencia aprobada ni autenticación Sanctum del paciente. Al evaluar política se instancian modelos sin guardar y ConsumerAuthorizationContext solo para prueba pura. No hay adaptación native operativa: el registry del experimento usa kind leo e integration. El guard de pagos de la app no se reemplaza.

## Ejecución reproducible

Desde `/home/developer/projects/famedic`, en WSL con herramientas ya instaladas:

```sh
TASK_REPORT_DIR=$(mktemp -d /tmp/famedic-v2-evidence-XXXXXX)
python3 -B scripts/prototypes/atix-hmac-v2/generate_vectors.py --check
python3 -B scripts/prototypes/atix-hmac-v2/test_v2.py --report "$TASK_REPORT_DIR/prototype.json"
python3 -B scripts/prototypes/atix-hmac-v2/run_laravel_local.py --report "$TASK_REPORT_DIR/laravel.json"
```

No requiere red, nuevas instalaciones, .env real ni servicio MySQL. Los runners rechazan sobrescribir reportes. El directorio de evidencias elegido conserva JSON; las DB, caches y storage efímeros creados por cada runner se destruyen al salir. No ejecutar el prototipo como servicio de producción ni copiar sus claves públicas de prueba a configuración real.

En Laravel se copian migraciones existentes al temporal, sin modificarlas; se aplican solo a memoria. Se excluye de esa copia 2026_10_21_142000_relax_atix_operation_unique_indexes.php, que consulta information_schema MySQL. Esto limita la prueba de esquema a SQLite; no acredita índices/locking de MySQL ni migraciones desplegadas. La suite estándar usa su RefreshDatabase; las dos suites isolated y H04 reciben provisión efímera previa. No provisionar dos veces la estándar: la primera ejecución evidenció conflictos de permisos Spatie por ese error del arnés y se conserva.

## Borrador de canonicalización evaluado

Doce campos UTF-8 separados por LF, sin LF final:

```
ATIX-API-V2
deployment_audience
key_id
UPPER(method)
raw_external_path
sha256(raw_query_bytes)
timestamp_decimal
nonce
registered_channel
sha256(exact_PAT_bytes) o - si no hay PAT
sha256(exact_Idempotency_Key_bytes) o - si está ausente
sha256(raw_body_bytes)
```

Firma HMAC-SHA256 hex minúscula. Perfil delegado exigido por el verificador y el registro, sin fallback V1. Audience/canal se comparan con el destino/registro sintético. Cada propósito, consumer y audiencia tiene secret distinto; configuración con secret repetido se rechaza. KeyId se firma; una rotación futura debe conservar integration_ref solo tras aprobación.

Se cubren Unicode, bytes binarios de cuerpo, GET vacío/query exacta, DELETE con path escapado y OTP antes de PAT. Cambiar PAT, método, path, query, body o key de idempotencia tras firmar invalida proof. El nonce es distinto en cada retry aunque payload/key semánticos sean iguales; no se implementa idempotencia de negocio en el prototipo.

## Retención y almacenamiento anti-replay

Con reloj controlado `now`, tolerancia τ y margen m=5 s experimental, aceptar solo |now-ts|≤τ y retener nonce hasta `ts+τ+m`. Reclamo atómico por hash(profile,audience,keyId)+hash(nonce), transacción SQLite BEGIN IMMEDIATE e índice PK único. Borrar un registro solo si expires_at<now. Dos firmas con nonce repetido se deniegan durante toda la ventana; después de ella la firma anterior se rechaza por timestamp antes de reclamar.

Se prueban 16 procesos PHP contra la misma DB temporal: un ganador y 15 duplicados. Se prueban write failure mediante SQLite query_only, archivo corrupto y lock real con timeout; fallan cerrados, sin reclamar el nonce. Symlink del archivo de almacenamiento rechazado. Cada DB solo puede estar bajo el directorio temporal privado del arnés. No es demostración de MySQL distribuido ni de una frontera proxy real.

## Diferencias deliberadas y límites

- Es un perfil experimental, no negociación con Akúbica. El path/query efectivos son argumentos de prueba; falta un adapter HTTP que los extraiga de una frontera confiable. No se validan proxy, HTTPS, eventos Meta o WhatsApp reales.
- Gramáticas estrictas del experimento: path ASCII escapado, sin //, dot segments, slash/backslash/doble escape ambiguos; query sin duplicados de nombres decodificados, bracket/dot keys o _method; límites path/query/body. Se firma query exacta, por lo que orden, + y %20 difieren. No convertir estas restricciones en contrato sin evaluar clientes.
- PAT no vacío hasta 256 caracteres con alfabeto experimental; ninguna cadena se consulta contra Sanctum. Idempotency-Key ausente usa marcador; vacío se rechaza. El caller local decide requirePat; una futura política de servidor debe fijarlo por operación, no el cliente.
- V1 acepta case de firma normalizado; V2 experimental exige hex minúscula. Errores V2_* son del prototipo, separados de ATIX_* V1, no códigos OpenAPI aprobados.
- Proof valida integración, no paciente ni grants. No hay scopes por operación, OTP real, binding persistido, revocación coordinada, receipt replay ni autorización API V2 implementados. No se devuelve permiso de pago ni se llama acción/proveedor. Pruebas puras de la política existente no son P01–P12 integrales.
- Margen 5 s no es SLA aprobado. Reloj compartido/rollback de tiempo, bloqueo de almacenamiento y revocación/rotación entre workers requieren diseño y pruebas adicionales; el reloj es inyectado/controlado aquí. Antes de runtime hay que revalidar tiempo/estado tras esperas y definir cleanup, invalidación y límites.
- H04 V1 sigue presente en el código operativo intacto. Los ensayos con TTL suficiente son solo configuración sintética y requieren una revisión separada para cambiar V1.

Los informes de cierre y pendientes están en docs/Akubica/v1.3.0-rc1.1/FASE-2B1E-CIERRE.md. No iniciar binding real, corte, beneficios ni despliegue desde este experimento.
