# Phase 4D Local QA - Laboratory Stores Directory

Date: 2026-09-03

URL under QA:

```text
http://localhost:8080/laboratory-stores?brand=olab
```

Final QA status:

```text
LABORATORY_STORES_LOCAL_UX_NEEDS_REVIEW
```

Reason: backend, build, data, and static UX checks passed, but this session has no executable browser surface for real visual screenshots or interactive Leaflet/geolocation verification.

## Scope

QA covered the directory after OLAB GDA import, smart search, filters, cards, Leaflet/OpenStreetMap, nearby search, distance sorting, and List/Map toggle.

No deploy, staging, production release, commit, push, or PR was performed.

## Fixes Made During QA

- External `Como llegar` links now use `rel="noopener noreferrer"` when opening a new tab.
- Card highlight after `Mapa -> Lista` now clears after a short interval instead of staying indefinitely.
- Search debounce now uses the latest filter state via `useRef`, preventing a delayed search request from overwriting a recently changed filter.
- Geolocation loading copy now says `Obteniendo ubicacion...`.

## Data Counts

| Metric                                 | Count |
| -------------------------------------- | ----: |
| Active OLAB stores                     |    47 |
| Active OLAB stores with coordinates    |    45 |
| Active OLAB stores without coordinates |     2 |
| Duplicate active OLAB names            |     0 |
| Duplicate active OLAB coordinate pairs |     0 |
| Resonancia results                     |    12 |
| Mastografia results                    |    25 |
| Optica results                         |     9 |
| MIXCOAC ID 60 exact coordinate matches |     1 |

MIXCOAC:

|  id | name    |   latitude |   longitude |
| --: | ------- | ---------: | ----------: |
|  60 | MIXCOAC | 19.3650650 | -99.1781010 |

## Stores Without Coordinates

No coordinates were invented and no external geocoding was performed.

|  id | name       | address                                                                                 | postal_code | latitude   | longitude | reason_known                   |
| --: | ---------- | --------------------------------------------------------------------------------------- | ----------- | ---------- | --------- | ------------------------------ |
| 132 | ALAMOS     | PROLONGACION BERNARDO QUINTANA, ALAMOS 3RA SECCION, SANTIAGO DE QUERETARO, 76170        | 76170       | NULL       | NULL      | latitude_and_longitude_missing |
| 135 | JURIQUILLA | BLVD DE LAS CIENCIAS CENTRO, COMERCIAL OMNICENTRO JURIQUILA, SANTA ROSA JAUREGUI, 76230 | 76230       | 20.7137140 | NULL      | longitude_missing              |

Expected UX for these stores:

- They appear in normal list/search.
- They are not rendered as map markers.
- They do not show `Ver en mapa`.
- They keep `Como llegar` through existing `google_maps_url`.
- They are excluded only from geographic nearby queries.

Validated:

| Scenario                         | Result |
| -------------------------------- | -----: |
| `search=ALAMOS`                  |      1 |
| `search=JURIQUILLA`              |      1 |
| `search=ALAMOS` + distance query |      0 |

## Missing Fields Matrix

Blank `missing_fields` means no audited field was missing.

| store_id | name                | missing_fields |
| -------: | ------------------- | -------------- |
|      132 | ALAMOS              | coordinates    |
|      127 | ALTAVISTA           |                |
|       40 | ANZURES             |                |
|      128 | ARBOLEDAS           |                |
|      129 | ARCOS               | municipality   |
|      130 | ATIZAPAN            |                |
|      131 | BALBUENA            |                |
|       41 | BARRANCA DEL MUERTO |                |
|      133 | CANDILES            |                |
|      134 | CARRACCI            |                |
|       42 | CENTER PLAZA        |                |
|       43 | COACALCO            |                |
|       48 | COAPA               |                |
|       45 | COLINAS DEL SUR     |                |
|       44 | CONDESA             |                |
|       46 | CONSULADO           |                |
|       47 | COYOACAN            |                |
|       49 | CUAUTITLAN MEXICO   |                |
|       50 | CUICUILCO           |                |
|       51 | DEL VALLE           |                |
|       52 | ECATEPEC            |                |
|       53 | ERMITA              |                |
|       54 | INTERLOMAS          |                |
|       55 | IZCALLI             |                |
|      135 | JURIQUILLA          | coordinates    |
|       56 | LA VILLA            |                |
|       57 | LINDAVISTA          |                |
|       58 | METEPEC             |                |
|       59 | MIRAMONTES          |                |
|       60 | MIXCOAC             |                |
|       61 | MONTEVIDEO          |                |
|       62 | MORAZAN             |                |
|      136 | NAPOLES             |                |
|      137 | NARVARTE            |                |
|       63 | NEZAHUALCOYOTL      |                |
|       64 | PASEO VENTURA       |                |
|      138 | POLANCO             |                |
|      139 | REFUGIO             |                |
|       65 | ROMA                |                |
|       66 | SANTA FE            |                |
|       67 | SANTA MONICA        |                |
|       68 | SATELITE            |                |
|       69 | TACUBAYA            |                |
|       70 | TEZONTLE            |                |
|       71 | TLALNEPANTLA        |                |
|       72 | TLALPAN             |                |
|      140 | UBIKA UNIVERSIDAD   |                |

