# EUL-U01 Runbook: Fixtures Sinteticos Akubica UAT

## Proposito

Este runbook describe el materializador `akubica:uat-fixtures` para staging-safe UAT de API V1. El objetivo es crear, verificar y limpiar un conjunto sintetico y determinista bajo el namespace `akubica-uat-v1` sin usar identidades reales, sin producir OTPs ni secretos imprimibles y sin adoptar registros ajenos por coincidencia parcial.

## Escenarios

- identidad principal con customer, contacto, direccion y perfil fiscal sinteticos;
- identidad ajena completamente separada para pruebas de ownership y soft-deny;
- identidad descartable no registrada para pruebas de auth/register;
- orden con resultados listos;
- orden con resultados pendientes;
- orden con factura disponible;
- orden con solicitud de factura pendiente;
- orden ajena con resultado y factura sinteticos;
- carrito con estudios, draft de checkout y catalogo `[UAT]`;
- cupon valido, cupon usado/expirado, cupon no aplicable y codigo inexistente reservado sin fila.

## Configuracion

El comando usa `config/akubica_uat.php`. Las identidades se toman de configuracion efectiva y nunca se imprimen.

Valores contractuales no configurables por environment:

- namespace: `akubica-uat-v1`;
- storage disk: `local`;
- storage prefix: `akubica-uat-v1/`;
- version de fixture: `1`.

- `configured`: la identidad de ese rol esta presente y cumple formato.
- `not_configured`: falta al menos correo, telefono o pais de ese rol.

Los placeholders pueden existir en `.env.example`, pero deben permanecer vacios. El canal seguro para compartir identidades con operaciones debe mantenerse fuera de Git.

## Dry Run

`php artisan akubica:uat-fixtures --namespace=akubica-uat-v1`

Comportamiento:

- valida environment, namespace y opciones;
- reporta cada identidad como string exacto `configured` o `not_configured`;
- construye un plan determinista y sanitizado;
- reporta solo accion, namespace, conteos y hashes;
- no requiere identidades completas;
- no ejecuta `requiredIdentities()`;
- no ejecuta preflight de colisiones;
- no adquiere lock;
- no consulta BD;
- no accede a storage;
- no resuelve el materializador ni gateways;
- no abre transaccion de escritura;
- no escribe storage;
- no dispara observers, jobs o integraciones.

## Apply

`php artisan akubica:uat-fixtures --apply --confirm=akubica-uat-v1`

Guardas antes de efectos:

1. environment permitido: solo `testing` o `staging`;
2. namespace exacto;
3. combinacion valida de opciones;
4. confirmacion exacta;
5. configuracion requerida y consistente;
6. lock de ejecucion;
7. plan validado;
8. colisiones verificadas;
9. solo entonces escrituras controladas.

Durante `apply`:

- se neutralizan Queue/Bus, Mail, Notification y HTTP stray requests;
- Scout se deshabilita solo para la ventana del proceso;
- documentos sinteticos se escriben primero bajo `akubica-uat-v1/.tmp/<run-id>/`;
- se validan rutas, tipo basico PDF/XML y hashes;
- despues de colisiones y temporales validos se crea/actualiza un manifiesto `preparing` en una transaccion independiente;
- el manifiesto `preparing` contiene solo run_id hasheado, rutas relativas allowlisted, hashes esperados y hashes de claves naturales;
- se persiste/reconcilia el set dentro de una segunda transaccion;
- se promueven documentos a rutas finales allowlisted antes del commit;
- se verifican existencia y hash de rutas finales;
- el manifiesto se marca `active`;
- el manifiesto guarda exclusivamente IDs tecnicos y hashes sanitizados.

## Verificacion

La verificacion posterior debe revisar:

- existencia del manifiesto de namespace;
- estado `active` o `fixture_expired`;
- conteos sanitizados;
- integridad de hashes de storage;
- presencia de catalogo `[UAT]`, cupones sinteticos y ordenes esperadas.

No se deben compartir rutas privadas completas, OTP, bearer, grant, secure links, datos personales, correos, telefonos ni payloads.

## Reset

`php artisan akubica:uat-fixtures --reset --confirm=akubica-uat-v1`

`reset` elimina solo recursos probados como sinteticos y ligados al manifiesto. Antes de borrar, valida:

