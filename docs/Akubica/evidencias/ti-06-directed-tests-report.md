# TI-06 - Pruebas dirigidas P0

- ID: TI-06
- Ambiente: local/testing en Docker Compose, servicio `app`
- SHA: ca74fa4d2d99c2ef70d403a6ff0da79794978cbc
- Fecha: 2026-08-13 10:55:21 UTC-06:00
- PHP: 8.3.31
- Laravel: 11.46.1

## Comandos ejecutados

```bash
docker compose exec -T app php artisan test tests/Feature/Api/V1/AkubicaResultsSecureLinksP0b2Test.php tests/Feature/Api/V1/AkubicaStepUpResultsP0b1Test.php tests/Feature/Api/V1/AkubicaUserEditableDataTest.php tests/Feature/Api/V1/AkubicaTaxProfilesInvoiceRequestTest.php tests/Feature/Api/V1/AkubicaRetryReadBackP0Test.php
docker compose exec -T app php artisan test tests/Feature/Api/V1/AkubicaResultsSecureLinksP0b2Test.php tests/Feature/Api/V1/AkubicaInvoicesStepUpSecureLinksP0b3Test.php tests/Feature/Api/V1/AkubicaBearerStepUpEnforcementP0b4Test.php tests/Feature/Api/V1/AkubicaStepUpResultsP0b1Test.php tests/Feature/Api/V1/AkubicaIdempotencyP1Test.php tests/Feature/Api/V1/AkubicaUserEditableDataTest.php tests/Feature/Api/V1/AkubicaTaxProfilesInvoiceRequestTest.php tests/Feature/Api/V1/AkubicaCartCatalogTest.php tests/Feature/Api/V1/AkubicaCatalogDiscoveryTest.php tests/Feature/Api/V1/AkubicaErrorsCorrelationP1a6Test.php tests/Feature/Api/V1/AkubicaLoginOtpP0a4Test.php tests/Feature/Api/V1/AkubicaRegisterP0a54Test.php tests/Feature/Api/V1/AkubicaRetryReadBackP0Test.php
```

## Resultado

- Suite dirigida modificada: 107 passed, 0 failed, 0 skipped, 421 assertions, 32.97s.
- Suite dirigida TI-06 completa: 280 passed, 0 failed, 0 skipped, 1358 assertions, 78.71s.

## Matriz P0