## Search QA

| Search                                 | Result Count | Notes                                                                |
| -------------------------------------- | -----------: | -------------------------------------------------------------------- |
| Mixcoac                                |            1 | Case-insensitive in local MySQL collation.                           |
| mixcoac                                |            1 | OK.                                                                  |
| MIXCOAC                                |            1 | OK.                                                                  |
| 03940                                  |            1 | Postal-code search OK.                                               |
| Narvarte                               |            1 | OK.                                                                  |
| Benito Juarez                          |            7 | Municipality search OK.                                              |
| Benito Juarez with accent in user text |            7 | Tested with `Benito Juarez` and accented variant; both returned 7.   |
| Queretaro                              |            6 | OK.                                                                  |
| Queretaro with accent in user text     |            6 | OK.                                                                  |
| mastografia                            |           25 | Search alias maps to capability.                                     |
| resonancia                             |           12 | Search alias maps to capability.                                     |
| olab                                   |            0 | Brand is a filter, not searched as store text. Not treated as a bug. |

## Filter QA

HTTP checks returned 200 with no SQL errors:

| Scenario                  | Status | Approx Time |
| ------------------------- | -----: | ----------: |
| brand=olab                |    200 |      406 ms |
| state                     |    200 |      291 ms |
| state + municipality      |    200 |      229 ms |
| capability                |    200 |      304 ms |
| service                   |    200 |      262 ms |
| capability + state        |    200 |      252 ms |
| capability + municipality |    200 |      225 ms |
| service + state           |    200 |      220 ms |
| search + capability       |    200 |      213 ms |
| search + service          |    200 |      227 ms |
| location + capability     |    200 |      264 ms |
| location + service        |    200 |      221 ms |
| location + state          |    200 |      279 ms |
| location + municipality   |    200 |      220 ms |

## Radius QA

Simulated location: MIXCOAC coordinates `19.365065, -99.178101`.

| Radius | Result Count |
| -----: | -----------: |
|   5 km |           10 |
|  10 km |           20 |
|  25 km |           34 |
|  50 km |           40 |

The UI only exposes `5`, `10`, `25`, and `50`. Backend rejects invalid radius values.

## Nearby And Distance

- `Cerca de mi` calls `navigator.geolocation.getCurrentPosition` only after a user click.
- While waiting, the button is disabled and shows `Obteniendo ubicacion...`.
- On success, Inertia requests `latitude`, `longitude`, `radius=10`, and `sort=distance`.
- `distance_km` is formatted to one decimal in the backend resource.
- `Quitar ubicacion` removes `latitude`, `longitude`, and `radius`; if needed, sorting returns to `name`.
- User location is shown with a `CircleMarker`, not a store marker.
- No DB write, localStorage, sessionStorage, cookies, or analytics payload with coordinates were found in the module.

## Map QA

Static/code checks:

- Leaflet marker icons are imported from `leaflet/dist/images`, avoiding fragile CDN marker paths.
- OpenStreetMap attribution is visible in `TileLayer`.
- Stores without coordinates are filtered out of markers.
- Empty map state exists when filtered stores have no coordinates.
- Bounds are fitted to filtered markers; one marker uses zoom 14; selected marker uses at least zoom 15.
- Popup content stays compact: name, location, today hours, short address, `Ver sucursal`, `Como llegar`.
- No routing, ETA, traffic, Directions API, Mapbox, Google Maps JS, or OSM geocoding was introduced.

