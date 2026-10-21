# P0 — Precios comparativos de catálogo

## Fuente canónica

Los endpoints públicos de catálogo leen exclusivamente `laboratory_tests.public_price_cents` y `laboratory_tests.famedic_price_cents`, administrados por FAMEDIC mediante la importación de catálogo y el panel administrativo. Akúbica no acepta precios en la petición y no calcula precios para carrito o checkout.

`price_cents` permanece como alias del precio FAMEDIC para compatibilidad con v1.2.3.

## Representación

La representación centralizada devuelve `pricing` en listado y detalle. El ahorro solo se calcula cuando el precio público es positivo y mayor que el precio FAMEDIC. Si el precio público es nulo o cero, el estado es `public_price_unavailable`; si es igual, `no_savings`; si es menor, `data_inconsistent` sin ahorro positivo.

## Brechas de fuente

El modelo actual no tiene una fuente confiable y separada para impuestos, inicio de vigencia o fin de vigencia de cada precio. Por eso `tax_included`, `effective_at` y `valid_until` se devuelven como `null` hasta que FAMEDIC exponga esos metadatos; no se derivan de `updated_at` ni se inventan valores.

Si la fuente FAMEDIC de precios no está disponible, ambos endpoints responden `503 PRICING_SOURCE_UNAVAILABLE` con `retryable: true` y conservan correlation ID. La disponibilidad operativa se controla con `API_V1_PRICING_SOURCE_AVAILABLE`, cuyo valor predeterminado es `true`.
