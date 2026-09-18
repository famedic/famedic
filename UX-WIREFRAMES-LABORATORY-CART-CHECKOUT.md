# Wireframes funcionales + Especificación visual
## Carrito + Checkout de Laboratorios Famedic

**Fase:** 1.5 — Documentación (sin implementación)  
**Fecha:** 2026-09-17  
**Fuentes:** Auditoría técnica · `UX-REDESIGN-LABORATORY-CART-CHECKOUT.md`  
**Regla:** Cero cambios de código, backend, eventos ni integraciones

---

# PARTE 1 — Inventario de pantallas

## 1.1 Pantallas vs estados

Varias “pantallas” son **estados de la misma ruta Inertia**, no rutas nuevas.

| ID | Pantalla | Ruta / contenedor | ¿Pantalla independiente? |
|----|----------|-------------------|--------------------------|
| **A** | Cart Desktop | `GET laboratory.shopping-cart` | Sí — layout ≥1024px |
| **B** | Cart Mobile | Misma ruta | Sí — layout <768px |
| **C** | Branches Desktop | Sección dentro de **A** (expand) | **Estado** de A, no ruta |
| **D** | Branches Mobile Sheet | Bottom sheet sobre **B** | **Estado** de B |
| **E** | Checkout Patient Desktop | `GET laboratory.checkout?step=patient` | Sí — paso wizard |
| **F** | Checkout Patient Mobile | Misma URL | Sí — layout mobile |
| **G** | Checkout Address Desktop | `?step=address` | Sí |
| **H** | Checkout Address Mobile | Misma URL | Sí |
| **I** | Checkout Payment Desktop | `?step=payment` | Sí |
| **J** | Checkout Payment Mobile | Misma URL | Sí |
| **K** | Checkout Appointment Desktop | `?step=appointment` | Sí — **solo si aplica** |
| **L** | Checkout Appointment Mobile | Misma URL | Sí — condicional |
| **M** | Checkout Confirmation Desktop | `?step=confirmation` | Sí |
| **N** | Checkout Confirmation Mobile | Misma URL | Sí |

## 1.2 Variantes de flujo (sin pasos artificiales)

| Flujo | Pasos visibles | Condición |
|-------|----------------|-----------|
| Estándar sin cita | patient → address → payment → confirmation | Sin `requires_appointment` |
| Estándar con cita | patient → address → payment → appointment → confirmation | Algún estudio requiere cita |
| Appointment-first | patient → address → appointment → payment* | `usesAppointmentFirstFlow` |

\* En appointment-first, paso `payment` incluye confirmación embebida (comportamiento actual).

## 1.3 Pantallas auxiliares (estados modales / sheets)

| ID | Nombre | Padre |
|----|--------|-------|
| D1 | Branch sheet — loading | B |
| D2 | Branch sheet — ready | B |
| D3 | Branch sheet — empty/error | B |
| N1 | Checkout summary sheet | F–N mobile |
| E1 | New patient form expand | E/F |
| G1 | New address form expand | G/H |
| C1 | Map panel overlay | C — **PROPUESTA — REQUIERE VALIDACIÓN** si no hay mapa hoy en carrito |

## 1.4 Total inventario

| Tipo | Cantidad |
|------|----------|
| Rutas base | 2 (`shopping-cart`, `checkout`) |
| Layouts distintos | 14 (A–N) |
| Estados modales/sheets | 7+ |
| Variantes flujo checkout | 3 |

---

# PARTE 2 — Wireframe Cart Desktop

## 2.1 Grid y anchos relativos

```
Viewport ≥ 1024px
Max content width: 1200px (centrado, alineado FamedicLayout existente)

┌────────────────────────────────────────────────────────────────────────┐
│ NAV BAR (100%) — FamedicLayout/NavBar                                  │
├──────────┬─────────────────────────────────────────────────────────────┤
│ SIDEBAR  │ MAIN CONTENT AREA                                           │
│ ~240px   │ ~calc(100% - 240px)                                         │
│ (exist.) │                                                             │
│          │  ┌─────────────────────────────┬──────────────────────────┐ │
│          │  │ PRIMARY COLUMN              │ SUMMARY COLUMN           │ │
│          │  │ flex: 1  (~66%)             │ width: 360px (~34%)      │ │
│          │  │ max-width: 720px            │ position: sticky         │ │
│          │  │                             │ top: 24px                │ │
│          │  └─────────────────────────────┴──────────────────────────┘ │
└──────────┴─────────────────────────────────────────────────────────────┘
```

## 2.2 Wireframe — estado normal (con estudios)

```
┌────────────────────────────────────────────────────────────────────────┐
│ [NavBar: Famedic logo · Carrito · Cuenta]                               │
├────────────────────────────────────────────────────────────────────────┤
│                                                                        │
│  [LaboratoryBrandCard — logo marca, ~48px alto]                        │
│                                                                        │
│  Carrito de laboratorio                          ← H1, navy, 28px      │
│  Revisa tus estudios antes de continuar.         ← sub, gray-600, 16px │
│                                                                        │
│  ┌─ ZONA A: ESTUDIOS (66%) ─────────────────┐ ┌─ ZONA C: RESUMEN ────┐ │
│  │                                          │ │ ┌ Card sticky ──────┐ │ │
│  │  Estudios en tu carrito    (2)           │ │ │ Subtotal    $X,XXX│ │ │
│  │  ─────────────────────────────────────   │ │ │ Descuento  −$XXX  │ │ │
│  │  ┌ LabStudyCard ──────────────────────┐  │ │ │ ────────────────  │ │ │
│  │  │ Perfil tiroideo completo           │  │ │ │ Total      $X,XXX│ │ │
│  │  │ [Requiere cita]          $1,049.29  │  │ │ │      MXN         │ │ │
│  │  │                          [Quitar]  │  │ │ │                  │ │ │
│  │  └────────────────────────────────────┘  │ │ │ [BalanceCredit   │ │ │
│  │  ┌ LabStudyCard ──────────────────────┐  │ │ │  si aplica]      │ │ │
│  │  │ Química sanguínea 20 elementos     │  │ │ │                  │ │ │
│  │  │                          $1,861.29 │  │ │ │ ┌──────────────┐ │ │ │
│  │  │                          [Quitar]  │  │ │ │ │  Continuar   │ │ │ │
│  │  └────────────────────────────────────┘  │ │ │ └──────────────┘ │ │ │
│  │                                          │ │ │   primary, full  │ │ │
│  │  + Agregar más estudios    ← link sec.   │ │ └──────────────────┘ │ │
│  │                                          │ └──────────────────────┘ │
│  │  ┌─ Aviso cita (condicional) ──────────┐  │                          │
│  │  │ ℹ Algunos estudios requieren cita.  │  │                          │
│  │  │   La coordinamos después de pagar.  │  │                          │
│  │  └─────────────────────────────────────┘  │                          │
│  │                                          │                          │
│  │  ┌─ ZONA B: SUCURSALES (colapsada) ────┐  │                          │
│  │  │ 📍 Encontrar una sucursal compatible │  │                          │
│  │  │    Opcional · Por código postal   [▼]│  │                          │
│  │  └─────────────────────────────────────┘  │                          │
│  └──────────────────────────────────────────┘                          │
│                                                                        │
└────────────────────────────────────────────────────────────────────────┘
```

## 2.3 Zona B expandida (→ Pantalla C)

Al click `[▼]` la sección crece in-place; **Zona C permanece sticky**. Ver Parte 5.

## 2.4 Wireframe — carrito vacío

```
┌────────────────────────────────────────────────────────────────────────┐
│  Carrito de laboratorio                                                │
│  Aún no tienes estudios en tu carrito.                                 │
│                                                                        │
│  ┌──────────────────────────────┐  ┌──────────────────────────────┐   │
│  │     [ilustración mínima]     │  │ RESUMEN                      │   │
│  │                              │  │ Total            $0.00       │   │
│  │  Tu carrito está vacío       │  │                              │   │
│  │                              │  │ [Continuar]  DISABLED        │   │
│  │  [Explorar estudios] primary │  │                              │   │
│  └──────────────────────────────┘  └──────────────────────────────┘   │
│                                                                        │
│  (Zona B sucursales: OCULTA)                                          │
└────────────────────────────────────────────────────────────────────────┘
```

## 2.5 Jerarquía y orden (above the fold)

