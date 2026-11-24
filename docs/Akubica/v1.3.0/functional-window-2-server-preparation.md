# IT-0061 — preparación local del lado servidor

Base: `4f95da676edeb88b933ac1d630903d3efbf6bf7a`  
Namespace local sintético: `akubica-uat-batch2-a4-v1`

## Interfaces extendidas

El comando existente `akubica:uat-access` conserva su comportamiento anterior
sin opciones adicionales. La opción explícita `--scenario=functional-window-2`
añade sólo al namespace dedicado:

- bearer native para `membership_regular`, con `memberships:read`,
  `cart:read`, `cart:membership:write`, `cart:laboratory:read` y
  `cart:laboratory:write`, limitado al binding `olab`;
- bearer Leo para `membership_none`, sin capacidades de pago y con
  `cart:laboratory:read` pero sin `cart:laboratory:write`;
- binding Leo con marca `swisslab`, distinta de `olab`, para que B21x alcance
  la autorización de marca pero falle por la capacidad literal ausente;
- estado, delegaciones, bindings, tokens cifrados y hashes separados para
  reset/idempotencia. No se crea un consumer adicional ni se altera el acceso
  base primary/foreign.

Dry-run, apply y reset:

```text
php artisan akubica:uat-access --dry-run --namespace=akubica-uat-batch2-a4-v1 --scenario=functional-window-2 --no-ansi
php artisan akubica:uat-access --apply --namespace=akubica-uat-batch2-a4-v1 --scenario=functional-window-2 --confirm=akubica-uat-batch2-a4-v1 --no-ansi
php artisan akubica:uat-access --reset --namespace=akubica-uat-batch2-a4-v1 --scenario=functional-window-2 --confirm=akubica-uat-batch2-a4-v1 --no-ansi
```

El fixture de precio B03/B06 usa una interfaz namespace-scoped en QA; el
manifest dedicado debe estar activo antes de cualquier escritura:

```text
php artisan akubica:rc2-price-fixtures --dry-run --namespace=akubica-uat-batch2-a4-v1 --no-ansi
php artisan akubica:rc2-price-fixtures --apply --namespace=akubica-uat-batch2-a4-v1 --confirm=rc2-price-fixtures --no-ansi
php artisan akubica:rc2-price-fixtures --cleanup --namespace=akubica-uat-batch2-a4-v1 --confirm=rc2-price-fixtures --no-ansi
```

El modo con namespace deriva prefijo, categoría y cleanup propios; rechaza
otros namespaces y no adopta registros legacy. El modo sin namespace sólo se
usa en el arnés local histórico.

La limpieza verifica consumers, channels, credentials, delegations, bindings y
tokens por los IDs del estado. Si queda un recurso activo o un token presente,
devuelve `UAT_ACCESS_CLEANUP_FAILED` y conserva el estado para diagnóstico.

## Fixtures R01/R04

El materializador principal ya crea pedidos deterministas propios y ajenos con
claves derivadas del namespace (`RESULT-READY`, `FOREIGN-ORDER`, etc.). R04 se
extiende mediante la interfaz existente:

```text
php artisan akubica:uat-fixtures-r04r05 --dry-run --namespace=akubica-uat-batch2-a4-v1 --no-ansi
php artisan akubica:uat-fixtures-r04r05 --apply --namespace=akubica-uat-batch2-a4-v1 --confirm=akubica-uat-batch2-a4-v1 --no-ansi
php artisan akubica:uat-fixtures-r04r05 --reset --namespace=akubica-uat-batch2-a4-v1 --confirm=akubica-uat-batch2-a4-v1 --no-ansi
```

El namespace legacy mantiene el marcador y nombre históricos por defecto. El
namespace dedicado usa un nombre determinista derivado del namespace y nunca
adopta registros legacy.

## Matriz

| Escenario | Estado | Preparación | Limpieza |
|---|---|---|---|
| B09 | READY local | native + membership_regular + customer/delegación/binding + capacidades literales | reset de acceso por IDs |
| B14 | READY local | B09 activo, mismo ownership y `olab` | reset de acceso y carrito dedicado |
| B21x/R07a2 | READY local | Leo membership_none, `cart:laboratory:read` sin write, marca distinta | reset de bindings/tokens |
| B25 | READY local | Leo membership_none sin capacidades de pago, guard negativa exacta | reset y readback de ausencia de efectos |
| R01/R01b | READY local | pedidos dedicados propios/ajenos materializados por namespace | reset limitado al manifest |
| R04 | READY local | familiares dedicados mediante `uat-fixtures-r04r05` con namespace | reset por marcador derivado |

`READY local` no significa listo para QA: requiere desplegar el cambio de
servidor y ejecutar dry-run/readback en staging. No se modifican A4, OpenAPI,
rev7 ni `akubica-uat-v1`.

