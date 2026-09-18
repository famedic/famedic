# FASE 2A.3 — SEPARATION MANIFEST

**Fecha:** 2026-09-17  
**Propósito:** Documentar inventario, clasificación y plan de separación del working tree sin perder trabajo.  
**Estado base:** READ-ONLY audit → preparación para commits atómicos futuros.

---

## 0. Estado Git preservado (READ-ONLY)

### Comandos ejecutados (sin efectos destructivos)

```bash
git status --short
git branch --show-current
git log --oneline -15
git diff --stat
git diff --name-only
git ls-files --others --exclude-standard
git rev-parse HEAD
git log --oneline -30 --grep=laboratory -i
git log --oneline -20 --grep=postal -i
git log --oneline -20 --grep=checkout -i
git log --oneline -20 --grep=cart -i
git log --oneline -15 -- routes/laboratories.php resources/js/Pages/LaboratoryShoppingCart.jsx
```

### Comandos NO ejecutados (por diseño)

- `git reset`, `git checkout`, `git revert`, `git stash`, `git clean`
- `git cherry-pick`, `git commit`, `git add`
- Cualquier operación que modifique working tree o historial

### Base identificada

| Campo | Valor |
|-------|-------|
| **Branch actual** | `branches-info` |
| **HEAD** | `6efbe0865924b19bcb049590d3d55a99abe75f5c` |
| **Último commit** | `6efbe086` — feat(laboratory): add GDA result status tracking, refresh, completion gate and admin controls |
| **Working tree** | 15 modified tracked + 58 untracked files |
| **Diff stat** | +1254 / −658 en 15 archivos trackeados |

### Observación sobre historial vs working tree

**Todo el trabajo de CP/sucursales/cart UX/checkout bridge está SIN COMMIT** (untracked o modified sobre HEAD `6efbe086`).

Los commits recientes en `branches-info` **no incluyen** postal code, compatible stores, selected store, ni rediseño carrito 2A:

