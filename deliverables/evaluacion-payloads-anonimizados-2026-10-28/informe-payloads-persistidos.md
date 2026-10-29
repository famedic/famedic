# Corrección de entregables de payloads persistidos

Fecha: 2026-10-28. Solo respaldo MySQL local; sin llamadas externas, PDFs ni cambios funcionales.

Se corrigió la anonimización de identificadores anidados y se separaron metadatos agregados del payload. Las muestras OLAB y Azteca originales están etiquetadas como gabinete; se buscó una muestra clínica adicional en todas las notificaciones disponibles sin limitar al periodo anterior.

`GDA_menssage` y otros campos se presentan como parte del payload persistido. No se garantiza que sean byte a byte equivalentes al HTTP recibido. El código revisado muestra que FAMEDIC guarda `$data` del webhook en `laboratory_purchases.gda_response`, por lo que ese campo no se considera evidencia de una respuesta original de consulta GDA.

El manifiesto documenta por ruta si PDF/Base64 o credenciales estaban ausentes, vacíos o con contenido y qué acción se aplicó. No se incluyeron valores ni se descargaron o extrajeron PDFs.

## Verificación de `header.registro`

Se verificó localmente el tipo y el patrón sin imprimir el valor original: string / fecha-hora texto. Se anonimiza como fecha cuando corresponde; su significado clínico queda pendiente del contrato.