- namespace, fixture_version y status allowlisted;
- metadata limitada a IDs tecnicos, hashes y rutas allowlisted;
- usuarios por identidad configurada;
- customers contra RegularAccount y User esperados;
- contactos, direcciones y perfiles fiscales contra su Customer padre;
- catalogo por nombre/gda_id/marca;
- carrito por customer y test esperado;
- ordenes por folio GDA sintetico;
- items de orden por compra padre y `indications` presente;
- facturas e invoice requests por morph padre;
- cupones y asignaciones por codigo/usuario esperado;
- storage por ruta exacta y SHA-256;
- ausencia de referencias externas.

Recursos eliminables tras validacion:

- usuarios, customers, contactos, direcciones y perfiles fiscales;
- ordenes, items, drafts, carritos, cupones y catalogo `[UAT]`;
- facturas, invoice requests y archivos en allowlist exacta;
- PATs, challenges, grants, secure links e idempotencia asociados al namespace.

El segundo reset debe ser no-op.

## Idempotencia

Una segunda corrida `apply` no debe duplicar:

- reconciliacion por manifiesto y claves naturales deterministas;
- abortar si una coincidencia no puede demostrarse como sintetica;
- renovacion de `expires_at` mediante `apply` idempotente;
- `created=0` cuando el conjunto ya existe y solo se reconcilia.

## State Machine, Rollback Y Cleanup

Estados del manifiesto:

- `preparing`: apply en curso o recuperable;
- `active`: set vigente y verificado;
- `resetting`: reservado para limpieza controlada;
- `failed`: fallo normal despues de crear evidencia recuperable.

Si falla el flujo:

- rollback de BD;
- cleanup exclusivo de archivos temporales y finales promovidos por la corrida;
- transicion segura a `failed` si ya existe manifiesto `preparing`;
- nunca eliminar el prefijo completo sin allowlist;
- no ejecutar `truncate`, `migrate:fresh` ni deletes globales.

Un apply posterior ante `preparing` o `failed` solo puede continuar si las claves, relaciones, rutas y hashes son demostrables. Si encuentra un hash distinto, aborta; no adopta archivos ajenos. Si un archivo final allowlisted ya existe con hash esperado puede reutilizarse; si falta, se regenera desde un temporal nuevo.

## Claves Naturales

- User: identidad configurada, normalizada y no impresa.
- RegularAccount: relacion exacta con Customer/User sintetico; sin manifest no se adopta.
- Customer: `medical_attention_identifier` sintetico `akubica-uat-v1-customer-<role>`.
- Contact: Customer esperado + nombre sintetico hasheado.
- Address: Customer esperado + campos controlados hasheados.
- TaxProfile: Customer esperado + razon social/RFC sinteticos hasheados.
- LaboratoryPurchase: `gda_order_id` sintetico determinista.
- PurchaseItem: compra padre + `gda_id` sintetico.
- Invoice: parent polimorfico + ruta/hash sintetico.
- InvoiceRequest: parent polimorfico + hash de clave natural; se registran `invoice_request_pending` y `foreign_order_invoice_request`.
- CartItem: customer + study esperado.
- CheckoutDraft: customer + marca.
- Coupon: codigo sintetico.
- CouponUser: coupon + user esperado.
- Category/Test/Store: nombres, marca, gda_id y prefijos `[UAT]`.
- Storage: ruta relativa allowlisted + SHA-256.

## TTL Y Responsables

- vigencia operativa del fixture: 14 dias;
- retencion logica de evidencia sanitizada: 30 dias;
- responsable tecnico/operativo: FAMEDIC;
- no codificar nombres personales en el fixture.

## Produccion Y Limitaciones

- prohibido en `production`;
- no emite OTP, bearer, grants ni secure links planos;
- no valida runtime externo, SMS, ActiveCampaign, Algolia, pagos o GDA;
- no demuestra que las pruebas pasen hasta que se ejecute la suite autorizada;
- dry-run no prueba colisiones porque contractualmente no toca BD/storage;
- no sustituye la evidencia documental protegida de EUL-D08;
- requiere un canal seguro externo a Git para identidades configuradas.

## Cobertura EUL-U01

Cobertura agregada como especificacion ejecutable no corrida:

- ambos InvoiceRequest en manifest/reset;
- parent alterado y metadata corrupta;
- manifiesto `preparing`/`failed` recuperable;
- recuperacion con hash final valido;
- aborto con hash divergente;
- colisiones sin manifest para RegularAccount, Customer, Invoice, InvoiceRequest, CartItem y PurchaseItem;
- Queue y Bus sin jobs;
- dry-run con strings exactos;
- Throwable inesperado con salida redactada y log estructurado minimo.