| Orden | Elemento | Prioridad |
|-------|----------|-----------|
| 1 | Título + subtítulo | Alta |
| 2 | Lista estudios | Alta |
| 3 | Resumen + Continuar (sticky) | Alta — visible sin scroll en ≤3 items |
| 4 | + Agregar más estudios | Media |
| 5 | Aviso cita | Media — solo si `requires_appointment` |
| 6 | Entry sucursales (colapsado) | Baja |
| 7 | FeaturesGrid | **Eliminado del carrito** — PROPUESTA: link footer |

## 2.6 CTAs

| CTA | Tipo | Ubicación | Handler existente |
|-----|------|-----------|-------------------|
| **Continuar** | Primary | Resumen sticky | `handleCheckoutClick` → GA4 `begin_checkout` → `laboratory.checkout?step=patient` |
| + Agregar más estudios | Secondary link | Bajo lista | `href={route('laboratory-tests')}` |
| Quitar | Tertiary / icon | Por card | `setLaboratoryCartItemToDelete` → modal → DELETE destroy |
| Explorar estudios | Primary | Solo empty | Link catálogo + GA4 `select_item` |

## 2.7 Sticky behavior desktop

- **Resumen (Zona C):** `position: sticky; top: 24px; align-self: start`
- Scroll: columna izquierda scroll independiente; resumen fijo hasta fin del contenedor padre
- Carrito largo (>6 items): resumen siempre visible; estudios scroll en columna izquierda

## 2.8 Breakpoint tablet (768–1023)

- Grid 60/40 o stack con resumen sticky bottom — **PROPUESTA — REQUIERE VALIDACIÓN** entre sticky sidebar vs bottom bar en tablet
- Recomendación: mantener sidebar sticky hasta 768px; bajo 768 → layout mobile (Parte 6)

---

# PARTE 3 — Estudio Card (LabStudyCard)

## 3.1 Datos disponibles (no inventar)

**Desde controller (`LaboratoryShoppingCartController` + totales):**
- `formattedTotal`, `formattedSubtotal`, `formattedDiscount` — a nivel página

**Desde shared `laboratoryCarts[brand]` (`HandleInertiaRequests`):**
- `id` (cart item id)
- `laboratory_test.id`
- `laboratory_test.name`
- `laboratory_test.requires_appointment` (bool)
- `laboratory_test.famedic_price_cents`
- `laboratory_test.formatted_famedic_price`

**No garantizado en payload actual:** `description`, `category`, `public_price` por item en shared — mostrar solo si presente en props.

## 3.2 Wireframe LabStudyCard

```
┌─────────────────────────────────────────────────────────────┐
│  Perfil tiroideo completo                    $1,049.29 MXN  │  ← row 1: nombre + precio
│  [ Badge: Requiere cita ]                      [Quitar ↗]   │  ← row 2: badge cond. + acción
│  ▸ Ver detalles                                             │  ← row 3: colapsado default
└─────────────────────────────────────────────────────────────┘

Expandido (▾ Ver detalles):
│  Precio público: $1,299.00  ·  Ahorras $249.71              │  ← SOLO si public_price disponible
│  (Sin más campos si backend no los envía)                     │
```

## 3.3 Visible vs secundario

| Campo | Visible inicial | Secundario | Fuente |
|-------|-----------------|------------|--------|
| Nombre | ✓ | — | `laboratory_test.name` |
| Precio Famedic | ✓ | — | `formatted_famedic_price` |
| Badge "Requiere cita" | ✓ si true | — | `requires_appointment` |
| Eliminar | ✓ | — | UI → modal existente |
| Descuento por ítem | — | ✓ expand | Solo si hay public vs famedic en props |
| Tipo/categoría | — | ✓ expand | Solo si prop existe |
| Requisitos operacionales | — | **No mostrar** | No vienen en cart item — evitar inventar |

## 3.4 Handlers a preservar

| Acción | Handler | Endpoint |
|--------|---------|----------|
| Quitar | `onDestroy` → `useDeleteLaboratoryCartItem` | DELETE `laboratory-cart-items.destroy` |
| GA4 remove | `handleItemRemove` antes de modal | `remove_from_cart` dataLayer |

## 3.5 Dimensiones conceptuales

- Altura mínima card: 72px (colapsada)
- Padding: 16px
- Gap entre cards: 12px
- Precio: alineado derecha, semibold
- Quitar: texto o icono 44×44 touch target

---

# PARTE 4 — Resumen del carrito (OrderSummary)

## 4.1 Wireframe desktop (sticky)

```
┌─────────────────────────────┐
│  Resumen de compra          │  ← Subheading, 14px uppercase optional
│                             │
│  Subtotal        $3,160.00  │  ← dl row, gray-600 / gray-900
│  Descuento        −$249.42  │  ← ocultar fila si descuento = 0
│  ─────────────────────────  │
│  Total           $2,910.58  │  ← 24px semibold navy
│              MXN            │
│                             │
│  ┌─ BalanceCreditCard ────┐ │  ← variant="cart" si props
│  │ Crédito disponible...   │ │
│  └─────────────────────────┘ │
│                             │
│  ┌─────────────────────────┐│
│  │      Continuar          ││  ← Button primary, h-12, full width
│  └─────────────────────────┘│
└─────────────────────────────┘
```

## 4.2 Wireframe mobile (StickyCheckoutBar)

```
┌─────────────────────────────────────┐
│  Total                    $2,910.58 │  ← fila superior, padding 12px 16px
│  ┌─────────────────────────────────┐│
│  │         Continuar  →            ││  ← h-48px min, full width
│  └─────────────────────────────────┘│
└─────────────────────────────────────┘
fixed bottom: 0; z-index: 40; shadow-top; safe-area-inset-bottom
```

## 4.3 Comportamiento scroll

| Escenario | Desktop | Mobile |
|-----------|---------|--------|
| ≤3 estudios | Resumen visible sin scroll | Barra siempre visible |
| 4+ estudios | Sticky sidebar | Contenido scroll detrás de barra; padding-bottom = bar height + 16px |
| CP/sucursales expandido | Sidebar no se mueve | Sheet no afecta barra del carrito base |

## 4.4 CTA secundaria "Agregar más estudios"

- **Ubicación:** solo en columna estudios (Zona A), **no** duplicar en resumen
- Link text + icon `+`
- Ruta: `laboratory-tests` con brand context

## 4.5 Cálculos — no modificar

- Totales: `CalculateTotalsAndDiscountAction` vía props controller
- Frontend solo muestra strings formateados

---

# PARTE 5 — Sucursales Desktop (Branches)

## 5.1 Contenedor expandido (Zona B)