Visual/browser checks:

- Not completed in this environment because CUA reported no available browsers and prior Puppeteer runs lacked required Chromium system libraries.
- Screenshots were not captured.

## Links, Phones, Addresses, Hours

`Como llegar` classification:

| Link Type                   | Count |
| --------------------------- | ----: |
| Existing `google_maps_url`  |    47 |
| Generated from lat/lng      |     0 |
| Generated from address/name |     0 |
| No possible link            |     0 |

Phones:

| Metric          | Count |
| --------------- | ----: |
| Missing phone   |     0 |
| Ten-digit phone |    47 |
| Total           |    47 |

Cards format 10-digit phone numbers as `55 4040 6580` style while preserving `tel:5540406580`.

Hours for Thursday, using backend timezone `America/Mexico_City`:

| Metric               | Count |
| -------------------- | ----: |
| Missing today row    |     0 |
| Closed today         |     2 |
| Malformed open today |     0 |
| Total                |    47 |

Addresses:

- Cards use structured `street`, `exterior_number`, `interior_number`, `neighborhood` first.
- Fallback is legacy `address`.
- No DB changes were made.
- One data issue remains: ARCOS is missing `municipality`.

## Capabilities And Services

Capabilities:

| Metric                             | Count |
| ---------------------------------- | ----: |
| Stores with exactly 1 capability   |     1 |
| Stores with exactly 5 capabilities |     1 |
| Stores with 10+ capabilities       |    17 |
| Max capabilities on one store      |    21 |

The card shows a bounded initial set and `+N mas`; expand/collapse reveals the rest. No duplicate active OLAB names or coordinate pairs were detected.

Services:

| Service Shape         | Count |
| --------------------- | ----: |
| Clinical history only |     4 |
| Optical only          |     7 |
| Both                  |     2 |
| Neither               |    34 |

Service badges remain visually differentiated from capabilities through color.

## Accessibility And Responsive Review

Static checks:

- Search has visible label and `aria-label`.
- Listboxes use Catalyst components.
- Capability/service chips use `aria-pressed`.
- `Lista | Mapa` uses `aria-pressed` and a grouped label.
- Nearby button is an explicit button and is disabled while geolocation is pending.
- External links include `target="_blank"` and `rel="noopener noreferrer"`.
- List remains the accessible alternative to map markers and popups.

Responsive implementation:

- List and map are mutually exclusive on mobile.
- Map has minimum usable height of `350px` on mobile and `520px` from small screens upward.
- Cards use one column by default and two columns at `xl`.
- No drawer was added for mobile filters.

Browser viewport QA at 375, 390, 768, 1024, and 1440 px remains pending because no browser was available.

## SSR Safety

- Build client + SSR passed after Leaflet integration.
- Leaflet map is lazy-loaded from the page.
- Top-level `window`, `document`, and `navigator` usage is limited to client-side hooks/handlers in the directory module.

## Performance

Current local timing is dominated by Laravel/local stack boot and request overhead, not query count.

| Scenario            |          Approx HTTP Time |
| ------------------- | ------------------------: |
| OLAB normal         |                    406 ms |
| Distance query      | 264 ms to 580 ms observed |
| Resonancia filtered |  304 ms / 530 ms observed |

Query count:

| Scenario                 | Query Count |
| ------------------------ | ----------: |
| `stores()` normal        |           4 |
| `stores()` with distance |           4 |

No caching was added.

## Build And Tests

PHP tests:

```text
47 passed (482 assertions)
```

Build:

```text
npm run build
client + SSR OK
```

Known baseline warnings:

- `NODE_ENV=production is not supported in the .env file`
- Browserslist data is old.
- Empty chunk: `VerSolicitud`.

## Remaining Risks

- Full visual QA and screenshots are pending until a browser/Chromium environment is available.
- Real permission-denied/timeout geolocation UX was statically reviewed but not browser-exercised.
- ARCOS has missing municipality data.
- ALAMOS and JURIQUILLA have incomplete coordinates and therefore cannot appear as markers until source data is corrected.
