# UX-CHECKOUT-AUDIT-2B — Auditoría profunda del checkout de laboratorio

**Fase:** 2B.1 — READ-ONLY  
**Fecha:** 2026-09-17  
**Base Git:** `branches-info` @ `d7282ae6` (commit consolidado post-Fase 2A)  
**Regla:** Sin modificaciones de código, backend, tracking, payment ni appointment.

**Fuentes leídas:**
- `UX-REDESIGN-LABORATORY-CART-CHECKOUT.md`
- `UX-WIREFRAMES-LABORATORY-CART-CHECKOUT.md`
- `FASE-2A-SEPARATION-MANIFEST.md`
- Código actual de checkout (frontend + backend)

---

## 1. Executive summary

El checkout de laboratorio es una **única página Inertia** (`LaboratoryCheckout.jsx`) con wizard multi-paso controlado por query `?step=`, draft en BD (`laboratory_checkout_drafts`), `sessionStorage` y URL params. No existen rutas separadas por paso.

**Hallazgo crítico para Fase 2B:** el stepper actual etiqueta el paso `address` como **"Sucursal"**, pero el componente `AddressStep` gestiona **exclusivamente la dirección del paciente** (calle, colonia, CP, estado, municipio). La sucursal de laboratorio es un concepto distinto, cubierto por:
- **Preferida:** `selected_laboratory_store_id` en draft (opcional, desde carrito)
- **Confirmada:** `laboratory_appointments.laboratory_store_id` (Concierge, post-compra/cita)

**Selected store NEVER blocks checkout** — `assertValidForCheckout()` existe pero **no** se invoca en `OrderAction` ni en guards de pago.

Fase 2B debe corregir copy/stepper y jerarquía visual **sin tocar** contratos de draft sync, payment, appointment sync, ni tracking server-side.

**Alcance seguro de rediseño:** presentación de steps, sidebar summary, progressive disclosure, mobile footer, chip de sucursal preferida, densidad del panel lateral.

**Fuera de alcance 2B UX:** lógica EfevooPay/Odessa/PayPal, polling de cita, guards PHP, payloads de purchase, ActiveCampaign outbox.

---

## 2. Current flow

### 2.1 Diagrama de flujo

```
Carrito (LaboratoryShoppingCart)
    │ Continuar → route("laboratory.checkout", { step: "patient" })
    ▼
GET /laboratory/{brand}/checkout?step=...
    │ LaboratoryCheckoutController
    │   → InitiateCheckout::track (server, Meta/CAPI)
    │   → Props Inertia (todas los pasos en una respuesta)
    ▼
┌─────────────────────────────────────────────────────────────────┐
│ Wizard client-side (LaboratoryCheckout.jsx)                      │
│                                                                  │
│ FLUJO ESTÁNDAR sin cita:                                         │
│   patient → address → payment → confirmation                     │
│                                                                  │
│ FLUJO ESTÁNDAR con cita (requires_appointment):                  │
│   patient → address → payment → appointment → confirmation       │
│                                                                  │
│ FLUJO APPOINTMENT-FIRST (usesAppointmentFirstFlow):              │
│   patient → address → appointment → payment*                     │
│   * payment unifica confirmación + pago (isUnifiedPaymentStep)   │
└─────────────────────────────────────────────────────────────────┘
    │
    ├── POST draft sync (patient|address|payment)
    ├── POST appointment sync (contact → LaboratoryAppointment)
    ├── POST checkout store (compra) / PayPal create+capture
    └── POST api.laboratory.quote.store (pago en sucursal GDA)
```

### 2.2 Steps reales vs labels actuales

| ID técnico | Label stepper **actual** | Contenido real | Label UX spec 2B |
|------------|-------------------------|----------------|------------------|
| `patient` | Paciente | Selección/creación contacto | ¿Para quién? |
| `address` | **Sucursal** ⚠️ | **Dirección del paciente** | Tu dirección |
| `payment` | Pago | Métodos Efevoo/Odessa/PayPal + cupones | Pago |
| `appointment` | Cita | Callback Concierge, polling | Agenda tu cita |
| `confirmation` | Revisar y confirmar | Resumen + submit pago | Confirmar |