```
┌────────────────────────────────────────────────────────────────────────┐
│ 📍 Encontrar una sucursal compatible                              [▲] │
│    Opcional. Te mostramos lugares compatibles con tus estudios.        │
├────────────────────────────────────────────────────────────────────────┤
│                                                                        │
│  Código postal (opcional)                                              │
│  ┌──────────────────────┐  ┌────────┐  ┌─────────┐                    │
│  │ 64000                │  │ Buscar │  │ Limpiar │                    │
│  └──────────────────────┘  └────────┘  └─────────┘                    │
│  Te ayuda a ordenar por cercanía.                                      │
│                                                                        │
│  ┌──────────┬─────────┬──────────┬────────────┐                       │
│  │Recomend. │  Todas  │ Filtros ▾│ Ver mapa   │  ← tab bar            │
│  └──────────┴─────────┴──────────┴────────────┘                       │
│                                                                        │
│  ══ TAB: RECOMENDADAS ══════════════════════════════════════════    │
│                                                                        │
│  ⭐ Recomendada para ti                                                │
│  ┌─ BranchRecommendationCard ─────────────────────────────────────┐ │
│  │  Laboratorio Vasconcelos                              2.3 km     │ │
│  │  Av. Vasconcelos 400, San Pedro Garza García, NL                 │ │
│  │  🕐 Lun–Vie 7:00–19:00  ·  Horario referencial                   │ │
│  │  ✓ Compatible con tus estudios                                   │ │
│  │  ┌────────────┐                    ┌──────────────────────────┐ │ │
│  │  │ Ver mapa   │                    │   Seleccionar sucursal     │ │ │
│  │  └────────────┘                    └──────────────────────────┘ │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                        │
│  Otras opciones cerca de ti                                            │
│  ┌─ BranchCompactCard ──┐  ┌─ BranchCompactCard ──┐                   │
│  │ Sucursal 2    3.1 km │  │ Sucursal 3    4.0 km │                   │
│  │ [Seleccionar]        │  │ [Seleccionar]        │                   │
│  └──────────────────────┘  └──────────────────────┘                   │
│                                                                        │
│  Más sucursales por zona                                               │
│  ▸ Monterrey (4 sucursales)                                            │
│  ▸ San Pedro Garza García (2)                                          │
│  ▸ Guadalupe (3)                                                       │
│  [ Ver todas las zonas ]                                               │
│                                                                        │
│  ┌─ PreferredStoreChip (si seleccionada) ──────────────────────────┐  │
│  │ ✓ Sucursal preferida: Vasconcelos · San Pedro    [Cambiar] [✕]  │  │
│  │   La cita y horario se confirman contigo después.               │  │
│  └─────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

## 5.2 Tab "Todas"

- Lista vertical de `BranchCompactCard` para cada `isCompatible === true`
- Orden: API (distancia → match level → horario) — no reordenar client-side

## 5.3 Tab "Filtros"

```
┌────────────────────────────────────────┐
│  Buscar sucursal                       │
│  ┌──────────────────────────────────┐  │
│  │ 🔍  Nombre, colonia o municipio  │  │
│  └──────────────────────────────────┘  │
│  (Filtra client-side: filterBranchesBySearch)
└────────────────────────────────────────┘
```

## 5.4 Tab "Ver mapa" — PROPUESTA — REQUIERE VALIDACIÓN

**Estado actual:** carrito no tiene mapa Leaflet integrado (existe en admin).

**Wireframe propuesto si se valida:**

```
┌──────────────────────────────┬─────────────────────────┐
│ Lista (40%)                  │ Mapa Leaflet (60%)      │
│ [cards scroll]               │ pins solo branches con  │
│                              │ lat/lng del API         │
└──────────────────────────────┴─────────────────────────┘
```

Si no se valida en v1: tab oculto o disabled con tooltip "Próximamente".

## 5.5 BranchRecommendationCard — campos API

| UI | Campo API | Notas |
|----|-----------|-------|
| Nombre | `branch.name` | |
| Dirección | `branch.address` | |
| Municipio | `branch.municipality` / `city` | |
| Distancia | `branch.distanceKm` | Solo si numérico; `formatDistance()` |
| Horario | `branch.hours.hoursSummary` | Label "Horario referencial" |
| Compatible | `branch.isCompatible` | Copy "Compatible con tus estudios" |
| Requisitos matched | `matchedRequirements` | Accordion "Qué cubre" — tap |
| Requisitos missing | `missingRequirements` | Solo si incompatible — no en recomendada |

## 5.6 Acciones

| Botón | Handler | Endpoint |
|-------|---------|----------|
| Buscar CP | `handlePostalCodeSubmit` | GET compatible-stores + persist |
| Limpiar | `handlePostalCodeClear` | GET + `clear_postal_code=1` |
| Seleccionar | `handleSelect(branch)` | POST `{ laboratory_store_id }` |
| Seleccionada (toggle) | mismo handler | DELETE selected-store |
| Cambiar preferida | scroll/focus card list | — |
| ✕ quitar preferida | DELETE | destroy |

---

# PARTE 6 — Sucursales Mobile (Bottom Sheet)

## 6.1 Trigger

Entry en carrito mobile:

```
┌─────────────────────────────────────┐
│ 📍  Encontrar sucursal compatible  │
│     Opcional                     →  │
└─────────────────────────────────────┘
```

Tap → abre `BranchBottomSheet`.

## 6.2 Wireframe sheet — altura y estructura

```
┌─────────────────────────────────────┐
│           ───  (drag handle)        │  ← 32px, centered pill
│  Encuentra una sucursal          ✕  │  ← header 56px
│  Compatible con tus estudios        │  ← subtitle 14px gray
├─────────────────────────────────────┤
│  Código postal (opcional)           │
│  [ 64000          ] [Buscar]        │
│  [ Limpiar ]                        │
├─────────────────────────────────────┤
│  ⭐ Recomendada                     │
│  ┌─────────────────────────────────┐│
│  │ Vasconcelos            2.3 km   ││
│  │ San Pedro · horario ref.        ││
│  │ [Seleccionar sucursal]          ││
│  └─────────────────────────────────┘│
│                                     │
│  [ Ver más sucursales ▾ ]           │  ← expande lista in-sheet
│                                     │
│  ── expandido ──                    │
│  [Recomendadas|Todas]  segmented    │
│  ▸ Monterrey (4)                    │
│  ▸ San Pedro (2)                    │
│                                     │
│  ┌─ sticky in sheet ──────────────┐ │
│  │ ✓ Preferida: Vasconcelos       │ │
│  └────────────────────────────────┘ │
└─────────────────────────────────────┘

Altura inicial: 55vh (snap)
Altura expandida: 90vh max
Scroll: contenido interno; header fijo
```

## 6.3 Comportamiento muchas sucursales

- Recomendada + 2 alternativas above fold
- "Ver más" → expande a 90vh + accordion municipios
- Virtualización **PROPUESTA — REQUIERE VALIDACIÓN** solo si perf issues — no requerida v1

## 6.4 Sin resultados

```
┌─────────────────────────────────────┐
│  No encontramos sucursales            │
│  compatibles con tus estudios.        │
│                                       │
│  Puedes continuar sin elegir una.     │
│                                       │
│  [ Cerrar ]                           │
└─────────────────────────────────────┘
```

## 6.5 Selección + cierre

- Al seleccionar: toast inline "Preferencia guardada" 2s
- Sheet puede permanecer abierta o auto-cerrar — **DECISIÓN RECOMENDADA:** permanece abierta con chip preferida actualizado; usuario cierra con ✕

## 6.6 Sticky en sheet

- Footer in-sheet sticky cuando hay preferida seleccionada
- **No** bloquea barra "Continuar" del carrito detrás (sheet overlay z-index 50)

---

# PARTE 7 — Estados del código postal

## 7.1 CP vacío (missing)

```
[                    ] [Buscar]
Opcional. Te ayuda a mostrar sucursales cercanas.

(Sin mensaje de error)
(Lista: compatible sin orden por distancia)
```

## 7.2 CP válido / resuelto (resolved)

```
[ 64000 ✓            ] [Buscar] [Limpiar]

Encontramos sucursales compatibles cerca de ti.

⭐ Recomendada — con distanceKm en card
```

## 7.3 CP no localizado (unresolved)

```
[ 99999              ] [Buscar] [Limpiar]

⚠ Aún no tenemos ubicación para este código postal.
  Mostramos sucursales compatibles sin ordenar por distancia.

(Sin bloquear Continuar en carrito)
```

## 7.4 CP inválido (validación client)

```
[ 640                ] [Buscar]
❌ Ingresa un código postal mexicano de 5 dígitos.

(Border input: red; no dispara GET hasta válido)
```

## 7.5 Loading

```
[ 64000              ] [···]
[Skeleton card × 1]
[Skeleton line × 2]
aria-busy="true"
```

## 7.6 Clear CP

```
Tap [Limpiar]:
- Input vacío
- clearPostalCodeOnNextLoadRef = true
- GET con clear_postal_code=1
- Copy vuelve a estado missing
- Preferencia sucursal: NO se borra automáticamente (comportamiento actual)
```

---

# PARTE 8 — Sucursal recomendada

## 8.1 Wireframe badge + card

```
⭐ Recomendada para ti