| P0 | Caso | Test/evidencia | Esperado | Observado | PASS/FAIL | Observacion |
| --- | --- | --- | --- | --- | --- | --- |
| P0-01 | Autenticacion y errores base | AkubicaErrorsCorrelationP1a6Test, AkubicaLoginOtpP0a4Test | Envelopes y correlacion estables | PASS en suite dirigida | PASS | Sin secretos en logs de prueba |
| P0-02 | Registro OTP | AkubicaRegisterP0a54Test | Flujos reales/decoy y resend | PASS | PASS | Fake delivery |
| P0-03 | Login OTP | AkubicaLoginOtpP0a4Test | Request, verify, resend y cooldown | PASS | PASS | Fake delivery |
| P0-04 | Step-up results | AkubicaStepUpResultsP0b1Test | Grant valido y rechazos | PASS | PASS | Incluye 429 dirigido |
| P0-05 | Step-up invoices | AkubicaInvoicesStepUpSecureLinksP0b3Test | Grant valido y rechazos | PASS | PASS | Incluye cooldown existente |
| P0-06 | Bearer step-up enforcement | AkubicaBearerStepUpEnforcementP0b4Test | Requiere grant cuando flags activos | PASS | PASS | Split/master flags cubiertos |
| P0-07 | Secure links results | AkubicaResultsSecureLinksP0b2Test | Emision y descarga segura | PASS | PASS | Incluye 60/3 dirigido |
| P0-08 | Secure links invoices | AkubicaInvoicesStepUpSecureLinksP0b3Test | Emision y descarga segura | PASS | PASS | Cross resource cubierto |
| P0-09 | Secure link expirado/revocado/consumido | AkubicaResultsSecureLinksP0b2Test, AkubicaInvoicesStepUpSecureLinksP0b3Test | 410 especifico | PASS | PASS | `SECURE_LINK_CONSUMED` confirmado |
| P0-10 | HEAD secure-download | AkubicaResultsSecureLinksP0b2Test | Documentar comportamiento real | HEAD retorna 200, body vacio, consume 1 apertura | PASS | Laravel enruta HEAD sobre GET |
| P0-11 | Range secure-download | AkubicaResultsSecureLinksP0b2Test | Documentar comportamiento real | `Range` ignorado: 200, sin Content-Range/Accept-Ranges, body completo, consume | PASS | No se implementa 206 |
| P0-12 | Preview secure-download | AkubicaResultsSecureLinksP0b2Test | PDF inline | Content-Type PDF y Content-Disposition inline | PASS | No existe endpoint/query preview separado |
| P0-13 | Abort/consumo | AkubicaResultsSecureLinksP0b2Test | Consumo antes de entregar response | open_count incrementa al producir response | PASS | TCP abort no observable en Feature Test |
| P0-14 | Concurrencia max_opens | AkubicaResultsSecureLinksP0b2Test | No mas de max_opens exitosos | max_opens=3 produce 3 exitos y rechazos posteriores | PASS | Usa servicio con transaccion/lock |
| P0-15 | Throttle 429 secure links | AkubicaResultsSecureLinksP0b2Test | 429 en rutas con throttle real | Emision y descarga retornan 429 | PASS | Handler API no conserva Retry-After en estos 429 |
| P0-16 | Catalogo discovery | AkubicaCatalogDiscoveryTest | Catalogo publico estable | PASS | PASS | Referencia evidencia TI-03 |
| P0-17 | Catalogo detalle/carrito | AkubicaCartCatalogTest, AkubicaCatalogDiscoveryTest | Detalle lab tests y carrito | PASS | PASS | Medicamentos sigue 503 |
| P0-18 | Idempotency | AkubicaIdempotencyP1Test | Replay, conflict, in-progress, uncertain | PASS | PASS | Cobertura existente reutilizada |
| P0-19 | Retry/read-back | AkubicaRetryReadBackP0Test, matriz TI-04 | Reconciliar mutacion con lectura | Factura pending y address list confirman read-back | PASS | Sin duplicar idempotency exhaustivo |
| P0-20 | Request bodies y 403 | AkubicaUserEditableDataTest, AkubicaTaxProfilesInvoiceRequestTest | 422 reales y 403 api.customer | PASS | PASS | Address/contact/tax profile cubiertos |

## Semantica observada

- HEAD `/api/v1/secure-downloads/{token}`: Laravel enruta HEAD sobre la ruta GET; status 200, `Content-Type: application/pdf`, body vacio en Feature Test, `open_count` pasa de 0 a 1 y `consumed_at` permanece null con `max_opens=3`.
- Range `bytes=0-99`: el runtime actual ignora Range; responde 200 con PDF completo, no envia `Content-Range` ni `Accept-Ranges`, y consume una apertura.
- Preview: en runtime actual corresponde a respuesta PDF inline (`Content-Disposition: inline`); no existe endpoint ni query parameter preview separado.
- Abort: un aborto de transporte posterior al consumo no es observable de forma realista en Feature Test; el consumo ocurre antes de entregar la respuesta, evidenciado por `open_count` incrementado al producir la response.
- Secure links 60/3: una liga con `max_opens=3` permite exactamente tres GET 200; el cuarto GET retorna 410 `SECURE_LINK_CONSUMED`; `consumed_at` se establece al tercer consumo.
- Concurrencia: usando el mismo mecanismo de consumo directo del test P0b2, `max_opens=3` no supera tres consumos exitosos; solicitudes posteriores reciben `SECURE_LINK_CONSUMED`.
- 429: emision de secure link y descarga secure-download usan middleware `throttle:60,1`; results step-up usa `throttle:akubica-otp`. Los 429 se observaron sin efectos secundarios de controlador.

## Notas

- Catalogo: P0-16/P0-17 se cubren por `AkubicaCatalogDiscoveryTest`, `AkubicaCartCatalogTest` y evidencia TI-03.
- Retry/read-back: se agrega cobertura pequena para solicitud de factura con `ordersInvoiceRequestStatus` y creacion de address sin `Idempotency-Key` con read-back por listado; `uncertain` se mantiene en `AkubicaIdempotencyP1Test`.
- No se ejecuto regresion completa 755+; pertenece a TI-08.