### 2.3 Condicionalidad

| Condición | Efecto |
|-----------|--------|
| `requiresAppointment` (prop + cart items) | Añade paso `appointment`; bloquea avance en confirmation hasta `confirmed_at` |
| `usesAppointmentFirstFlow` | Orden: appointment antes de payment; payment bloqueado sin cita confirmada |
| `laboratoryAppointment.confirmed_at` | Auto-avanza appointment → confirmation (estándar) o → payment (appointment-first) |
| `laboratoryAppointment.is_payable === false` | Redirige a appointment en appointment-first |
| Cupón 100% / balance | `payment_method = coupon_balance`; total $0 |

### 2.4 URL / query params

| Mecanismo | Clave | Uso |
|-----------|-------|-----|
| Query | `step` | Paso activo: patient, address, payment, appointment, confirmation |
| Query | `contact`, `address`, `payment_method`, `coupon_id` | Rehidratación formulario |
| sessionStorage | `laboratory-checkout-wizard:{brand}` | `{ stepId, contact, address, payment_method, ... }` |
| Draft BD | `checkout_step`, `contact_id`, `address_id`, etc. | Persistencia server-side |

`persistWizardState` y `persistWizardFormDataToUrl` sincronizan sessionStorage + `history.replaceState`.

### 2.5 Axios / Inertia calls

| Acción | Método | Ruta | Cuándo |
|--------|--------|------|--------|
| Draft sync | POST (Inertia) | `laboratory.checkout.draft.sync` | Continuar en patient/address/payment |
| Appointment sync | POST (Inertia) | `laboratory.checkout.appointment.sync` | Auto al entrar appointment; manual |
| Compra | POST (Inertia) | `laboratory.checkout.store` | Submit confirmation/payment |
| PayPal | POST | `paypal.create-order`, `paypal.capture-order` | `LaboratoryPayPalButton` |
| Pago sucursal GDA | POST (Inertia) | `api.laboratory.quote.store` | Submit branch_payment |
| Crear contacto | POST (axios) | `checkout.contacts.store` | ContactForm |
| Crear dirección | POST (axios) | `checkout.addresses.store` | AddressForm |
| Promo validate | POST | `laboratory.checkout.promo-codes.validate` | PromoCodeField |
| Polling cita | GET reload | Inertia `only: [laboratoryAppointment, ...]` | Interval 10s en step appointment |
| Cambiar sucursal preferida | navigate | `laboratory.shopping-cart` | Botón en SelectedLaboratoryStoreSummaryCard |

### 2.6 Redirects / guards (server)

`LaboratoryCheckoutStepGuard` en GET checkout puede redirigir con flash `checkout_step_notice` si el paso solicitado no es alcanzable.

`canInitiatePayment` valida contacto, dirección, método de pago, cita confirmada (si aplica) — **no** valida selected store.

---

## 3. Patient step

### 3.1 Archivos

- `ContactStep.jsx` → `CheckoutWizardStep` / `CheckoutSelectionCard` / `ContactForm`
- Orquestación: `LaboratoryCheckout.jsx` → `renderStepContent` case `"patient"`

### 3.2 Campos

| Campo | Tipo | Clasificación |
|-------|------|---------------|
| `data.contact` (contact_id) | ID seleccionado | **OBLIGATORIO** |
| `name`, `paternal_lastname`, `maternal_lastname` | Form nuevo paciente | **OBLIGATORIO** (si crea) |
| `birth_date` | date | **OBLIGATORIO** (si crea) |
| `gender` | enum | **OBLIGATORIO** (si crea) |
| `phone`, `phone_country` | tel | **OBLIGATORIO** (si crea) |
| `formatted_gender`, `formatted_birth_date` | display | **DERIVADO** |
| Heading copy | strings | **SOLO VISUAL** |