┌─────────────────────────────────────────┐
│ ★  Laboratorio Vasconcelos       2.3 km │  ← estrella solo visual
│    San Pedro Garza García               │
│    ...                                  │
└─────────────────────────────────────────┘
```

## 8.2 Copy obligatorio (tooltip o línea secundaria)

**Ubicación recomendada:** línea 12px bajo título sección, gray-500:

> Ordenada por compatibilidad con tus estudios y cercanía. No reserva cita.

## 8.3 Qué NO mostrar

- ❌ "Disponible"
- ❌ "Reservada"
- ❌ "Confirmada"
- ❌ ETA / tiempo de espera
- ❌ Rating / estrellas de reseña (solo icono "recomendada" decorativo)

## 8.4 Lógica preservada

- `nearestCompatibleBranch(branches)` — primera con `distanceKm` finito
- Sin CP: primera compatible del API order (sin badge distancia)

---

# PARTE 9 — Sucursal preferida

## 9.1 Wireframe post-selección

```
┌─────────────────────────────────────────────────────────┐
│ ✓  Sucursal preferida                                   │
│    Vasconcelos                                          │
│    San Pedro Garza García                               │
│    La cita y horario se confirman contigo después.      │  ← secondary text
│                                    [Cambiar]  [Quitar]  │
└─────────────────────────────────────────────────────────┘
```

## 9.2 Decisión microcopy — justificación

| Opción | Evaluación | Decisión |
|--------|------------|----------|
| Visible siempre | Clara, evita confusión legal/expectativa | **✓ Elegida** — 1 línea secondary text |
| Tooltip | Oculta info crítica; mala en mobile | Rechazada |
| Solo accordion | Usuario puede no verla | Rechazada |
| Sin texto | Riesgo alto confundir con cita | Rechazada |

**Formato:** `text-sm text-gray-500` bajo nombre sucursal — no banner, no modal.

## 9.3 Estados preferida

| Estado | Visual |
|--------|--------|
| valid | Borde green-200 bg-green-50 suave |
| stale | Amber + "Actualiza tu preferencia" |
| invalid | Amber + "Elige otra sucursal" |

## 9.4 Handlers

- POST/DELETE selected-store — sin cambios
- `checkoutSelectedStorePresentation()` — fuente copy checkout

---

# PARTE 10 — Checkout Stepper

## 10.1 Desktop wireframe

```
Flujo 4 pasos (sin cita):
┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐
│ ✓ Para   │───│ ● Tu     │───│ ○ Pago   │───│ ○ Confirmar│
│   quién  │    │  direcc. │    │          │    │           │
└──────────┘    └──────────┘    └──────────┘    └──────────┘

Flujo 5 pasos (con cita):
... ─── │ ○ Cita │ ─── │ ○ Confirmar │

IDs internos (NO renombrar):
patient | address | payment | appointment | confirmation
```

## 10.2 Mobile wireframe

```
Paso 2 de 4                                    ← text right optional

● ○ ○ ○                                        ← dots, 8px gap

¿Cuál es tu dirección?                         ← H1, 22px

Selecciona o agrega la dirección del paciente. ← subtitle
```

## 10.3 Estados step

| Estado | Desktop | Mobile |
|--------|---------|--------|
| **completed** | ✓ círculo filled navy | dot filled |
| **active** | ● círculo outline grueso | dot large |
| **pending** | ○ gris | dot small gray |
| **disabled** | ○ gris 40% opacity, no click | idem |
| **error** | borde rojo en step activo + mensaje bajo | banner error |

## 10.4 Appointment-first

```
● Para quién → ○ Tu dirección → ○ Cita → ○ Pago y confirmar

Label último paso: "Pago y confirmar" (UI only)
ID sigue siendo: payment
```

## 10.5 Flujo sin cita

- Step `appointment` **no renderizado** en stepper
- Numeración mobile ajustada: "Paso X de 4"

---

# PARTE 11 — Checkout Patient (E / F)

## 11.1 Desktop wireframe

```
┌─ MAIN (66%) ────────────────────────┐ ┌─ SIDEBAR ─────────┐
│                                      │ │ 🧪 2 estudios     │
│  ¿Para quién son los estudios?       │ │ $2,910.58         │
│  Selecciona al paciente que          │ │ [Ver detalles ▾]  │
│  realizará los estudios.             │ │                   │
│                                      │ │ [ Continuar ]     │
│  ┌─ CheckoutStepCard (selected) ───┐ │ └───────────────────┘
│  │ ● María López García            │ │
│  │   Femenino · 12/05/1985         │ │
│  │   ··· · ·324                    │ │
│  └─────────────────────────────────┘ │
│  ┌─ CheckoutStepCard ──────────────┐ │
│  │ ○ Juan Pérez López              │ │
│  └─────────────────────────────────┘ │
│                                      │
│  + Agregar nuevo paciente            │
│                                      │
│  ┌─ ContactForm (expanded) ────────┐ │  ← si showContactForm
│  │  [campos existentes]            │ │
│  │  [Guardar paciente]             │ │
│  └─────────────────────────────────┘ │
└──────────────────────────────────────┘

Footer flotante (opcional backup): [← Volver]  [Continuar →]
```

## 11.2 Mobile wireframe

```
← Checkout

● ○ ○ ○
Paso 1 de 4

¿Para quién son los estudios?

┌─────────────────────────────────┐
│ ● María López García            │
│   Femenino · 12/05/1985         │
└─────────────────────────────────┘
┌─────────────────────────────────┐
│ ○ Juan Pérez López              │
└─────────────────────────────────┘

+ Agregar nuevo paciente

┌─ sticky bottom ─────────────────┐
│ Total $2,910.58  [Continuar →]  │
└─────────────────────────────────┘
```

## 11.3 Card seleccionada

- Border 2px navy + bg blue-50/5%
- Radio `●` filled
- `aria-checked="true"`

## 11.4 Error

```
❌ Selecciona un paciente para continuar.
(banner sobre cards, errors.contact de Inertia)
```

## 11.5 CTA Continuar

- Disabled si `!data.contact`
- Click → `syncCheckoutDraft({ step: 'patient', contact_id })` → avanza índice

## 11.6 Endpoints preservados

- POST `checkout.contacts.store` (axios, ContactForm)
- POST `laboratory.checkout.draft.sync`

---

# PARTE 12 — Checkout Address (G / H)

## 12.1 Decisión título

| Opción | Pros | Contras |
|--------|------|---------|
| "Tu dirección" | Corto en stepper | Menos orientador |
| "¿Cuál es tu dirección?" | Pregunta clara, alinea principio "una decisión" | Más largo en stepper |

**DECISIÓN RECOMENDADA:**
- **Stepper label:** "Tu dirección"
- **H1 del paso:** "¿Cuál es tu dirección?"
- **Subtitle:** "La usamos para tu expediente y para contactarte sobre tus resultados."

## 12.2 Desktop wireframe

```
¿Cuál es tu dirección?
La usamos para tu expediente y para contactarte sobre tus resultados.

┌─ CheckoutStepCard (selected) ─────────────────────────────┐
│ ● Av. Constitución 100, Col. Centro                     │
│   Monterrey, Nuevo León · CP 64000                       │
└──────────────────────────────────────────────────────────┘

+ Agregar nueva dirección

┌─ PreferredStoreChip (condicional, compact) ──────────────┐
│ 📍 Preferencia: Vasconcelos · San Pedro    [Cambiar ↗]   │
│    No reserva cita.                                       │
└──────────────────────────────────────────────────────────┘
   [Cambiar ↗] → link `laboratory.shopping-cart#sucursales` o scroll carrito
```

## 12.3 Mobile

- Igual estructura; chip preferida entre lista y sticky bar
- Form nueva dirección: **full-screen sheet** sobre paso

## 12.4 Error

```
❌ Selecciona una dirección para continuar.
errors.address
```

## 12.5 Regla

- **Nunca** label "Sucursal" en este paso
- **Nunca** selector de `laboratory_store_id` aquí

---

# PARTE 13 — Ubicación visual sucursal preferida en checkout

## 13.1 Opciones evaluadas

| Opción | Descripción | Fricción | Riesgo confusión |
|--------|-------------|----------|------------------|
| A | Solo resumen sidebar | Baja | Media — usuario no la ve en paso dirección |
| B | Sección opcional dedicada | Alta — parece paso obligatorio | Baja |
| C | Solo carrito | Media — lejos en checkout | Baja |
| **D** | **A + chip en paso dirección** | **Baja** | **Baja** |

## 13.2 DECISIÓN RECOMENDADA: D (combinación A + chip)

**Por qué:**
1. Checkout no gana un paso artificial — respeta auditoría
2. Chip en dirección aclara que dirección ≠ sucursal lab sin mezclar formularios
3. Sidebar mantiene visibilidad del total + preferencia en todos los pasos
4. Cambiar preferencia redirige al carrito (contrato existente) — sin nuevo endpoint

**No es paso obligatorio.** Usuario puede completar checkout sin preferencia.

---

# PARTE 14 — Checkout Payment (I / J)

## 14.1 Desktop wireframe

```
¿Cómo quieres pagar?
Elige un método para completar tu compra.

