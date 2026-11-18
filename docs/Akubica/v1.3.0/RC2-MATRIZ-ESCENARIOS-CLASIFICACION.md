# RC2: clasificación de escenarios para el lanzamiento (Batch 1 · T8)

Fuente: plan de cierre por batches v1.0 (tabla 8, S01–S16). Cada uno de los 46 escenarios históricos (A01–A12, B01–B26, R01–R08) se clasifica como **OBLIGATORIO_LANZAMIENTO**, **DIFERIDO** o **NO_APLICABLE**, sin borrar su historia. La versión tabular es `RC2-MATRIZ-ESCENARIOS-CLASIFICACION.csv`.

- `estado_historico_qa_3fb5feb8`: estado vigente antes de Batch 2 (24 PASS, 2 FAIL, 19 BLOCKED, 1 NOT_RUN) sobre QA `3fb5feb8` / release 83302559. Es un **antecedente**, no una certificación.
- `historico_inmutable`: el estado original del paquete, que no se modifica.
- La certificación final se hace en Batch 2, sobre el build final, por escenario S01–S16. Hoy hay 0 certificados (NO_INICIADA). R08 (UAT conversacional) corresponde a Batch 3.

Resultado: 45 OBLIGATORIO_LANZAMIENTO, 1 NO_APLICABLE (B26) y 1 DIFERIDO adicional (X01, aplicación directa de cupones o créditos por Leo, C6/L16). Las 16 filas S01–S16 de la tabla 8 tienen al menos un escenario asignado.