### 3.3 Validaciones

- Client: `canProceedFromStep` → `contactStepIsComplete` (= `!!data.contact`)
- Server: `SyncLaboratoryCheckoutDraftRequest` valida `contact_id` exists
- ContactForm: 422 desde `checkout.contacts.store`

### 3.4 Persistencia

- Continuar → `syncCheckoutDraft` POST `{ step: "patient", contact_id }`
- Registra `CartEventType::PatientSelected` vía `SyncLaboratoryCheckoutDraftAction`

### 3.5 Mobile vs desktop

- Mismo componente `variant="wizard"`
- Cards compactas (`compact={showRadio}`) en wizard
- `floatingWizardFooter` activo → Continuar/Volver sticky

### 3.6 Datos realmente necesarios

Para continuar: **solo `contact_id`**. El resto es creación/selección de entidad Contact existente en backend.

---

## 4. Address step

### 4.1 Qué significa "address" hoy

**100% dirección del paciente.** No contiene sucursal de laboratorio.

### 4.2 Campos AddressForm

| Campo | Clasificación |
|-------|---------------|
| `street` | **OBLIGATORIO** |
| `number` | **OBLIGATORIO** |
| `neighborhood` (colonia) | **OBLIGATORIO** |
| `state` | **OBLIGATORIO** (select mexicanStates) |
| `city` (municipio) | **OBLIGATORIO** (depende de state) |
| `zipcode` (CP) | **OBLIGATORIO** |
| `additional_references` | **OPCIONAL** |
| `data.address` (address_id) | **OBLIGATORIO** para continuar |

### 4.3 Confusión documentada

| Concepto | Dónde vive | CP aquí |
|--------|------------|---------|
| **Paciente Address** | `addresses` table, `AddressStep`, draft `address_id` | CP del domicilio del paciente |
| **CP carrito/sucursales** | draft `postal_code`, compatible-stores API | CP para recomendar sucursales — **independiente** |
| **Laboratory Store preferida** | draft `selected_laboratory_store_id` | N/A — es sucursal física lab |
| **Laboratory Store confirmada** | `laboratory_appointments.laboratory_store_id` | N/A — Concierge |

### 4.4 Bug UX actual (pre-2B)

Stepper label **"Sucursal"** para step `address` es **incorrecto** y contradice:
- `AddressStep` copy: "dirección del paciente"
- `UX-REDESIGN` §3 problema #2 y §9.2
- Wireframes: step G/H = "Checkout Address"

**Fase 2B debe corregir esto** (solo copy/labels UI, ID `address` intacto).

### 4.5 Persistencia

- Continuar → `syncCheckoutDraft` POST `{ step: "address", contact_id, address_id }`
- `CartEventType::AddressSelected`

---

## 5. Selected laboratory store

### 5.1 Campos draft

| Campo | Propósito |
|-------|-----------|
| `selected_laboratory_store_id` | FK preferencia opcional |
| `selected_laboratory_store_validated_at` | Timestamp última validación |
| `selected_laboratory_store_cart_hash` | Detecta stale si carrito cambia |
| `postal_code` | CP para recomendaciones (desde carrito API) |

### 5.2 Dónde se lee

| Ubicación | Método |
|-----------|--------|
| GET checkout | `SelectedLaboratoryStoreDraftService::checkoutState()` → prop `selectedLaboratoryStore` |
| Carrito | `fetchSelectedLaboratoryStore` / POST/DELETE selected-store |
| Admin Concierge | `conciergeRecommendation()` |

### 5.3 Dónde se muestra (checkout)

- `SelectedLaboratoryStoreSummaryCard` en `CheckoutLayout.summaryExtra` (sidebar)
- Estados UI vía `checkoutSelectedStorePresentation()`:
  - `valid` → verde, nombre sucursal, "Cambiar"
  - `missing` → azul informativo, "Ver sucursales recomendadas"
  - `stale` → ámbar, carrito cambió
  - `invalid` → ámbar, incompatible con requisitos