┌─ CheckoutPaymentCard (selected) ────────────────────────┐
│ ● Visa ···· 4242                                        │
│   Vence 12/28                                           │
└─────────────────────────────────────────────────────────┘
┌─ CheckoutPaymentCard ───────────────────────────────────┐
│ ○ PayPal                                                │
└─────────────────────────────────────────────────────────┘
┌─ CheckoutPaymentCard ───────────────────────────────────┐
│ ○ Caja de ahorro Odessa                                 │
└─────────────────────────────────────────────────────────┘

+ Agregar tarjeta    → payment-methods.create?return_url=...

▸ ¿Tienes un código promocional?     ← PromoCodeField collapsed

┌─ coupon_balance state ──────────────────────────────────┐
│ ✓ Tu compra está cubierta con crédito aplicado.         │
│   No necesitas otro método de pago.                     │
└─────────────────────────────────────────────────────────┘
```

## 14.2 Estados visuales

| Estado | Visual |
|--------|--------|
| **unselected** | Border gray-200, bg white |
| **selected** | Border navy 2px, radio ● |
| **loading** | Skeleton cards / spinner en CTA sidebar |
| **error** | Banner rojo top: `errors.payment_method` |
| **disabled** | Opacity 50%, `onlinePaymentDisabled` |
| **success** | N/A en paso — redirect post-submit |

## 14.3 PayPal

- Selección en paso pago: card PayPal
- Botón SDK: solo en **confirmación** (comportamiento actual)
- **No mover** `LaboratoryPayPalButton` / `createLaboratoryPayPalHandlers.js`

## 14.4 Preservar

- `payment_method` values: token id | `"paypal"` | `"odessa"` | `"coupon_balance"`
- `usePayPalFundingEligibility`
- Mock mode UI

---

# PARTE 15 — Appointment (K / L)

## 15.1 Wireframe — pendiente

```
Agenda tu cita
Un especialista de Famedic te contactará para confirmar fecha, hora y sucursal.

┌─ AppointmentStatusCard ─────────────────────────────────┐
│  ⏳  Estamos coordinando tu cita                          │
│      Te avisaremos cuando esté confirmada.                │
└─────────────────────────────────────────────────────────┘

┌─ Acciones ──────────────────────────────────────────────┐
│  [  WhatsApp  ]    [  Llamar  ]                          │  → phone-intent POST
└─────────────────────────────────────────────────────────┘

┌─ Preferencia de contacto ─────────────────────────────────┐
│  Prefiero que me llamen                                     │
│  [Comentario opcional........................]            │
│  Disponible de [__:__] a [__:__]                           │
│  [ Guardar preferencia ]                                    │  → PATCH callback-availability
└─────────────────────────────────────────────────────────────┘

ℹ Tu preferencia de sucursal en el carrito nos ayuda a orientarte.
  La sucursal final la confirma nuestro equipo.

[Polling indicator sutil: "Actualizando..." cada 10s]
```

## 15.2 Wireframe — confirmada

```
┌─ AppointmentStatusCard ─────────────────────────────────┐
│  ✓  Cita confirmada                                      │
│      Miércoles 18 sep 2026 · 10:30                       │
│      Laboratorio Vasconcelos                             │  ← appointment.laboratory_store
│      San Pedro Garza García                              │
└─────────────────────────────────────────────────────────┘
```

## 15.3 Reglas copy

| Mostrar | Fuente | Cuándo |
|---------|--------|--------|
| Sucursal confirmada | `laboratoryAppointment.laboratory_store` | `confirmed_at` presente |
| Preferencia paciente | draft selected store | Solo texto "preferencia" — nunca como confirmada |
| Fecha/hora | appointment fields | Solo si backend las envía |

## 15.4 NO mostrar

- Sucursal recomendada del ranking como si fuera cita confirmada
- "Reservado" / "Agendado" sin `confirmed_at`

---

# PARTE 16 — Confirmation (M / N)

## 16.1 Desktop wireframe

```
Revisa tu compra
Confirma que todo esté correcto antes de pagar.

┌─ ReviewRow ────────────────────────────────────────────────┐
│ PACIENTE          María López García            [Cambiar]  │
├────────────────────────────────────────────────────────────┤
│ DIRECCIÓN         Av. Constitución 100...       [Cambiar]  │
├────────────────────────────────────────────────────────────┤
│ SUCURSAL          Vasconcelos · San Pedro       [Cambiar]  │  ← "PREFERIDA" label small
│ PREFERIDA         (opcional — ocultar fila si missing)     │
├────────────────────────────────────────────────────────────┤
│ PAGO              Visa ···· 4242                [Cambiar]  │
├────────────────────────────────────────────────────────────┤
│ CITA              Confirmada · 18 sep 10:30     [Cambiar]  │  ← condicional
└────────────────────────────────────────────────────────────┘

Estudios (2)                                    [Ver lista ▾]

Total                              $2,910.58 MXN

Términos y condiciones · Aviso de privacidad    ← links existentes

Sidebar:
┌─────────────────────────┐
│ [ Confirmar compra      ]│  ← o LaboratoryPayPalButton
│      $2,910.58 MXN      │
└─────────────────────────┘
```

## 16.2 Mobile

- ReviewRows full width
- CTA en sticky bar: "Confirmar compra"
- PayPal button en barra o inmediatamente arriba

## 16.3 [Cambiar] targets

| Fila | `onEditStep` |
|------|--------------|
| Paciente | `"patient"` |
| Dirección | `"address"` |
| Pago | `"payment"` |
| Cita | `"appointment"` |
| Sucursal preferida | Navigate carrito `#sucursales` — no step id |

## 16.4 Submit preservado

- POST `laboratory.checkout.store` via `useForm.post`
- PayPal: handlers existentes

---

# PARTE 17 — Checkout Sidebar Desktop

## 17.1 Wireframe collapsed (default)

```
┌─────────────────────────────┐
│  🧪 2 estudios              │
│  $2,910.58 MXN              │
│                             │
│  [ Ver detalles  ▾ ]        │
│                             │
│  ┌─ PreferredStoreChip ───┐ │  ← solo si valid/stale
│  │ 📍 Vasconcelos         │ │
│  └────────────────────────┘ │
│                             │
│  ─────────────────────────  │
│  Total        $2,910.58     │
│                             │
│  ┌─────────────────────────┐│
│  │  Continuar / Confirmar  ││
│  └─────────────────────────┘│
└─────────────────────────────┘
```

## 17.2 Wireframe expanded ("Ver detalles")

```
│  [ Ver detalles  ▴ ]        │
│                             │
│  Perfil tiroideo    $1,049  │
│  Química sanguínea  $1,861  │
│  ─────────────────────────  │
│  Subtotal          $3,160   │
│  Descuento          −$249   │
│  ─────────────────────────  │
│  Promo: VERANO20    −$XX    │  ← si appliedPromo
│  Cupón aplicado...          │  ← BalanceCreditCard info
```

## 17.3 Ubicación elementos

| Elemento | Default | Expandido |
|----------|---------|-----------|
| Count + total | ✓ | ✓ |
| Lista estudios | — | ✓ |
| Subtotal/descuento | — | ✓ |
| Cupón/promo | Chip "Cupón aplicado" | Detalle |
| Sucursal preferida | Chip compacto | + dirección completa |
| CTA | ✓ | ✓ |
| Formulario paso actual | **No** en sidebar | **No** |

---

# PARTE 18 — Checkout Mobile Summary

## 18.1 Sticky bottom bar

```
┌─────────────────────────────────────┐
│  Ver resumen (2 estudios)        ↗  │  ← link 14px, abre sheet
│                                     │
│  Total                    $2,910.58 │
│  ┌─────────────────────────────────┐│
│  │         Continuar  →            ││
│  └─────────────────────────────────┘│
└─────────────────────────────────────┘
```

En confirmación: link "Ver resumen" permanece; CTA = "Confirmar compra".

## 18.2 Bottom sheet contenido exacto

```
┌─────────────────────────────────────┐
│           ───                       │
│  Resumen de compra              ✕   │
├─────────────────────────────────────┤
│  ESTUDIOS                           │
│  · Perfil tiroideo         $1,049   │
│  · Química sanguínea       $1,861   │
│                                     │
│  Subtotal                  $3,160   │
│  Descuento                  −$249   │
│  ─────────────────────────────────  │
│  Total                   $2,910.58  │
│                                     │
│  SUCURSAL PREFERIDA (si aplica)     │
│  Vasconcelos · San Pedro            │
│                                     │
│  CUPÓN / PROMO (si aplica)           │
│  Crédito aplicado: −$XXX            │
│                                     │
│  CITA (si aplica)                   │
│  Pendiente / Confirmada · fecha     │
│                                     │
│  [ Cerrar ]                         │
└─────────────────────────────────────┘
```