| ID | Funcionalidad | Histórico QA | Clasificación | Plan | Batch | Nota |
|---|---|---|---|---|---|---|
| A01 | Reconocimiento ATIX | BLOCKED | OBLIGATORIO_LANZAMIENTO | S01 | B2 |  |
| A02 | Reconocimiento ATIX | BLOCKED | OBLIGATORIO_LANZAMIENTO | S02 | B2 |  |
| A03 | Seguridad HMAC | BLOCKED | OBLIGATORIO_LANZAMIENTO | S01 | B2 | Control HMAC de S01/S02 |
| A04 | Seguridad HMAC | BLOCKED | OBLIGATORIO_LANZAMIENTO | S01 | B2 | Control HMAC de S01/S02 |
| A05 | Anti replay | BLOCKED | OBLIGATORIO_LANZAMIENTO | S01 | B2 | Anti-replay (timestamp/nonce) |
| A06 | Idempotencia ATIX | BLOCKED | OBLIGATORIO_LANZAMIENTO | S01 | B2 | Idempotencia ATIX |
| A07 | Validación ATIX | BLOCKED | OBLIGATORIO_LANZAMIENTO | S02 | B2 | Validación ATIX sin enumeración |
| A08 | Solicitud OTP | PASS | OBLIGATORIO_LANZAMIENTO | S03 | B2 | SMS sólo con autorización |
| A09 | Solicitud OTP | PASS | OBLIGATORIO_LANZAMIENTO | S03 | B2 |  |
| A10 | Verificación OTP | PASS | OBLIGATORIO_LANZAMIENTO | S03 | B2 |  |
| A11 | Verificación OTP | PASS | OBLIGATORIO_LANZAMIENTO | S03 | B2 |  |
| A12 | Ciclo de vida Bearer | PASS | OBLIGATORIO_LANZAMIENTO | S03 | B2 | Ciclo de vida Bearer |
| B01 | Precio de estudio | PASS | OBLIGATORIO_LANZAMIENTO | S04 | B2 |  |
| B02 | Precio de paquete | PASS | OBLIGATORIO_LANZAMIENTO | S05 | B2 | Criterio exacto B1-T6 |
| B03 | Precio sin referencia pública | PASS | OBLIGATORIO_LANZAMIENTO | S04 | B2 |  |
| B04 | Sin ahorro | PASS | OBLIGATORIO_LANZAMIENTO | S04 | B2 |  |
| B05 | Dato de precio inconsistente | PASS | OBLIGATORIO_LANZAMIENTO | S04 | B2 |  |
| B06 | Revalidación de precio | BLOCKED | OBLIGATORIO_LANZAMIENTO | S04 | B2 | Revalidación de precio antes de agregar |
| B07 | Planes de membresía | PASS | OBLIGATORIO_LANZAMIENTO | S06 | B2 |  |
| B08 | Estado de membresía | PASS | OBLIGATORIO_LANZAMIENTO | S06 | B2 |  |
| B09 | Estado de membresía | BLOCKED | OBLIGATORIO_LANZAMIENTO | S06 | B2 |  |
| B10 | Estado de membresía | PASS | OBLIGATORIO_LANZAMIENTO | S06 | B2 |  |
| B11 | Estado de membresía | BLOCKED | OBLIGATORIO_LANZAMIENTO | S06 | B2 |  |
| B12 | Agregar membresía | PASS | OBLIGATORIO_LANZAMIENTO | S06 | B2 |  |
| B13 | Duplicado de membresía | PASS | OBLIGATORIO_LANZAMIENTO | S06 | B2 | Duplicado rechazado |
| B14 | Membresía activa | BLOCKED | OBLIGATORIO_LANZAMIENTO | S06 | B2 | Activa rechazada |
| B15 | Membresía patrocinada | PASS | OBLIGATORIO_LANZAMIENTO | S06 | B2 |  |
| B16 | Retirar membresía | PASS | OBLIGATORIO_LANZAMIENTO | S06 | B2 | Retiro de membresía |
| B17 | Idempotencia membresía | PASS | OBLIGATORIO_LANZAMIENTO | S16 | B2 | Replay idempotente |
| B18 | Concurrencia membresía | PASS | OBLIGATORIO_LANZAMIENTO | S16 | B2 | Concurrencia; fuera de Newman |
| B19 | Aislamiento médico laboratorio | BLOCKED | OBLIGATORIO_LANZAMIENTO | S07 | B2 | cart:laboratory:* (L06) |
| B20 | Aislamiento laboratorio médico | BLOCKED | OBLIGATORIO_LANZAMIENTO | S08 | B2 | La otra línea conserva ítems y totales |
| B21 | Retiro aislado | BLOCKED | OBLIGATORIO_LANZAMIENTO | S08 | B2 |  |
| B22 | Farmacia suspendida | PASS | OBLIGATORIO_LANZAMIENTO | S15 | B2 |  |
| B23 | Preparación de checkout | FAIL | OBLIGATORIO_LANZAMIENTO | S12 | B2 | Semántica T4 |
| B24 | Continuidad a FAMEDIC | FAIL | OBLIGATORIO_LANZAMIENTO | S12 | B2 | Sólo staging; nunca producción |
| B25 | Pago prohibido para Leo | PASS | OBLIGATORIO_LANZAMIENTO | S14 | B2 | Sólo prueba negativa (B1-T5) |
| B26 | Activación posterior al pago | PASS | NO_APLICABLE | — | — | Activación posterior al pago es first-party (FAMEDIC); Leo no paga ni activa. Se conserva como regresión FAMEDIC, sin certificación de la API de Leo |
| R01 | Pedidos | BLOCKED | OBLIGATORIO_LANZAMIENTO | S09 | B2 | Contrato B1-T7 |
| R02 | Resultados | BLOCKED | OBLIGATORIO_LANZAMIENTO | S10 | B2 | Step-up y liga segura; descarga directa 403 |
| R03 | Facturas | BLOCKED | OBLIGATORIO_LANZAMIENTO | S11 | B2 | Liga PDF; XML a Atención a usuarios |
| R04 | Familiares | BLOCKED | OBLIGATORIO_LANZAMIENTO | S09 | B2 | Contrato B1-T7 |
| R05 | Citas y concierge | PASS | OBLIGATORIO_LANZAMIENTO | S13 | B2 | Concierge sólo en el checkout first-party |
| R06 | Observabilidad | PASS | OBLIGATORIO_LANZAMIENTO | S16 | B2 | Correlation ID y trazas |
| R07 | Errores contractuales | BLOCKED | OBLIGATORIO_LANZAMIENTO | S16 | B2 | Errores tipados (transversal) |
| R08 | UAT conversacional | NOT_RUN | OBLIGATORIO_LANZAMIENTO | UAT | B3 | UAT conversacional en Batch 3 |
| X01 | Cupones y créditos | — | DIFERIDO | — | Posterior a RC2 | C6/L16: el usuario los ve y aplica en el checkout FAMEDIC; Leo sólo informa |
