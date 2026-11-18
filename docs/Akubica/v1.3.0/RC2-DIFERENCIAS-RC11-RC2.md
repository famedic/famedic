# Diferencias RC1.1 RC2

## Fuente y alcance

RC2 continúa desde `prep/akubica-technical-integration-rc11` en `9c060c81e6eda7871e2c902563e3c9e0daac74dc`. La línea base OpenAPI 1.2.3 no se modifica. El paquete de Leo usado como referencia contiene 74 operaciones principales, cuatro operaciones R30 first party y 90 solicitudes Postman. Nota de Batch 1 (2026-11-18): esas cifras son las del paquete de referencia histórico; el conteo directo sobre los artefactos vigentes es 76 operaciones principales, 4 R30 y 92 solicitudes.

## Ajustes cerrados localmente

- `GET /cart/totals` documenta `applied_benefits`.
- La continuidad opaca conserva `backend_transport_approved=false`, `adapter_activated=false` y `assistant_can_pay=false`.
- Leo no obtiene pagos, payment links, activación de membresías ni beneficios legacy.
- Los fixtures de prueba registran consumidores sintéticos solo en SQLite segura o en la base MySQL exclusiva `_testing`.
- La comprobación offline espera el registro de `BoundConsumerIdentityResolver` con política fail closed.
- El fixture de laboratorio usa `gda_id` sintético único para evitar colisiones con datos de prueba existentes.

## Pendientes y exclusiones

[Histórico; criterio vigente en `RC2-B02-BLOQUEO-FUENTE-PAQUETES.md` (Batch 1 · T6)]. B02 permanece bloqueado. No existe una fuente autorizada y verificable para catálogo de paquetes, precios, vigencia y reglas de comparación. No se inventaron schemas, precios ni solicitudes positivas.

B24 queda preparado localmente, pero no certificado operacionalmente. Falta autorizar el transporte y el mecanismo de continuidad hacia el checkout FAMEDIC en QA.

R30 permanece separado en `r30-first-party-v1.3.0.json` y no forma parte de las capacidades de Leo.

## Evidencia

La evidencia local es la de `RC2-INFORME-PRUEBAS-LOCALES.md`. La colección (90 solicitudes en aquella referencia; 92 en el artefacto vigente) es estructural y guardada. No se realizaron solicitudes HTTP contra staging ni se generaron resultados Newman ficticios.