- **Read-only** — no editar desde sheet; [Cambiar] vive en paso confirmación o navegación
- Altura: 70vh max

---

# PARTE 19 — Progressive Disclosure (tabla completa)

| Información | Visible | Tap | Accordion | Bottom Sheet |
|-------------|---------|-----|-----------|--------------|
| Nombre estudio | ✓ cart | — | — | — |
| Precio estudio | ✓ cart | — | — | — |
| Badge requiere cita | ✓ si aplica | — | — | — |
| Detalle estudio / ahorro | — | ✓ Ver detalles | ✓ inline | — |
| Subtotal/descuento/total cart | ✓ resumen | — | — | — |
| Entry sucursales | ✓ 1 línea | ✓ expand/sheet | — | ✓ mobile |
| Input CP | — | ✓ expand | — | ✓ mobile |
| Sucursal recomendada | — | ✓ post-expand | — | ✓ |
| 2–3 alternativas | — | ✓ | — | ✓ Ver más |
| Municipios | — | — | ✓ | ✓ in-sheet |
| Búsqueda sucursal | — | ✓ tab Filtros | — | ✓ |
| Requisitos matched | — | ✓ en card | ✓ | ✓ |
| Horarios sucursal | ✓ 1 línea | — | — | — |
| Distancia | ✓ en card | — | — | — |
| Mapa | — | ✓ tab Mapa | — | ✓ fullscreen |
| Preferida seleccionada | ✓ chip | ✓ Cambiar | — | ✓ resumen |
| Lista pacientes | ✓ paso 1 | — | — | — |
| Form nuevo paciente | — | ✓ | ✓ desktop | ✓ sheet mobile |
| Lista direcciones | ✓ paso 2 | — | — | — |
| Form nueva dirección | — | ✓ | ✓ desktop | ✓ sheet mobile |
| Chip preferida checkout | ✓ paso 2 | ✓ Cambiar→carrito | — | — |
| Métodos pago | ✓ paso 3 | — | — | — |
| Promo/código | — | ✓ | — | — |
| Resumen checkout items | ✓ count | ✓ Ver detalles | ✓ desktop | ✓ mobile sheet |
| Cupón en sidebar | chip | ✓ expand | ✓ | ✓ sheet |
| Estado cita | ✓ paso cita | — | — | ✓ sheet |
| WhatsApp/llamada | ✓ paso cita | ✓ | — | — |
| Filas revisión | ✓ confirm | [Cambiar] | — | — |
| Términos legales | — | — | — | ✓ solo confirmación |
| FeaturesGrid | — | — | — | — (omitido) |

---

# PARTE 20 — Responsive

| Componente | Desktop ≥1024 | Tablet 768–1023 | Mobile <768 |
|------------|---------------|-----------------|-------------|
| Cart grid | 66/34 sticky | 60/40 o stack+sticky bottom | 1 col + bottom bar |
| LabStudyCard | Full width col | Full width | Full width compact |
| OrderSummary | Sidebar sticky top | Sticky sidebar o bottom | StickyCheckoutBar |
| Branch section | Inline expand | Inline expand | BranchBottomSheet |
| Branch tabs | Horizontal | Horizontal scroll | Segmented 2 tabs |
| Branch cards grid | 2 col alternativas | 1–2 col | 1 col vertical |
| Map | Side panel 40% | Overlay 50% | Fullscreen modal |
| Municipality | Accordion max 3 | Accordion | Accordion in-sheet |
| Checkout grid | 66/34 | Similar | Full width |
| CheckoutStepper | Horizontal labels | Compact horizontal | Dots + title |
| CheckoutStepCard | Max 560px | Full width | Full width |
| Sidebar summary | Sticky | Collapsible top | Sheet only |
| CTA | Sidebar + footer | Sticky bottom | Sticky bottom |
| Contact/Address form | Inline expand | Inline | Full-screen sheet |
| PayPal button | Sidebar width | Full width | Full width sticky zone |

---

# PARTE 21 — Estados globales (wireframes)

## 21.1 Carrito

### Normal
→ Parte 2 wireframe

### Vacío
→ Parte 2.4

### Loading (Inertia)
```
[Skeleton header]
[Skeleton card × 2]
[Skeleton sidebar]
```

### Error (full page)
- Rare — Inertia error boundary existente
- Copy: "No pudimos cargar tu carrito. [Reintentar]"

## 21.2 Sucursales

| Estado | Wireframe clave |
|--------|-----------------|
| missing CP | Parte 7.1 |
| resolved | Parte 7.2 |
| unresolved | Parte 7.3 |
| invalid CP | Parte 7.4 |
| loading | Parte 7.5 |
| clear CP | Parte 7.6 |
| empty cart | Mensaje: "Agrega estudios primero" |
| no-compatible | "No hay sucursales compatibles" + Continuar visible |
| unresolved cart | "Revisamos tus estudios" — sin lista |
| error API | Banner + Reintentar |
| selected valid | Parte 9.1 green chip |
| selected stale | Amber "Actualiza preferencia" |
| selected invalid | Amber "Elige otra" |

## 21.3 Checkout

| Estado | Visual |
|--------|--------|
| loading | Skeleton step + sidebar |
| normal | Wireframes E–N |
| validation error | Banner bajo H1, field errors |
| payment loading | CTA spinner + disabled |
| payment error | Red banner `errors.payment_method` |
| appointment pending | Parte 15.1 |
| appointment confirmed | Parte 15.2 |
| confirmation | Parte 16 |
| appointment-first blocked payment | CTA disabled + tooltip "Confirma tu cita primero" |
| syncing draft | Subtle "Guardando..." near CTA |

---

# PARTE 22 — Copy Bank (español, usuario final)

## 22.1 Carrito

| Elemento | Copy |
|----------|------|
| Título | Carrito de laboratorio |
| Subtítulo | Revisa tus estudios antes de continuar. |
| Sección estudios | Estudios en tu carrito |
| Agregar | + Agregar más estudios |
| Quitar | Quitar |
| Ver detalles estudio | Ver detalles |
| Badge cita | Requiere cita |
| Aviso carrito | Algunos estudios requieren cita. La coordinamos contigo después de completar tu compra. |
| Empty título | Tu carrito está vacío |
| Empty CTA | Explorar estudios |
| Continuar | Continuar |
| Resumen título | Resumen de compra |

## 22.2 Sucursales

| Elemento | Copy |
|----------|------|
| Entry collapsed | Encontrar una sucursal compatible |
| Entry hint | Opcional · Busca por código postal |
| CP label | Código postal (opcional) |
| CP hint | Te ayuda a mostrar opciones cercanas. |
| Buscar | Buscar |
| Limpiar | Limpiar |
| Resolved | Encontramos sucursales compatibles cerca de ti. |
| Unresolved | Aún no tenemos ubicación para este código postal. Mostramos sucursales compatibles sin ordenar por distancia. |
| CP error 5 digits | Ingresa un código postal mexicano de 5 dígitos. |
| Tab recomendadas | Recomendadas |
| Tab todas | Todas |
| Tab filtros | Filtros |
| Tab mapa | Ver mapa |
| Recomendada title | Recomendada para ti |
| Recomendada disclaimer | Ordenada por compatibilidad con tus estudios y cercanía. No reserva cita. |
| Horario | Horario referencial |
| Compatible | Compatible con tus estudios |
| Seleccionar | Seleccionar sucursal |
| Seleccionada | Sucursal seleccionada |
| Otras opciones | Otras opciones cerca de ti |
| Municipios | Más sucursales por zona |
| Ver zonas | Ver todas las zonas |
| Preferida title | Sucursal preferida |
| Preferida disclaimer | La cita y horario se confirman contigo después. |
| Cambiar | Cambiar |
| Quitar preferencia | Quitar |
| Guardada toast | Guardamos tu preferencia de sucursal. |
| No compatible | No encontramos sucursales compatibles con tus estudios. Puedes continuar sin elegir una. |
| Error load | No pudimos cargar las sucursales. Intenta de nuevo. |
| Reintentar | Reintentar |