- `--grep=postal` → sin resultados
- `--grep=laboratory` → GDA results, share links, stores admin (distinto feature)
- `--grep=checkout` → resume links, mejoras visuales previas
- `--grep=cart` → cart journey tracking / ActiveCampaign (PR #31, commits antiguos)
- Historial de `LaboratoryShoppingCart.jsx` → créditos, cupones, cita; **no** rediseño 2A actual

**Conclusión:** el working tree mezcla al menos **3 bloques de trabajo no commiteados** encima de HEAD GDA/results.

---

## A — FASE 2A UX (carrito laboratorio)

Lista exacta — **9 archivos**

| # | Path | Estado |
|---|------|--------|
| 1 | `resources/js/Pages/LaboratoryShoppingCart.jsx` | modified |
| 2 | `resources/js/Layouts/LaboratoryShoppingCartLayout.jsx` | untracked |
| 3 | `resources/js/Components/LaboratoryCart/LabStudyCard.jsx` | untracked |
| 4 | `resources/js/Components/LaboratoryCart/OrderSummary.jsx` | untracked |
| 5 | `resources/js/Components/LaboratoryCart/StickyCheckoutBar.jsx` | untracked |
| 6 | `resources/js/Components/LaboratoryCart/BranchBottomSheet.jsx` | untracked |
| 7 | `resources/js/Components/LaboratoryCompatibleStoresSection.jsx` | untracked |
| 8 | `UX-REDESIGN-LABORATORY-CART-CHECKOUT.md` | untracked |
| 9 | `UX-WIREFRAMES-LABORATORY-CART-CHECKOUT.md` | untracked |

**Nota:** `LaboratoryCompatibleStoresSection.jsx` es **capa UX (A)** sobre APIs del bloque **B**. No incluir backend B dentro del commit A.

---

## B — ARQUITECTURA CP / SUCURSALES / COMPATIBILIDAD

Lista exacta — **42 archivos**

### Migraciones (unidad atómica — NO separar)

| # | Path |
|---|------|
| 1 | `database/migrations/2026_09_17_000001_create_laboratory_study_requirement_groups_table.php` |
| 2 | `database/migrations/2026_09_17_000002_create_laboratory_study_requirements_table.php` |
| 3 | `database/migrations/2026_09_17_000003_add_selected_store_to_laboratory_checkout_drafts_table.php` |
| 4 | `database/migrations/2026_09_17_000004_add_postal_code_to_laboratory_checkout_drafts_table.php` |
| 5 | `database/migrations/2026_09_17_000005_create_postal_code_locations_table.php` |

### Controllers

| # | Path | Estado |
|---|------|--------|
| 6 | `app/Http/Controllers/LaboratoryCompatibleStoresController.php` | untracked |
| 7 | `app/Http/Controllers/LaboratoryCheckoutSelectedStoreController.php` | untracked |

### Requests / Resources

| # | Path | Estado |
|---|------|--------|
| 8 | `app/Http/Requests/Laboratories/CompatibleLaboratoryStoresRequest.php` | untracked |
| 9 | `app/Http/Requests/LaboratoryCheckout/StoreSelectedLaboratoryStoreRequest.php` | untracked |
| 10 | `app/Http/Resources/CompatibleLaboratoryStoreResource.php` | untracked |

### Models

| # | Path | Estado |
|---|------|--------|
| 11 | `app/Models/LaboratoryStudyRequirement.php` | untracked |
| 12 | `app/Models/LaboratoryStudyRequirementGroup.php` | untracked |
| 13 | `app/Models/PostalCodeLocation.php` | untracked |
| 14 | `app/Models/LaboratoryCheckoutDraft.php` | modified |
| 15 | `app/Models/LaboratoryCapability.php` | modified |
| 16 | `app/Models/LaboratoryTest.php` | modified |

### Services — Laboratory

| # | Path | Estado |
|---|------|--------|
| 17 | `app/Services/Laboratory/PostalCodeLocationResolver.php` | untracked |
| 18 | `app/Services/Laboratory/SelectedLaboratoryStoreDraftService.php` | untracked |
| 19 | `app/Services/Laboratory/SelectedLaboratoryStoreException.php` | untracked |

### Services — LaboratoryRequirements

| # | Path | Estado |
|---|------|--------|
| 20 | `app/Services/LaboratoryRequirements/BranchResolver.php` | untracked |
| 21 | `app/Services/LaboratoryRequirements/CartRequirementAggregator.php` | untracked |
| 22 | `app/Services/LaboratoryRequirements/PackageRequirementParser.php` | untracked |
| 23 | `app/Services/LaboratoryRequirements/StudyRequirementResolver.php` | untracked |
| 24 | `app/Services/LaboratoryRequirements/README.md` | untracked |

### Support — LaboratoryRequirements

| # | Path | Estado |
|---|------|--------|
| 25 | `app/Support/LaboratoryRequirements/BranchHoursInfo.php` | untracked |
| 26 | `app/Support/LaboratoryRequirements/BranchMatchResult.php` | untracked |
| 27 | `app/Support/LaboratoryRequirements/BranchResolution.php` | untracked |
| 28 | `app/Support/LaboratoryRequirements/CartRequirement.php` | untracked |
| 29 | `app/Support/LaboratoryRequirements/CartRequirementGroup.php` | untracked |
| 30 | `app/Support/LaboratoryRequirements/CartRequirements.php` | untracked |
| 31 | `app/Support/LaboratoryRequirements/GeoPoint.php` | untracked |
| 32 | `app/Support/LaboratoryRequirements/GroupMatchResult.php` | untracked |
| 33 | `app/Support/LaboratoryRequirements/ParsedPackageComponent.php` | untracked |
| 34 | `app/Support/LaboratoryRequirements/ResolvedRequirement.php` | untracked |
| 35 | `app/Support/LaboratoryRequirements/ResolvedRequirementGroup.php` | untracked |
| 36 | `app/Support/LaboratoryRequirements/ResolvedStudyRequirements.php` | untracked |

### Console / Routes / Frontend lib

| # | Path | Estado |
|---|------|--------|
| 37 | `app/Console/Commands/ImportPostalCodeLocationsCommand.php` | untracked |
| 38 | `routes/laboratories.php` | modified |
| 39 | `resources/js/lib/laboratoryCompatibleStores.js` | untracked |

### Tests B

| # | Path | Estado |
|---|------|--------|
| 40 | `tests/Feature/LaboratoryCompatibleStoresEndpointTest.php` | untracked |
| 41 | `tests/Feature/LaboratoryCheckoutSelectedStoreTest.php` | untracked |
| 42 | `tests/Feature/LaboratoryCheckoutSelectedStoreIntegrationTest.php` | untracked |
| 43 | `tests/Feature/PostalCodeLocationResolverTest.php` | untracked |
| 44 | `tests/Feature/PostalCodeLocationsImporterTest.php` | untracked |
| 45 | `tests/Unit/LaboratoryRequirements/BranchResolverTest.php` | untracked |
| 46 | `tests/Unit/LaboratoryRequirements/CartRequirementAggregatorTest.php` | untracked |
| 47 | `tests/Unit/LaboratoryRequirements/PackageRequirementParserTest.php` | untracked |
| 48 | `tests/Unit/LaboratoryRequirements/StudyRequirementResolverTest.php` | untracked |
| 49 | `tests/JavaScript/laboratoryCompatibleStores.test.mjs` | untracked |

*(Conteo B productivo: 39 archivos + 10 tests = 49 paths; migraciones contadas como 1 unidad de 5 archivos.)*

---

## C — CHECKOUT / APPOINTMENT

Lista exacta — **8 archivos** (+ tests asociados en F)

| # | Path | Estado | Propósito |
|---|------|--------|-----------|
| 1 | `resources/js/Pages/LaboratoryCheckout.jsx` | modified | Card sucursal preferida; copy wizard/header; prop `selectedLaboratoryStore` |
| 2 | `resources/js/Layouts/CheckoutLayout.jsx` | modified | Prop `summaryExtra`; sidebar más compacto |
| 3 | `resources/js/Components/Checkout/ContactStep.jsx` | modified | Copy paciente; cards compactas |
| 4 | `app/Http/Controllers/LaboratoryCheckoutController.php` | modified | Pasa `selectedLaboratoryStore` desde draft |
| 5 | `app/Http/Controllers/Admin/LaboratoryAppointmentController.php` | modified | Recomendación sucursal Concierge |
| 6 | `app/Http/Requests/Admin/LaboratoryAppointments/UpdateLaboratoryAppointmentRequest.php` | modified | Validación compatibilidad sucursal |
| 7 | `resources/js/Pages/Admin/LaboratoryAppointment.jsx` | modified | UI recomendación sucursal en confirmación |
| 8 | `resources/js/lib/laboratoryAppointmentRecommendation.js` | untracked | Helpers presentación admin |

**Sin cambios en working tree:**

- `AddressStep.jsx`, `PaymentMethodStep.jsx`, `LaboratoryAppointmentStep.jsx`, `ConfirmationStep.jsx`
- PayPal / componentes de pago

---

## D — TRACKING / ACTIVECAMPAIGN

### ActiveCampaign

**Sin archivos modificados ni untracked** en observers, jobs, services AC, outbox.

| Path | Estado |
|------|--------|
| *(ninguno en working tree)* | INTACTO respecto a HEAD |

### Tracking (GA4 embebido — no archivos dedicados)

| Path | Grupo | Eventos | Notas |
|------|-------|---------|-------|
| `resources/js/Pages/LaboratoryShoppingCart.jsx` | A (+ D) | `view_cart`, `remove_from_cart`, `begin_checkout`, `select_item` | Página; incluye `ecommerce: null` reset |
| `resources/js/Layouts/LaboratoryShoppingCartLayout.jsx` | A (+ D) | `remove_from_cart`, `begin_checkout` | Layout; sin `coupon`; doble fire con página |
| `resources/js/Layouts/ShoppingCartLayout.jsx` | E (+ D) | `remove_from_cart`, `begin_checkout` | Pre-existente; GA4 logic sin cambio estructural en diff |

**Lista D explícita:** vacía de archivos exclusivos AC. Tracking documentado como sub-aspecto de A y E.

---

## E — FARMACIA

Lista exacta — **1 archivo**

| # | Path | Estado |
|---|------|--------|
| 1 | `resources/js/Layouts/ShoppingCartLayout.jsx` | modified |

**Consumidor:** `resources/js/Pages/OnlinePharmacyShoppingCart.jsx` (sin cambios en working tree).

**El carrito lab ya NO usa este layout** — usa `LaboratoryShoppingCartLayout.jsx`.

---

## F — TESTS (por dominio)

### Tests B (incluidos arriba en B)

10 archivos — ver sección B.

### Tests C

| # | Path | Estado |
|---|------|--------|
| 1 | `tests/Feature/Admin/LaboratoryAppointmentsPendingTabTest.php` | modified |
| 2 | `tests/Feature/Carts/CartPaymentMethodSelectedTest.php` | modified |
| 3 | `tests/JavaScript/laboratoryAppointmentRecommendation.test.mjs` | untracked |

### Tests A

Sin tests JS dedicados exclusivos a componentes `LaboratoryCart/*`.  
`laboratoryCompatibleStores.test.mjs` pertenece a **B** (lib compartida).

---

## 4. Clasificación por dependencia — archivos A

| Archivo | Pertenece A | Depende B | Depende C | Riesgo separar |
|---------|-------------|-----------|-----------|----------------|
| `LaboratoryShoppingCart.jsx` | ✅ | ✅ (import Section) | ❌ | 🟡 Medio |
| `LaboratoryShoppingCartLayout.jsx` | ✅ | ❌ | ❌ | 🟢 Bajo |
| `LabStudyCard.jsx` | ✅ | ❌ | ❌ | 🟢 Bajo |
| `OrderSummary.jsx` | ✅ | ❌ | ❌ | 🟢 Bajo |
| `StickyCheckoutBar.jsx` | ✅ | ❌ | ❌ | 🟢 Bajo |
| `BranchBottomSheet.jsx` | ✅ | ❌ | ❌ | 🟢 Bajo |
| **`LaboratoryCompatibleStoresSection.jsx`** | ✅ UX | **✅ obligatorio** | ❌ | 🔴 Alto — A+B acoplado en runtime |
| `UX-REDESIGN-*.md` | ✅ docs | ❌ | ❌ | 🟢 Bajo |
| `UX-WIREFRAMES-*.md` | ✅ docs | ❌ | ❌ | 🟢 Bajo |

### Regla documentada: LaboratoryCompatibleStoresSection.jsx

```
Categoría:     A (presentación UX)
Dependencia:   B (laboratoryCompatibleStores.js + endpoints + draft)
Commit A solo: NO funcional sin B previamente mergeado
Duplicar B en A: PROHIBIDO — mantener backend en commit B separado
```

---

## 5. Farmacia — ShoppingCartLayout.jsx

### Diff vs HEAD

| Métrica | Valor |
|---------|-------|
| Líneas diff | 259 (~98 insertions, 83 deletions) |
| Archivo | `resources/js/Layouts/ShoppingCartLayout.jsx` |

### Cambios introducidos (respecto a HEAD)

1. **Import:** añade `PlusIcon`.
2. **Botón "agregar más":** movido desde `OrderSummary` interno → zona superior del layout (antes del grid).
3. **CartItem compacto:**
   - Padding reducido (`py-6 sm:py-10` → `py-4 sm:py-5`).
   - Imágenes más pequeñas (`size-24/48` → `size-16/20`).
   - Precio y descuento movidos arriba-derecha junto al título.
   - Descripción/indicaciones/features colapsados en `<details>Ver detalles</details>`.
4. **Copy hardcoded:** texto fijo `"Estudio de laboratorio"` bajo cada item — **incorrecto para farmacia**.
5. **GA4:** handlers `remove_from_cart` / `begin_checkout` **no reescritos** en el diff; lógica pre-existente intacta.

### Origen probable

Refactor visual del carrito lab aplicado al layout compartido **antes** de extraer `LaboratoryShoppingCartLayout`. Spillover de trabajo 2A, no feature farmacia.

### Decisión para separar A (NO ejecutada)

| Opción | Recomendación |
|--------|---------------|
| Incluir en commit A | ❌ **NO** — no pertenece a Fase 2A |
| Revertir posteriormente | ✅ **Preferido** si farmacia no debe cambiar aún |
| Commit E independiente | ✅ Alternativa si se desea conservar UI compacta en farmacia (corrigiendo copy) |

**Para commit A puro:** `ShoppingCartLayout.jsx` **debe quedar fuera**.

---

## 6. Checkout — detalle por archivo

### LaboratoryCheckout.jsx

| Campo | Detalle |
|-------|---------|
| Diff vs HEAD | ~219 líneas |
| Propósito | Integrar sucursal preferida en sidebar; acortar labels wizard; copy orientado a paciente |
| Categoría | **C** (bridge B→checkout) + UX checkout temprano |
| Depende A | Indirecto — link "Cambiar" → `laboratory.shopping-cart` |
| Depende B | ✅ `checkoutSelectedStorePresentation` desde `laboratoryCompatibleStores.js`; prop `selectedLaboratoryStore` |
| ¿Fuera de 2A? | **✅ SÍ** — no incluir en commit A |

**Cambios clave:**

- Import `checkoutSelectedStorePresentation`
- Wizard: step `address` label → **"Sucursal"** (⚠️ contradice spec UX — requiere decisión humana)
- Nuevo `SelectedLaboratoryStoreSummaryCard`
- Prop `selectedLaboratoryStore`
- `summaryExtra` en CheckoutLayout
- Título/header → "¿Para quién son los estudios?"

### CheckoutLayout.jsx

| Campo | Detalle |
|-------|---------|
| Diff vs HEAD | ~208 líneas |
| Propósito | Slot `summaryExtra`; compactar sidebar (items, totales, padding) |
| Categoría | **C** |
| Depende A | ❌ |
| Depende B | Indirecto (slot usado para card de sucursal) |
| ¿Fuera de 2A? | **✅ SÍ** |

### ContactStep.jsx

| Campo | Detalle |
|-------|---------|
| Diff vs HEAD | ~96 líneas |
| Propósito | Copy paciente; layout compacto en wizard |
| Categoría | **C** (UX checkout — posible 2B) |
| Depende A | ❌ |
| Depende B | ❌ |
| ¿Fuera de 2A? | **✅ SÍ** |

### LaboratoryCheckoutController.php

| Campo | Detalle |
|-------|---------|
| Diff vs HEAD | ~37 líneas |
| Propósito | Resolver y pasar `selectedLaboratoryStore` a Inertia |
| Categoría | **B→C bridge** |
| Depende A | ❌ |
| Depende B | ✅ `SelectedLaboratoryStoreDraftService` |
| ¿Fuera de 2A? | **✅ SÍ** |

---

## 7. Migraciones — unidad B

```
database/migrations/2026_09_17_000001_create_laboratory_study_requirement_groups_table.php
database/migrations/2026_09_17_000002_create_laboratory_study_requirements_table.php
database/migrations/2026_09_17_000003_add_selected_store_to_laboratory_checkout_drafts_table.php
database/migrations/2026_09_17_000004_add_postal_code_to_laboratory_checkout_drafts_table.php
database/migrations/2026_09_17_000005_create_postal_code_locations_table.php
```

| Regla | Descripción |
|-------|-------------|
| **Unidad atómica** | Las 5 migraciones van juntas en commit B |
| **Orden** | 001 → 002 → 003 → 004 → 005 |
| **003+004** | Extienden `laboratory_checkout_drafts` (selected_store + postal_code) |
| **NO separar** | Separar rompe FK, draft service y endpoints |

---

## 8. Riesgo de separación por grupo

| Grupo / artefacto | Riesgo | Clasificación |
|-------------------|--------|---------------|
| Migraciones 2026_09_17_* (5) | Separar individualmente rompe schema | 🔴 No separar |
| `LaboratoryCheckoutDraft.php` + migrations 003–004 | Acoplado a selected store y CP | 🔴 No separar de B |
| `routes/laboratories.php` | Rutas B requieren controllers B | 🔴 No separar de B |
| `laboratoryCompatibleStores.js` | Cliente de API B; Section y Checkout dependen | 🔴 No separar de B |
| `LaboratoryCompatibleStoresSection.jsx` | UX A pero runtime B | 🟡 Requiere cuidado — commit A después de B |
| `LaboratoryShoppingCart.jsx` | Orquesta A; Section necesita B | 🟡 Requiere cuidado |
| Componentes `LaboratoryCart/*` | Aislados | 🟢 Seguro |
| `LaboratoryShoppingCartLayout.jsx` | Aislado | 🟢 Seguro |
| Docs UX | Aislados | 🟢 Seguro |
| Bloque C checkout | Independiente de A si B mergeado | 🟡 Requiere cuidado |
| `ShoppingCartLayout.jsx` | Afecta farmacia; spillover | 🟡 Requiere cuidado — commit E o revert |
| ActiveCampaign | Sin cambios | 🟢 Seguro |

---

## 9. Plan final (NO EJECUTADO)

### Orden recomendado de commits

```
HEAD (6efbe086) @ branches-info
    │
    ▼
Commit B — feat(lab): CP, requirements, compatible stores, selected store draft
    │
    ▼
Commit C — feat(lab): checkout/admin bridge for preferred store
    │
    ▼
Commit A — feat(lab): cart UX redesign Phase 2A
    │
    ▼
Commit E — fix/chore(pharmacy): ShoppingCartLayout spillover (revert or fix copy)
```

---

### Commit B: `feat(laboratory): CP, branch compatibility and selected store architecture`

**Incluir:**

- Migraciones 001–005 (todas)
- `app/Services/LaboratoryRequirements/*` + `app/Support/LaboratoryRequirements/*`
- Models: `LaboratoryStudyRequirement*`, `PostalCodeLocation`, changes `LaboratoryCheckoutDraft`, `LaboratoryCapability`, `LaboratoryTest`
- Controllers: `LaboratoryCompatibleStoresController`, `LaboratoryCheckoutSelectedStoreController`
- Requests, Resource, Services Laboratory, Import command
- `routes/laboratories.php` (solo rutas nuevas B)
- `resources/js/lib/laboratoryCompatibleStores.js`
- Tests B (Feature + Unit + JS compatible stores)

**NO incluir:**

- Componentes A (`LaboratoryCart/*`, layouts carrito)
- Checkout UI (C)
- `ShoppingCartLayout.jsx` (E)

**Dependencias:** ninguna de A/C/E.

**Tests asociados:**

- `tests/Feature/LaboratoryCompatibleStoresEndpointTest.php`
- `tests/Feature/LaboratoryCheckoutSelectedStoreTest.php`
- `tests/Feature/LaboratoryCheckoutSelectedStoreIntegrationTest.php`
- `tests/Feature/PostalCodeLocationResolverTest.php`
- `tests/Feature/PostalCodeLocationsImporterTest.php`
- `tests/Unit/LaboratoryRequirements/*`
- `tests/JavaScript/laboratoryCompatibleStores.test.mjs`

---

### Commit C: `feat(laboratory): preferred store in checkout and admin appointment`

**Incluir:**

- `LaboratoryCheckoutController.php`
- `LaboratoryCheckout.jsx`
- `CheckoutLayout.jsx`
- `ContactStep.jsx`
- `LaboratoryAppointmentController.php`
- `UpdateLaboratoryAppointmentRequest.php`
- `LaboratoryAppointment.jsx` (admin)
- `laboratoryAppointmentRecommendation.js`

**Dependencias:** Commit B mergeado (draft service, lib, routes).

**Tests asociados:**

- `tests/Feature/Admin/LaboratoryAppointmentsPendingTabTest.php` (modified)
- `tests/Feature/Carts/CartPaymentMethodSelectedTest.php` (modified)
- `tests/JavaScript/laboratoryAppointmentRecommendation.test.mjs`

**Decisión humana pendiente:** label wizard "Sucursal" en step address.

---

### Commit A: `feat(laboratory): cart UX redesign Phase 2A`

**Incluir:**

- `LaboratoryShoppingCart.jsx`
- `LaboratoryShoppingCartLayout.jsx`
- `LaboratoryCart/LabStudyCard.jsx`
- `LaboratoryCart/OrderSummary.jsx`
- `LaboratoryCart/StickyCheckoutBar.jsx`
- `LaboratoryCart/BranchBottomSheet.jsx`
- `LaboratoryCompatibleStoresSection.jsx`
- `UX-REDESIGN-LABORATORY-CART-CHECKOUT.md`
- `UX-WIREFRAMES-LABORATORY-CART-CHECKOUT.md`

**Dependencias:** Commit B mergeado (Section llama API/lib).

**NO incluir:** backend B, checkout C, farmacia E.

**Tests asociados:** ninguno exclusivo A; smoke manual carrito + `laboratoryCompatibleStores.test.mjs` ya en B.

---

### Commit E: `fix(pharmacy): revert or correct ShoppingCartLayout spillover`

**Opciones (decisión humana):**

1. **Revert** `ShoppingCartLayout.jsx` a HEAD — farmacia sin cambios.
2. **Commit E fix:** conservar UI compacta pero corregir `"Estudio de laboratorio"` → copy genérico farmacia.

**Dependencias:** independiente; puede ir antes o después de A.

**Recomendación:** **revert** si no hay intención de rediseñar farmacia ahora.

---

### Mapa de dependencias

```
FASE 2A UX (Commit A)
    │
    ├── LaboratoryShoppingCart.jsx
    ├── LaboratoryShoppingCartLayout.jsx
    ├── LaboratoryCart/*
    └── LaboratoryCompatibleStoresSection.jsx
              │
              ▼
    laboratoryCompatibleStores.js (Commit B)
              │
              ▼
    API: compatible-stores + selected-store (Commit B)
              │
              ▼
    BranchResolver + Requirements + PostalCode (Commit B)
              │
              ▼
    Migrations 001–005 (Commit B, unidad atómica)

LAB CART ──Continuar──► CHECKOUT (Commit C)
                              │
                              ▼
                         selectedLaboratoryStore prop
                              │
                              ▼
                         PAYMENT / APPOINTMENT (sin cambios en WT)

ShoppingCartLayout (Commit E) ──► OnlinePharmacyShoppingCart (sin cambios WT)
```

---

## 10. Resumen ejecutivo

### Conteo por grupo

| Grupo | Archivos |
|-------|----------|
| A — Fase 2A UX | 9 |
| B — CP/Sucursales | ~39 productivos + 10 tests |
| C — Checkout/Appointment | 8 |
| D — Tracking/AC | 0 exclusivos (GA4 embebido en A/E) |
| E — Farmacia | 1 |
| F — Tests (C adicionales) | 3 (fuera de tests B) |
| **Total working tree** | 15 modified + 58 untracked |

### Dependencias críticas (no eliminar al separar A)

1. Migraciones 2026_09_17_000001–000005 (unidad)
2. `laboratoryCompatibleStores.js`
3. Controllers + services + routes B
4. `LaboratoryCheckoutDraft` model changes
5. `LaboratoryCompatibleStoresSection.jsx` requiere B en runtime

### Cambios que definitivamente NO pertenecen a Fase 2A

- Todo el bloque B (backend, migrations, lib, routes)
- Todo el bloque C (checkout, admin appointment, ContactStep)
- `ShoppingCartLayout.jsx` (E)
- Tests B y C extendidos

### Cambios que requieren decisión humana

1. `ShoppingCartLayout.jsx` — revert vs commit E con fix copy
2. Wizard label "Sucursal" en `LaboratoryCheckout.jsx` step address
3. `ContactStep` / `CheckoutLayout` — ¿parte de C ahora o esperar 2B?
4. ¿Incluir docs UX en commit A o commit docs separado?

### Estrategia recomendada (sin ejecutar)

1. **Backup:** copiar working tree o branch pointer antes de cualquier separación (`git branch backup/wt-2026-09-17-mixed` — futuro, no ejecutado aquí).
2. **Nueva rama** desde `6efbe086` para cada capa, o **commits selectivos** con `git add -p` / paths explícitos en orden B → C → A → E.
3. **NO usar stash** del working tree completo — 73 paths mezclados, alto riesgo perder untracked.
4. **Validar** tras cada commit: migraciones, tests B, smoke carrito, smoke farmacia.

---

## Operaciones registradas en Fase 2A.3

| Ejecutadas | NO ejecutadas |
|------------|---------------|
| `git status`, `git diff`, `git log`, `git branch`, `git rev-parse`, `git ls-files` | reset, checkout, revert, stash, clean, cherry-pick, commit, add |
| Creación de `FASE-2A-SEPARATION-MANIFEST.md` | Modificación de cualquier otro archivo |

---

*Generado en Fase 2A.3 — preparación segura para separación. Working tree intacto.*