### 5.4 Comportamiento por estado

| Estado | Checkout bloqueado | UX |
|--------|-------------------|-----|
| No existe | **No** | Chip informativo azul |
| Valid | **No** | Chip verde + link carrito |
| Stale | **No** | Warning + "Actualizar sucursal" → carrito |
| Invalid | **No** | Warning + "Elegir otra" → carrito |
| Error server | **No** | `errors.selected_laboratory_store` en card (display only) |

`checkoutState()` oculta selección stale (trata como no seleccionada para UI de checkout).

### 5.5 Selected Store ≠ Confirmed Store

| | Preferida | Confirmada |
|---|-----------|------------|
| **Campo** | `draft.selected_laboratory_store_id` | `appointment.laboratory_store_id` |
| **Quién setea** | Usuario en carrito | Concierge en admin |
| **Cuándo** | Pre-checkout opcional | Post solicitud cita |
| **ConfirmationStep** | No muestra preferida | Muestra `laboratoryAppointment.laboratory_store` si confirmada |
| **Bloquea pago** | **Nunca** | N/A (cita confirmada es gate separado) |

### 5.6 assertValidForCheckout

Existe en `SelectedLaboratoryStoreDraftService` pero **no** se usa en:
- `LaboratoryPurchaseController::store`
- `PayPalController`
- `LaboratoryCheckoutStepGuard::canInitiatePayment`

Confirmado: **Selected Store NEVER blocks checkout.**

---

## 6. Summary (sidebar)

### 6.1 Componente

`CheckoutLayout` → `CheckoutSummary` → `CheckoutSummaryCard` + `CheckoutTotalsPanel`

### 6.2 Contenido actual

| Bloque | Fuente | Clasificación |
|--------|--------|---------------|
| Items carrito | props `items` | **OBLIGATORIO** (datos) |
| Contador estudios | derivado `items.length` | **SOLO VISUAL** (añadido recientemente) |
| Subtotal / descuento / total | `summaryDetails` | **OBLIGATORIO** |
| `summaryExtra` | SelectedLaboratoryStoreSummaryCard | **OPCIONAL** (preferida) |
| `couponSection` | PromoCodeField + BalanceCreditCard | **OPCIONAL** |
| `summaryActions` | Pagar ahora / PayPal | **OBLIGATORIO** en confirmation |
| Pago 100% seguro badge | estático | **SOLO VISUAL** |
| Remover item carrito | onDestroy en items | **Funcional** (no rediseñar lógica) |

### 6.3 Visibilidad por paso

- Sidebar visible en **todos** los pasos (layout 8+4 grid)
- Cupones ocultos en appointment-first excepto step `payment`
- `scrollToCheckoutSummaryTotals()` al aplicar cupón

### 6.4 Spec 2B — qué permanece visible

Según UX doc §9.1: total siempre accesible, items colapsables ("Ver detalles"), chip sucursal preferida, CTA contextual por paso.

---

## 7. Payment

### 7.1 Métodos (sin modificar contratos)

| Método | Valor `payment_method` | Condición |
|--------|------------------------|-----------|
| EfevooPay (tarjetas tokenizadas) | ID numérico string | `paymentMethods` prop |
| Odessa | `"odessa"` | `hasOdessaPay` |
| PayPal | `"paypal"` | `hasPayPal` + `paypalClientId` |
| Saldo cupón 100% | `"coupon_balance"` | total $0 con cupón |
| Pago en sucursal (GDA) | branch submit | `showBranchPayment={true}` → quote API |

### 7.2 UI rediseñable vs contrato

| Rediseñable (UX) | No tocar (contrato) |
|------------------|---------------------|
| Layout cards PaymentMethodStep | Values `payment_method` |
| Copy labels métodos | PayPal funding eligibility hook |
| Collapse promo code | `PromoCodeField` API + token |
| Orden visual métodos | `StoreLaboratoryPurchaseRequest` validation |
| Densidad sidebar totals | Server total recalculation |
| PayPal wordmark/tiles | create-order / capture-order payloads |

