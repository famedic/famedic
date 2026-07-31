# P0-C1 — Expiración controlada de tokens Sanctum (180 minutos)

## Objetivo

Tokens **Akubica API V1** expiran en 180 minutos cuando `OTP_P0A_SANCTUM_3H_ENABLED=true`, sin cambiar `sanctum.expiration` global ni otros consumidores.

## Alcance: Akubica-only

Evidencia:

- Emisión centralizada en `IssueAkubicaTokenAction` (login legacy, login OTP, registro OTP).
- Sanctum 4.2 permite `createToken($name, $abilities, $expiresAt)`.
- Con flag ON se persiste `personal_access_tokens.expires_at`.
- Con flag OFF, `expires_at` queda `null` y el Guard usa solo `sanctum.expiration` (1440) desde `created_at`.
- Tokens con otros `name` (p. ej. web/admin) no pasan por esta action → sin cambio.

## Flags / TTL

| Env | Default | Uso |
|-----|---------|-----|
| `OTP_P0A_SANCTUM_3H_ENABLED` | false | Activa persistencia de `expires_at` Akubica |
| `OTP_P0A_SANCTUM_TOKEN_TTL_MINUTES` | 180 | Preferido (P0-C1) |
| `OTP_P0A_SANCTUM_TARGET_EXPIRATION_MINUTES` | 180 | Fallback histórico |
| `SANCTUM_TOKEN_EXPIRATION` | 1440 | Global Guard; **no se modifica** en este bloque |

## Tokens existentes

- No se reescriben PATs previos.
- Nuevos tokens Akubica usan 180 min con flag ON.
- Anteriores conservan su regla hasta revoke/logout.

## Contrato de respuesta (sin cambio de forma)

```json
{
  "token": "<REDACTED>",
  "token_type": "Bearer",
  "expires_in": 10800,
  "expires_at": "YYYY-MM-DDTHH:mm:ssZ",
  "user": { "id": 0, "email": "usuario.ejemplo@example.com", "name": "Ejemplo" }
}
```

Ya existía `expires_in` / `expires_at`; con flag ON reflejan el TTL real del PAT.

## Logout vs expiración

| Evento | Efecto |
|--------|--------|
| Logout (`DELETE` token) | Elimina PAT de inmediato |
| Expiración | Guard rechaza (401) por `expires_at` / ventana global |
| Grant ligado a PAT | No usable con otro Bearer; requiere auth válida |
| Secure link ya emitido | Política corta propia (P0-B); no cambia aquí |
| Refresh token | **No implementado** — LeoV debe rehacer login OTP |

## Rollout staging

1. Deploy flag OFF.
2. Confirmar login legacy (TTL anunciado ~1440).
3. Activar `OTP_P0A_SANCTUM_3H_ENABLED=true` (+ TTL 180 si hace falta).
4. `optimize:clear` / `config:cache` / `queue:restart`.
5. Login nuevo → `expires_at ≈ now+180m`, PAT con `expires_at` no null.
6. Endpoint privado OK.
7. Expiración vía tests (`Carbon::setTestNow`), no esperar 3h.
8. Re-login OTP.
9. Rollback: flag OFF (nuevos tokens vuelven a legacy; no reescribe antiguos).

## Riesgos

- Clientes que asuman 24h de sesión deberán reautenticar a las 3h.
- Sin refresh token.
- Docs bajo `/docs/*` pueden estar gitignored.