## 22.3 Checkout stepper

| ID | Label stepper | H1 paso |
|----|---------------|---------|
| patient | ¿Para quién? | ¿Para quién son los estudios? |
| address | Tu dirección | ¿Cuál es tu dirección? |
| payment | Pago | ¿Cómo quieres pagar? |
| appointment | Cita | Agenda tu cita |
| confirmation | Confirmar | Revisa tu compra |

## 22.4 Checkout pasos

| Elemento | Copy |
|----------|------|
| Patient subtitle | Selecciona al paciente que realizará los estudios. |
| + paciente | + Agregar nuevo paciente |
| Address subtitle | La usamos para tu expediente y para contactarte sobre tus resultados. |
| + dirección | + Agregar nueva dirección |
| Chip preferida | Preferencia de sucursal |
| Payment subtitle | Elige un método para completar tu compra. |
| + tarjeta | + Agregar tarjeta |
| Promo toggle | ¿Tienes un código promocional? |
| Cupón cubierto | Tu compra está cubierta. No necesitas otro método de pago. |
| Appointment subtitle | Un especialista de Famedic te contactará para confirmar fecha, hora y sucursal. |
| Pending | Estamos coordinando tu cita |
| Pending detail | Te avisaremos cuando esté confirmada. |
| Confirmed | Cita confirmada |
| WhatsApp | WhatsApp |
| Llamar | Llamar |
| Callback title | Prefiero que me llamen |
| Guardar callback | Guardar preferencia |
| Confirm subtitle | Confirma que todo esté correcto antes de pagar. |
| Confirm CTA | Confirmar compra |
| Ver resumen | Ver resumen |
| Ver detalles | Ver detalles |
| Ver lista estudios | Ver lista |

## 22.5 Errores

| Contexto | Copy |
|----------|------|
| Sin paciente | Selecciona un paciente para continuar. |
| Sin dirección | Selecciona una dirección para continuar. |
| Sin pago | Elige un método de pago para continuar. |
| Pago fallido | No pudimos procesar tu pago. Revisa tu método e intenta de nuevo. |
| Cita no confirmada | Espera la confirmación de tu cita para continuar. |
| Selección sucursal 422 | Esta sucursal ya no coincide con tus estudios. Elige otra o continúa sin preferencia. |

---

# PARTE 23 — Componentes visuales propuestos

| # | Componente | Propósito | Desktop | Mobile | Estados | Dependencias funcionales |
|---|------------|-----------|---------|--------|---------|--------------------------|
| 1 | **LabStudyCard** | Item estudio en carrito | Col lista | Compact row | normal, expanded | DELETE cart item, GA4 remove |
| 2 | **OrderSummary** | Subtotal/total/CTA | Sidebar sticky | Part of StickyBar | empty disabled | props totales, checkoutUrl |
| 3 | **StickyCheckoutBar** | CTA + total fijo | — | Bottom fixed | empty disabled | handleCheckoutClick |
| 4 | **BranchSectionEntry** | Collapsed trigger sucursales | Expand inline | Open sheet | — | — |
| 5 | **PostalCodeSearch** | Input CP + buscar/limpiar | Inline | In sheet | missing, resolved, unresolved, invalid, loading | GET compatible-stores |
| 6 | **BranchTabBar** | Recomendadas/Todas/Filtros/Mapa | Horizontal tabs | Segmented | — | UI only |
| 7 | **BranchRecommendationCard** | Sucursal destacada | Wide card | Full width | default, selected | POST/DELETE selected-store |
| 8 | **BranchCompactCard** | Alternativas / lista | 2-col grid | Stack | default, selected | idem |
| 9 | **MunicipalityAccordion** | Agrupación zona | Max 3 visible | In sheet | collapsed/expanded | groupBranchesByMunicipality |
| 10 | **PreferredStoreChip** | Preferencia guardada | Chip + actions | Chip in sheet | valid, stale, invalid | selected-store GET |
| 11 | **BranchBottomSheet** | Contenedor mobile sucursales | — | 55–90vh | loading, ready, error, empty | fetchCompatibleLaboratoryStores |
| 12 | **HumanStepper** | Progreso checkout | Horizontal | Dots | completed, active, pending, disabled | step IDs unchanged |
| 13 | **CheckoutStepCard** | Select paciente/dirección/pago | 560px max | Full width | selected, unselected, error | setData, draft sync |
| 14 | **CheckoutPaymentCard** | Método pago | Stack | Stack | selected, error, disabled | payment_method values |
| 15 | **CheckoutSidebarSummary** | Resumen checkout desktop | Sticky col | — | collapsed, expanded | props cart, selectedStore |
| 16 | **CheckoutSummarySheet** | Resumen mobile | — | Bottom sheet | — | read-only props |
| 17 | **ReviewRow** | Fila editable confirmación | Table rows | Stack rows | — | onEditStep |
| 18 | **AppointmentStatusCard** | Estado cita | Card | Card | pending, confirmed | laboratoryAppointment props |
| 19 | **AppointmentContactActions** | WA / Llamar | Button row | Full width btns | — | phone-intent POST |
| 20 | **CallbackPreferenceForm** | Horario callback | Inline | Inline | — | PATCH callback-availability |
| 21 | **InlineAlert** | Errores/info | Banner | Banner | info, warning, error | errors.* |
| 22 | **LoadingSkeleton** | Placeholders | Various | Various | — | — |

**Total componentes propuestos: 22**

---

# PARTE 24 — Trazabilidad

| Componente | Conservar (sin cambios) |
|------------|-------------------------|
| LabStudyCard | DELETE `laboratory-cart-items.destroy`; modal confirm; GA4 remove_from_cart |
| OrderSummary / StickyCheckoutBar | Navigate `laboratory.checkout?step=patient`; GA4 begin_checkout; totales server-side |
| PostalCodeSearch | GET `laboratory.cart.compatible-stores`; params `postal_code`, `clear_postal_code`; persist draft |
| BranchRecommendationCard | POST `selected-store.store` `{ laboratory_store_id }`; DELETE destroy; toggle selection |
| BranchBottomSheet | Mismos axios calls que `LaboratoryCompatibleStoresSection` |
| PreferredStoreChip | GET selected-store.show; presentation from `checkoutSelectedStorePresentation()` |
| HumanStepper | IDs: patient, address, payment, appointment, confirmation; `goToStep()` |
| CheckoutStepCard (patient) | POST `checkout.contacts.store`; draft sync contact_id |
| CheckoutStepCard (address) | POST `checkout.addresses.store`; draft sync address_id |
| CheckoutPaymentCard | setData payment_method; values token/paypal/odessa/coupon_balance |
| CheckoutPaymentCard PayPal | Selection only; SDK en confirmación |
| AppointmentStatusCard | Polling reload 10s; NO write appointment.laboratory_store_id |
| AppointmentContactActions | POST `laboratory-appointments.phone-intent` |
| CallbackPreferenceForm | PATCH callback-availability |
| ReviewRow / Confirm CTA | POST `laboratory.checkout.store`; PayPal handlers |
| CheckoutSidebarSummary | hideDefaultSubmit; summaryActions slot behavior |

---

# PARTE 25 — Riesgo por componente

| Componente | Riesgo | Motivo | Qué preservar |
|------------|--------|--------|---------------|
| LabStudyCard | 🟡 | Estado + DELETE + GA4 | destroy handler, modal |
| OrderSummary | 🟡 | CTA navegación + GA4 | checkoutUrl, begin_checkout |
| StickyCheckoutBar | 🟡 | Mobile CTA | Mismo handler Continuar |
| PostalCodeSearch | 🟡 | API + persist draft | Query params, clear flag |
| BranchRecommendationCard | 🟡 | POST/DELETE selection | laboratory_store_id payload |
| BranchBottomSheet | 🟡 | Contenedor axios | Misma lib laboratoryCompatibleStores |
| PreferredStoreChip | 🟡 | Draft read-only display | No implicar confirmación cita |
| HumanStepper | 🟡 | Navegación wizard | Step IDs, step guard compat |
| CheckoutStepCard | 🟡 | Form state + draft sync | contact/address IDs |
| CheckoutPaymentCard | 🔴 | payment_method + SDK | Handlers, PayPal, validation |
| CheckoutSidebarSummary | 🟡 | CTA submit slot | submit(), summaryActions |
| CheckoutSummarySheet | 🟢 | Read-only | Props display only |
| ReviewRow | 🟡 | onEditStep navigation | goToStep targets |
| AppointmentStatusCard | 🔴 | Polling + bloqueo pago | confirmed_at logic |
| AppointmentContactActions | 🔴 | phone-intent AC side effects | POST payload channel |
| CallbackPreferenceForm | 🔴 | AC call signals | PATCH endpoint |
| BranchTabBar Map | 🟢* | Visual (*si mapa nuevo → validación) | — |
| InlineAlert | 🟢 | Visual | Error prop mapping |
| LoadingSkeleton | 🟢 | Visual | — |