### 7.3 Server-side validation

- `StoreLaboratoryPurchaseRequest`: total, contact, address, payment_method
- `LaboratoryCheckoutStepGuard::canInitiatePayment`
- `OrderAction` ejecuta cobro Efevoo/Odessa/coupon

### 7.4 Success / failure / retry

- Inertia `post` checkout.store → redirect compra o errors
- PayPal: componente dedicado con callbacks
- Branch: redirect GDA `redirect_url`
- `checkoutSubmittedRef` previene double submit
- `checkoutProcessing` deshabilita botones

### 7.5 Campos form checkout submit

```javascript
{ contact, address, payment_method, coupon_id, promo_validation_token,
  laboratory_appointment, total }
```

---

## 8. Appointment

### 8.1 Cuándo aparece

- Step `appointment` en wizard si `needsAppointment`
- Posición: después de payment (estándar) o antes (appointment-first)

### 8.2 Componente

`LaboratoryAppointmentStep.jsx` + `LaboratoryAppointmentInfo` en checkout page

### 8.3 Flujo

1. Usuario llega a appointment con contact (+ address en draft)
2. Auto `syncAppointmentFromContact` si no hay pending/active
3. POST `laboratory.checkout.appointment.sync` → crea/actualiza `LaboratoryAppointment`
4. Polling cada **10s** reload props appointment
5. Usuario deja callback preference (teléfono, horario)
6. Concierge confirma → `confirmed_at` seteado
7. Gate: payment/confirmation requiere `confirmed_at` si needs appointment

### 8.4 Sucursal en cita

- **Confirmada:** `laboratory_appointments.laboratory_store_id` — seteada por Concierge
- **Preferida del carrito:** solo informativa para Concierge (`conciergeRecommendation` en admin)
- Copy appointment step: "definir con concierge la **sucursal**, fecha y hora"

### 8.5 appointment-first

- `isUnifiedPaymentStep`: payment incluye confirmation + legal text + PayPal
- No step `confirmation` separado en wizard steps array
- Payment bloqueado hasta `laboratoryAppointment.confirmed_at`

---

## 9. Confirmation

### 9.1 Componente

`ConfirmationStep.jsx` — secciones: Paciente, Dirección, Pago, Cita (si `laboratoryAppointment.laboratory_store`)

### 9.2 Acciones

- Edit links → `onEditStep(stepId)` → navega wizard
- Submit "Pagar ahora" o PayPal panel
- Legal text términos/privacidad

### 9.3 No incluye

- Sucursal preferida (solo cita confirmada si existe)
- CP de recomendación carrito

---

## 10. Tracking

### 10.1 GA4 / GTM (frontend checkout)

