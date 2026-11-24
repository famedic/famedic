# IT-0061 — preparación native `never-set` para B18

El materializador existente ya crea `membership_none` con suscripción
ausente, pero el aprovisionador de `functional-window-2` sólo exponía ese
usuario mediante el binding Leo. Por ello B18 no podía alcanzar la regla de
negocio con un actor native dedicado sin reutilizar `membership_regular`.

## Corrección local

- Se añade el binding `native_membership_none` únicamente al escenario
  `functional-window-2`.
- Usa exactamente las capacidades native existentes; no añade permisos.
- La custodia acepta el alias `native_membership_none` por el mismo canal
  privado y nunca imprime el bearer.
- El estado, delegación, binding, token y cleanup siguen siendo parte del
  mismo namespace y del mismo reset idempotente.

## Precondiciones de retest QA

1. Dry-run debe mostrar el binding native `membership_none` y las mismas cinco
   capacidades native existentes.
2. Apply debe crear un token para ese alias; la BD debe demostrar que su
   customer no tiene suscripción activa antes de emitirlo.
3. Dos POST HTTP independientes a `/api/v1/cart/items`, con claves de
   idempotencia distintas por solicitud, deben producir `201 + 409`, una sola
   fila y ningún 500.
4. El cleanup elimina únicamente la fila devuelta por el 201, y el reset
   revoca todos los recursos registrados. Deben quedar cero PAT del namespace
   y ningún `access-state.json`.

## Validación local

PHP lint y `git diff --check` pasan. La prueba Feature focalizada no pudo
inicializarse en este worktree porque el junction temporal de `vendor/`
resolvió el bootstrap desde el worktree principal y dejó el contenedor sin el
binding `config` (`Target class [config] does not exist`, 0 assertions). No se
atribuye ese error al cambio runtime; queda pendiente ejecutar la suite con el
arnés MySQL del worktree correctamente instalado.

No se hizo QA, no se emitieron credenciales remotas, no se hizo merge ni
deploy.