---

# PARTE 26 — Plan de implementación

| Fase | Entregable | Archivos estimados | Depende de | Riesgo |
|------|------------|-------------------|------------|--------|
| **1** | Design tokens, primitivos (Card, Chip, Sheet, StickyBar) | Nuevos UI primitives | — | 🟢 |
| **2** | Cart desktop — LabStudyCard + OrderSummary | ShoppingCartLayout, LaboratoryShoppingCart | F1 | 🟡 |
| **3** | Cart mobile — StickyCheckoutBar + reorder | ↑ | F2 | 🟡 |
| **4** | Branches desktop — expand section, tabs, cards | LaboratoryCompatibleStoresSection | F2 | 🟡 |
| **5** | Branches mobile — BranchBottomSheet | ↑ + nuevo wrapper | F3, F4 | 🟡 |
| **6** | Checkout patient + address — HumanStepper, StepCards, chip preferida | ContactStep, AddressStep, CheckoutStepper, LaboratoryCheckout | F1 | 🟡 |
| **7** | Payment — CheckoutPaymentCard | PaymentMethodStep | F6 | 🔴 |
| **8** | Appointment — AppointmentStatusCard | LaboratoryAppointmentStep | F6 | 🔴 |
| **9** | Confirmation — ReviewRow, sidebar summary | ConfirmationStep, CheckoutLayout | F7, F8 | 🔴 |
| **10** | Responsive polish + a11y | Transversal | F9 | 🟡 |
| **11** | Regression testing | — | F10 | — |

**Ajuste vs secuencia genérica:** Fases 2–3 antes de 4–5 (carrito estable antes de sucursales). Fase mapa (tab Ver mapa) **opcional post-F11** — requiere validación.

---

# PARTE 27 — Criterios de aceptación por fase

## F2–3 Cart

- [ ] Mismos estudios renderizados desde `laboratoryCarts` + props
- [ ] Mismos precios formateados (subtotal, descuento, total)
- [ ] Eliminar abre modal y DELETE funciona
- [ ] + Agregar más estudios → `laboratory-tests`
- [ ] Continuar → checkout patient step
- [ ] GA4 view_cart, remove_from_cart, begin_checkout intactos
- [ ] ActiveCampaign / FB sin cambios (server-side)
- [ ] Carrito vacío: Continuar disabled
- [ ] Mobile: sticky bar visible sin scroll

## F4–5 Branches

- [ ] GET compatible-stores mismo endpoint/query
- [ ] CP persist/rehydrate/clear funciona
- [ ] POST/DELETE selected-store mismo payload
- [ ] Cart hash stale invalida UI correctamente
- [ ] CP opcional — Continuar checkout sin CP ni preferencia
- [ ] No bloquea checkout
- [ ] Copy no implica reserva/disponibilidad cita

## F6 Checkout patient/address

- [ ] Step IDs internos unchanged
- [ ] Draft sync contact_id / address_id
- [ ] Stepper no dice "Sucursal" para address
- [ ] Nuevo paciente/dirección endpoints intactos
- [ ] Chip preferida read-only + link carrito

## F7 Payment

- [ ] payment_method values unchanged
- [ ] PayPal SDK render en confirmación
- [ ] Promo/coupon flows intactos
- [ ] coupon_balance auto cuando total 0

## F8 Appointment

- [ ] Paso solo si flujo lo requiere
- [ ] Polling 10s preserved
- [ ] phone-intent + callback PATCH intactos
- [ ] Sucursal confirmada solo de appointment object
- [ ] Bloqueo pago appointment-first preserved

## F9 Confirmation

- [ ] POST checkout.store payload unchanged
- [ ] onEditStep navigation correcta
- [ ] PayPal button placement funcional
- [ ] Review rows match saved draft state

## F11 Regression

- [ ] Suite PHP listed in audit passes
- [ ] JS laboratoryCompatibleStores tests pass
- [ ] Manual GA4 + FB verification staging

---

# PARTE 28 — Decisión final UX

## 28.1 DECISIONES RECOMENDADAS

1. **Tres zonas carrito:** Estudios (A) · Sucursales colapsadas (B) · Resumen sticky (C).
2. **Mobile:** StickyCheckoutBar + BranchBottomSheet — no column stack del desktop.
3. **Stepper address:** Label "Tu dirección"; H1 "¿Cuál es tu dirección?"; nunca "Sucursal".
4. **Sucursal preferida en checkout:** Chip compacto en paso dirección + sidebar — **no** paso wizard dedicado.
5. **Microcopy preferida:** Secondary text visible: "La cita y horario se confirman contigo después."
6. **Recomendada ≠ preferida ≠ confirmada:** Copy distinto en cada nivel; confirmada solo en paso cita con `appointment.laboratory_store`.
7. **CP opcional** con 6 estados wireframeados; nunca bloquea Continuar.
8. **Sucursales desktop:** Tab Recomendadas default; 1 destacada + 2–3 alternativas; municipios accordion max 3.
9. **Checkout sidebar:** Collapsed default — count + total + Ver detalles + CTA.
10. **Checkout mobile:** "Ver resumen" sheet read-only + sticky CTA.
11. **Eliminar FeaturesGrid del carrito** — reduce ruido.
12. **Un solo CTA "Agregar más estudios"** en columna estudios.
13. **Confirmación:** ReviewRow con [Cambiar] — no repetir formularios.
14. **Appointment-first:** Label UI "Pago y confirmar" en último paso; ID `payment` intacto.

## 28.2 DECISIONES QUE REQUIEREN VALIDACIÓN

| # | Decisión | Pregunta para negocio/producto |
|---|----------|--------------------------------|
| 1 | Tab **Ver mapa** en carrito | ¿Invertir en mapa Leaflet en carrito v1? Hoy no existe en flujo paciente. |
| 2 | **FeaturesGrid** eliminado del carrito | ¿Mover a footer global o eliminar del flujo compra? |
| 3 | Tablet 768–1023 layout | ¿Sidebar sticky o bottom bar? |
| 4 | Auto-cerrar sheet al seleccionar sucursal | ¿Permanece abierto (recomendado) o cierra automático? |
| 5 | Consolidar **doble GA4 begin_checkout** | ¿Corregir en mismo release visual o ticket aparte? |
| 6 | Virtualización lista sucursales | ¿Necesaria? Depende de volumen sucursales por marca. |
| 7 | Link **Cambiar preferida** desde checkout | ¿Deep link carrito `#sucursales` o modal inline reutilizando section? |

## 28.3 FUNCIONALIDADES NUEVAS NO EXISTENTES (no implementar sin plan)

| Funcionalidad | Estado |
|---------------|--------|
| Mapa en carrito paciente | No existe — PROPUESTA |
| Ratings / reviews sucursales | No existe — prohibido inventar |
| Disponibilidad cita en tiempo real | No existe — prohibido |
| ETA / tiempos de espera | No existe — prohibido |
| Filtros capacidad beyond search text | No existe — prohibido |
| Nuevo paso wizard "Sucursal lab" | No requerido — draft optional |
| Push notifications cita | Fuera alcance |
| assertValidForCheckout en compra | Existe método pero no se usa — no activar en rediseño UI |

---

# Apéndice — Métricas del entregable

| Métrica | Cantidad |
|---------|----------|
| **Wireframes estructurales** | **32** (14 pantallas + 6 CP + 7 checkout estados + 5 carrito/sucursales variantes) |
| **Estados documentados** | **28** |
| **Componentes visuales propuestos** | **22** |
| **Decisiones recomendadas** | **14** |
| **Decisiones requiere validación** | **7** |
| **Funcionalidades nuevas flagged** | **8** |

---

*Documento generado Fase 1.5 — Wireframes funcionales. Sin implementación.*