**No hay `sendGA4Event` en `LaboratoryCheckout.jsx` ni componentes Checkout/**

Eventos GA4 de checkout ocurren en:
- **Carrito:** `begin_checkout` (pre-navegación) — doble fire página+layout
- **Post-compra:** probablemente página de confirmación (fuera de scope checkout wizard)

### 10.2 Meta / CAPI (server)

| Evento | Dónde | Cuándo |
|--------|-------|--------|
| `InitiateCheckout` | `LaboratoryCheckoutController` GET | Entrada checkout |
| `Purchase` | `LaboratoryPurchaseController::store` | Compra exitosa |

### 10.3 Cart journey events (AC outbox pipeline)

Vía `SyncLaboratoryCheckoutDraftAction` + `CartEventRecorder`:

| CartEventType | Trigger |
|---------------|---------|
| `CheckoutStarted` | Primer draft sync |
| `PatientSelected` | step patient |
| `AddressSelected` | step address |
| `PaymentMethodSelected` | step payment |
| `AppointmentRequested` | appointment sync |
| `CheckoutFlowDetermined` | flow resolver |
| `CheckoutVisited` | activity resolver |

**No modificar** nombres ni payloads en Fase 2B.

### 10.4 Hotjar / phone intent

- `tel:` links en `LaboratoryAppointmentInfo` (Concierge phone)
- Sin Hotjar explícito en archivos checkout auditados

### 10.5 ActiveCampaign

Sin observers/services AC modificados en commit checkout. Cart events alimentan outbox existente — **no tocar** en 2B UX.

---

## 11. ActiveCampaign

**Estado:** INTACTO respecto a checkout redesign scope.

- Observers (`LaboratoryCartItemObserver`, etc.) sin cambios en paths AC
- Outbox driven por `CartEventRecorder` en draft sync — comportamiento backend existente
- Fase 2B UX **no debe** añadir fires client-side AC

---

## 12. Draft / persistence

### 12.1 Modelo `LaboratoryCheckoutDraft`

| Campo | Exposed `forCheckout()` | Step |
|-------|-------------------------|------|
| `contact_id` | ✅ | patient |
| `address_id` | ✅ | address |
| `payment_method` | ✅ | payment |
| `coupon_id` | ✅ | payment |
| `promo_validation_token` | ✅ | payment |
| `checkout_step` | ✅ | navigation |
| `postal_code` | ✅ | carrito bridge |
| `selected_laboratory_store_id` | ✅ | carrito bridge |
| `_validated_at`, `_cart_hash` | ❌ server only | staleness |

### 12.2 sessionStorage

Key: `laboratory-checkout-wizard:{brand}`  
Guarda step + form fields para recuperación client-side.

### 12.3 Resume links

`LaboratoryCheckoutResumeController` — flujo de recuperación carrito abandonado (fuera de wizard UX pero mismo draft).

---

## 13. Risks — matriz

| Área | Riesgo | Qué NO tocar | Qué SÍ puede rediseñarse |
|------|--------|--------------|--------------------------|
| **Patient** | Medio — form axios + draft sync | `contact_id` contract, route `checkout.contacts.store` | Cards, headings, spacing, compact mode |
| **Address** | **Alto** — label "Sucursal" confunde | `address_id`, AddressForm fields/API | Stepper label → "Tu dirección", títulos, chip preferida separado |
| **Selected Store** | Medio — mezclar con address | draft fields, API selected-store, no blocking | Chip sidebar, copy preferencia, link carrito |
| **Summary** | Bajo | `summaryDetails` structure, item remove | Collapse items, density, sticky behavior |
| **Payment** | **Alto** | payment_method values, PayPal hook, submit | Card layout, promo collapse, copy |
| **Appointment** | **Alto** | polling interval, sync endpoint, confirmed_at gate | Visual states, callback form layout |
| **Confirmation** | Medio | submit flow, edit step IDs | Section cards, legal text placement |
| **Tracking** | **Alto** | InitiateCheckout, CartEvent types, Purchase | Nada client-side nuevo sin spec |
| **ActiveCampaign** | Alto | outbox pipeline | N/A |
| **Draft** | **Alto** | sync payload shape, step IDs | N/A |
| **Navigation** | Medio | step IDs (`patient` not `contact`) | Stepper labels, icons, progress UI |

---

## 14. Safe redesign zones

### ✅ Seguro (presentación)

1. Stepper labels e iconografía (mantener IDs)
2. Títulos/subtítulos `CheckoutWizardStep`
3. Densidad sidebar — collapse items list
4. `SelectedLaboratoryStoreSummaryCard` visual refinement
5. Mobile floating footer simplification
6. Spacing CheckoutLayout (ya iniciado en commit actual)
7. Progressive disclosure promo/credits
8. Separación visual paciente vs preferencia sucursal

### ⚠️ Con cuidado

1. Reordenar sidebar blocks (summaryExtra vs couponSection)
2. Unificar payment+confirmation visual en appointment-first
3. Edit links en ConfirmationStep

### ❌ Prohibido en 2B UX

1. Renombrar step IDs
2. Cambiar draft sync payloads
3. Añadir validación selected store en pago
4. Mover appointment sync logic
5. Nuevo GA4 events en checkout page
6. Modificar PayPal/Efevoo/Odessa integration
7. Usar `assertValidForCheckout` en submit

---

## 15. Proposed UX (sin implementar)

Basado en `UX-REDESIGN` §9–10 y wireframes E–N.

### 15.1 Jerarquía

```
┌ Stepper (labels humanos, IDs técnicos ocultos) ─────────────┐
├ Paso actual (una pregunta) ──────────────────── 8 cols ────┤
│  [Wizard content]                                           │
├ Sidebar resumen ─────────────────────────────── 4 cols ────┤
│  Total · items collapse · preferida · cupón                 │
└ Footer móvil Continuar/Volver ─────────────────────────────┘
```

### 15.2 Stepper propuesto

| Label UI | ID |
|----------|-----|
| ¿Para quién? | patient |
| Tu dirección | address |
| Pago | payment |
| Agenda tu cita | appointment |
| Confirmar | confirmation |

**Nunca** "Sucursal" en step address.

### 15.3 Progressive disclosure

- Sidebar items: max-height + "Ver detalles"
- Promo: collapsed por default
- Preferida sucursal: chip compacto, expand on tap (mobile)

### 15.4 Sucursal preferida en checkout

Ubicación: **sidebar only** (summaryExtra), nunca dentro de AddressStep form.

Copy: "Sucursal preferida · Opcional · No reserva cita"

Acción: "Cambiar" → carrito `#sucursales` o route shopping-cart.

### 15.5 Address step propuesto

- Título: **"¿Cuál es tu dirección?"**
- Subtítulo: expediente y comunicación de resultados
- Zero mención de sucursal lab en este paso

---

## 16. Mobile

### Actual

- Mismo wizard, grid colapsa a stack
- `CheckoutWizardFloatingFooter` en patient/address/payment/appointment
- Sidebar summary debajo o accesible scroll
- Step scroll into view on change

### Propuesto (wireframes J, N)

- Sticky bottom: Continuar + total mini
- Sidebar → accordion "Resumen" collapsible top o bottom sheet read-only
- Stepper horizontal scroll compact
- Preferida sucursal: chip bajo total, no full card

---

## 17. Desktop

### Actual

- 8+4 grid en CheckoutLayout
- Sidebar sticky `top-8`
- Stepper horizontal completo

### Propuesto (wireframes E, G, I, M)

- Mantener 8+4
- Sidebar sticky con total prominente
- Items scroll max-h-52 (ya en commit)
- Stepper con estados ✓ ● ○

---

## 18. Questions requiring validation

| # | Pregunta | Impacto |
|---|----------|---------|
| Q1 | ¿Corregir label "Sucursal" → "Tu dirección" en primer commit 2B? | Alto — alinea spec |
| Q2 | ¿Título checkout page "¿Para quién son los estudios?" solo en step patient o global? | Medio — ya global en commit actual |
| Q3 | ¿Mostrar chip preferida en step address además de sidebar? | Medio — spec dice debajo formulario |
| Q4 | ¿Inline read-only preferida o solo link a carrito? | Bajo |
| Q5 | ¿Rediseñar ContactStep/CheckoutLayout spacing del commit consolidado o revert parcial? | Medio |
| Q6 | ¿Appointment-first unifica payment+confirmation — mantener o separar visualmente? | Alto — no cambiar lógica |
| Q7 | ¿Branch payment (GDA quote) visible en todos los flujos? | Negocio |
| Q8 | ¿Cuándo ejecutar `assertValidForCheckout` en futuro (post-2B)? | Arquitectura — fuera 2B |

---

## 19. Recommended implementation phases

### Fase 2B.1 — Auditoría ✅ (este documento)

READ-ONLY. Sin código.

### Fase 2B.2 — Stepper + copy fix (bajo riesgo)

- Corregir `buildWizardSteps` labels address
- Alinear títulos ContactStep/AddressStep con spec
- Verificar stepper `CheckoutStepper` recibe labels nuevos
- **No tocar** IDs ni draft sync

### Fase 2B.3 — Sidebar summary UX

- Collapse items (parcialmente hecho)
- Refinar `SelectedLaboratoryStoreSummaryCard` copy/layout
- Promo/credits progressive disclosure
- Mobile summary accordion

### Fase 2B.4 — Payment + Confirmation polish

- PaymentMethodStep card visual refresh
- ConfirmationStep density
- Legal text placement
- **Sin** cambiar submit/PayPal

### Fase 2B.5 — Appointment step polish

- Visual states only
- **Sin** tocar polling/sync

### Fase 2B.6 — QA + manual browser

- Flujos: sin cita, con cita, appointment-first
- Preferida valid/stale/missing
- Payment methods smoke
- Regression tracking (InitiateCheckout, Purchase, CartEvents)

---

## Apéndice A — Archivos revisados

### Frontend principal

- `resources/js/Pages/LaboratoryCheckout.jsx`
- `resources/js/Layouts/CheckoutLayout.jsx`
- `resources/js/Components/Checkout/ContactStep.jsx`
- `resources/js/Components/Checkout/AddressStep.jsx`
- `resources/js/Components/Checkout/PaymentMethodStep.jsx`
- `resources/js/Components/Checkout/LaboratoryAppointmentStep.jsx`
- `resources/js/Components/Checkout/ConfirmationStep.jsx`

### Frontend importados directamente

- `CheckoutStepper.jsx`, `CheckoutWizardStep.jsx`, `CheckoutStep.jsx`
- `CheckoutSelectionCard.jsx`, `CheckoutSaveSuccessAlert.jsx`
- `CheckoutWizardFloatingFooter.jsx`, `CheckoutWhatsAppHelp.jsx`
- `LaboratoryPayPalButton.jsx`, `PromoCodeField.jsx`
- `BalanceCreditCard.jsx` (coupons)
- `resources/js/lib/laboratoryCompatibleStores.js` (checkoutSelectedStorePresentation)
- `resources/js/lib/couponEligibilityUi.js`
- `resources/js/Hooks/usePayPalFundingEligibility.jsx`

### Backend

- `app/Http/Controllers/LaboratoryCheckoutController.php`
- `app/Http/Controllers/LaboratoryPurchaseController.php`
- `app/Http/Controllers/LaboratoryCheckoutSelectedStoreController.php`
- `app/Services/Laboratory/SelectedLaboratoryStoreDraftService.php`
- `app/Services/Laboratory/LaboratoryCheckoutStepGuard.php`
- `app/Actions/Laboratories/SyncLaboratoryCheckoutDraftAction.php`
- `app/Models/LaboratoryCheckoutDraft.php`
- `app/Http/Requests/LaboratoryCheckout/*`
- `routes/laboratories.php`
- `app/Services/Tracking/InitiateCheckout.php`

### Documentación

- `UX-REDESIGN-LABORATORY-CART-CHECKOUT.md`
- `UX-WIREFRAMES-LABORATORY-CART-CHECKOUT.md`
- `FASE-2A-SEPARATION-MANIFEST.md`

---

## Apéndice B — Validación decisión "Address ≠ Store"

### Estado actual (incorrecto en stepper)

```
Step ID: address
Stepper label: "Sucursal"  ← INCORRECTO
Component: AddressStep → dirección paciente
```

### Estado objetivo Fase 2B

```
Step ID: address (sin cambio)
Stepper label: "Tu dirección"
Step title: "¿Cuál es tu dirección?"
Sidebar: "Sucursal preferida" (opcional, si draft valid)
Appointment: "Sucursal confirmada" (solo post-Concierge)
```

### Regla de oro

> **Address Step ≠ Sucursal.**  
> La sucursal aparece únicamente como **"Sucursal preferida"** (opcional) en sidebar o chip dedicado, nunca como label del paso de dirección.

---

*Fin del documento — Fase 2B.1 READ-ONLY audit.*
