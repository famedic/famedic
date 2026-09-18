# Laboratory Study Requirements - Phase 1

Phase 1 introduces a conservative resolver that converts a `LaboratoryTest` into operational requirements:

`LaboratoryTest -> StudyRequirementResolver -> ResolvedStudyRequirements -> ResolvedRequirementGroup[] -> ResolvedRequirement[]`

These requirements describe broad operational capabilities, not exact study availability at a specific branch. They are safe inputs for a future branch resolver, but they are not a checkout, appointment, payment, GDA, or store-selection decision by themselves.

## Concepts

- `LaboratoryStudyRequirementGroup`: one logical requirement group for a study. `operator = all` means every requirement in the group is required; `operator = any` means one of the alternatives may satisfy the study.
- `LaboratoryStudyRequirement`: one required operational capability, normally backed by `LaboratoryCapability`.
- `source`: where the requirement came from. The resolver reserves the precedence levels `exact`, `manual`, `package_component`, `rule`, `category`, `approximate`, and `unknown`; Phase 1 actively uses `manual`, `rule`, `category`, and `unknown`.
- `confidence`: resolver confidence. Phase 1 uses `MAPPED`, `CATEGORY`, and `UNKNOWN`.
- `UNKNOWN`: explicit non-resolution state with reasons such as `category_not_mappable`, `service_or_copy`, and `insufficient_evidence`.

## Phase 1 Rules

Manual requirements stored in the new tables take precedence over generated category mappings.

Specific text rules are applied before category rules, including mammography/mastography, Doppler ultrasound, audiometry, electrocardiogram/ECG, spirometry, Pap smear, and densitometry.

Safe categories map only when the category itself is operationally reliable:

- `Sanguíneo`, `Urianálisis`, `Nasofaríngeo`, `Copro`, `Cultivo` -> `laboratorio`
- `Rayos X` -> `rayos_x`, except mammography/mastography text -> `mastografia`
- `Ultrasonido` -> `ultrasonido_convencional OR ultrasonido_especial`, except Doppler text -> `ultrasonido_especial`
- `Resonancia` -> `resonancia_magnetica`, unless the study is a service/copy
- `Tomografía` -> `tomografia`, unless the study is a service/copy

`Especiales`, `Cutánea`, and `Chequeos y Paquetes` are not generically mapped. Package `feature_list` is intentionally documented but not parsed in Phase 1.

## Phase 2 Package Parsing

`PackageRequirementParser` handles package `feature_list` arrays without consulting branches, stores, appointments, checkout, GDA, or location data.

The parser keeps each package component as a `ParsedPackageComponent` with original text, normalized text, index, confidence, inferred category, evidence, and unresolved reason when present.

Package components are converted into in-memory `LaboratoryTest` instances and passed back through `StudyRequirementResolver`. This keeps component rules such as Doppler ultrasound, mammography/mastography, and Papanicolaou in one place.

Package output uses:

- `source = package_component`
- one logical group per distinct requirement set
- `operator = any` preserved for ultrasound alternatives
- deduplicated repeated capabilities with all component provenance retained

If a package has known components and unknown components, known requirements are still returned, but the package-level confidence becomes `UNKNOWN` and `unresolved_reasons` keeps the unresolved component visible. A future branch resolver should treat that state as not safe for automatic branch recommendation.

## Phase 3 Cart Aggregation

`CartRequirementAggregator` is the boundary between studies and cart-level operational requirements. It does not match stores, choose branches, create appointments, or touch checkout.

Primary APIs:

- `aggregateResolved(Collection $resolvedStudies, ?string $cartId = null)`: core API for callers that already have `ResolvedStudyRequirements`.
- `aggregateStudies(Collection $studies, ?string $cartId = null)`: resolves `LaboratoryTest` models and aggregates them.
- `aggregateItems(Collection $items, ?string $cartId = null)`: accepts `LaboratoryCartItem` models and preserves item-level provenance.

`CartRequirements` contains:

- `cartId`
- `studies`
- `groups`
- `requirements`
- `confidence`
- `isResolvable`
- `unresolvedReasons`
- `brands`
- `evidence`

Aggregation semantics are intentionally conservative:

- Cart groups are an implicit `ALL`: every group must be satisfied later by a branch resolver.
- Requirements inside a group preserve the original group operator, including `ANY` alternatives.
- Groups are merged only when they have the same operator and the exact same sorted capability slugs.
- Overlapping alternatives such as `ANY(A, B)` and `ANY(B, C)` remain independent groups.
- Flat `requirements` are deduplicated for reporting/provenance, but `groups` remain the authoritative compatibility contract.
- Unknown study/package components keep known requirements visible while setting `isResolvable = false`.
- Multibrand carts expose `brands` so a future branch resolver can evaluate each brand boundary explicitly.
- Quantity is provenance only in Phase 3; operational capability requirements are deduplicated.

## Phase 4 Branch Resolution

`BranchResolver` answers whether existing `LaboratoryStore` records satisfy the known operational requirements in `CartRequirements`. It does not assert exact study availability and does not consult GDA, appointments, slots, checkout, payment, or UI state.

API:

`resolve(CartRequirements $requirements, ?GeoPoint $location = null, ?CarbonInterface $date = null): BranchResolution`

Resolution flow:

1. Split requirements by brand using `CartRequirements->brands`.
2. Query active stores for each brand.
3. Eager-load store capabilities and hours.
4. Evaluate every cart group as required.
5. Respect `ANY` within a group and the implicit `ALL` across groups.
6. Mark a branch compatible only when all groups are satisfied and the cart is resolvable.
7. Preserve matched and missing capabilities.
8. Calculate distance only when a user `GeoPoint` is provided.
9. Read hours without claiming appointment availability; agenda availability remains `unknown`.
10. Rank compatible branches first, then by evidence level, satisfied group count, distance, hours, and name.

Unknown or partially unresolved carts can still produce matched capability evidence, but branches are not marked fully compatible. This keeps future UI/API flows from presenting an uncertain cart as safe for automatic branch selection.

## Phase 5A Read-Only Endpoint

Endpoint:

`GET /laboratory/cart/compatible-stores`

Route name:

`laboratory.cart.compatible-stores`

Authentication:

The route lives inside the protected laboratory route group. It uses the authenticated user's current customer and reads only `customer->laboratoryCartItems`; the frontend cannot submit `laboratory_test_id[]`, capabilities, or brand as source of truth.

Allowed query parameters:

- `latitude`: optional numeric, between -90 and 90. Must be sent with `longitude`.
- `longitude`: optional numeric, between -180 and 180. Must be sent with `latitude`.
- `date`: optional `Y-m-d`, used only for hours evaluation.

Response shape:

```json
{
  "success": true,
  "data": {
    "resolution_status": "resolved",
    "is_resolvable": true,
    "confidence": "CATEGORY",
    "unresolved_reasons": [],
    "brands": {},
    "branches": [],
    "compatible_branches_count": 0,
    "reasons": [],
    "meta": {
      "cart_items_count": 0,
      "appointment_availability": "unknown",
      "availability_scope": "operational_capability_only"
    }
  }
}
```

Empty carts return `resolution_status = empty_cart` and no branches.

Unknown carts return `resolution_status = unknown`, keep known matched/missing evidence, and never mark a branch as fully compatible.

Multibrand carts keep brand sections under `data.brands`; future UI should present those sections independently instead of mixing branches from different laboratory brands.
