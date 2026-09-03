# Laboratory Stores Search UI Contract

Backend endpoint:

```text
GET /laboratory-stores
```

The existing URL remains compatible:

```text
/laboratory-stores?brand=olab
```

The directory supports list and map views. The map uses Leaflet with OpenStreetMap tiles and existing store coordinates only.

## Query Parameters

All filters are additive.

| Parameter      | Example            | Notes                                                                                                            |
| -------------- | ------------------ | ---------------------------------------------------------------------------------------------------------------- |
| `brand`        | `olab`             | Optional `LaboratoryBrand` enum value.                                                                           |
| `search`       | `narvarte`         | Text search over name, address, street, neighborhood, municipality, city, state, and postal code. Max 120 chars. |
| `state`        | `Ciudad de Mexico` | Exact state match, preserving current behavior.                                                                  |
| `municipality` | `Benito Juarez`    | Exact municipality match.                                                                                        |
| `postal_code`  | `03940`            | Exact five-character postal code; never cast to integer.                                                         |
| `capability`   | `rayos_x`          | Exact active capability slug from `laboratory_capabilities`.                                                     |
| `service`      | `historia_clinica` | Supported values: `historia_clinica`, `optica`.                                                                  |
| `latitude`     | `19.39023`         | Optional user latitude for nearby search. Valid range: -90 to 90. Must be sent with `longitude`.                 |
| `longitude`    | `-99.17403`        | Optional user longitude for nearby search. Valid range: -180 to 180. Must be sent with `latitude`.               |
| `radius`       | `10`               | Optional radius in kilometers. Supported values: `5`, `10`, `25`, `50`.                                          |
| `sort`         | `distance`         | Supported values: `name`, `relevance`, `distance`. `distance` requires valid `latitude` and `longitude`.         |

Search includes a small centralized alias map for capability-like terms:

- `rayos`, `rayos x` -> `rayos_x`
- `resonancia` -> `resonancia_magnetica`
- `mastografia` -> `mastografia`
- `tomografia` -> `tomografia`
- `ultrasonido` -> `ultrasonido_convencional`

Explicit `capability=<slug>` remains preferred for capability filtering.

## Inertia Props

### `laboratoryStores`

Array of stores. Legacy fields used by the current React table are preserved.

```json
{
	"id": 60,
	"brand": "olab",
	"name": "MIXCOAC",
	"address": "...",
	"street": "...",
	"neighborhood": "...",
	"municipality": "BENITO JUAREZ",
	"city": null,
	"state": "CIUDAD DE MEXICO",
	"postal_code": "03940",
	"phone": "5512345678",
	"latitude": "19.3650650",
	"longitude": "-99.1781010",
	"distance_km": 2.8,
	"google_maps_url": "https://www.google.com/maps/...",
	"weekly_hours": "6:00-19:00",
	"saturday_hours": "7:00-15:00",
	"sunday_hours": "8:00-14:00",
	"today": {
		"is_closed": false,
		"opens_at": "07:00",
		"closes_at": "15:00",
		"label": "07:00 - 15:00"
	},
	"capabilities": [
		{
			"slug": "rayos_x",
			"name": "Rayos X"
		}
	],
	"services": [
		{
			"type": "historia_clinica",
			"name": "Historia Clinica"
		}
	],
	"service_flags": {
		"has_clinical_history": true,
		"has_optical": false
	}
}
```

### `filters`

```json
{
	"brand": "olab",
	"search": null,
	"state": null,
	"municipality": null,
	"postal_code": null,
	"capability": null,
	"service": null,
	"latitude": null,
	"longitude": null,
	"radius": null,
	"sort": "name"
}
```

### `states`

Distinct states available inside the selected active brand scope when `brand` is present. Soft-deleted and inactive stores are excluded.

### `municipalities`

Distinct municipalities available inside the selected active brand scope and selected `state` when present. Soft-deleted and inactive stores are excluded.

### `capabilities`

Active capabilities that have at least one active store in the current brand/state/municipality option scope.

```json
[
	{
		"slug": "mastografia",
		"name": "Mastografia",
		"stores_count": 25
	}
]
```

### `services`

Special service options that have at least one active store in the current brand/state/municipality option scope.

```json
[
	{
		"type": "historia_clinica",
		"name": "Historia Clinica",
		"stores_count": 6
	},
	{
		"type": "optica",
		"name": "Optica",
		"stores_count": 9
	}
]
```

### Counts

```json
{
	"total": 47,
	"filtered_total": 12
}
```

`total` is the active store count in the base brand scope. `filtered_total` includes search, state, municipality, postal code, capability, service, and optional geographic filters.

## Map / Nearby UX

- The map view is a client-side Leaflet component loaded only when the user opens the map.
- Tiles come from OpenStreetMap and the visible attribution must remain available: `© OpenStreetMap contributors`.
- No public OSM geocoding is used. Markers are created only from `latitude` and `longitude` already stored for each branch.
- Stores without coordinates remain visible in list view. They are omitted only from map markers and from geographic nearby queries.
- Marker popups stay compact: name, municipality/state, today's hours, short address, `Ver sucursal`, and external `Como llegar`.
- `Como llegar` continues to open Google Maps as an external link. There is no Google Maps JS, Directions API, routing, ETA, traffic, or turn-by-turn route inside Leaflet.
- The `Cerca de mi` button calls `navigator.geolocation.getCurrentPosition` only after a user click. The browser is not prompted on page load.
- When location is accepted, the frontend requests `latitude`, `longitude`, `radius=10`, and `sort=distance`.
- Radius can be changed to 5, 10, 25, or 50 km. A 0-result radius is not expanded automatically.
- `Quitar ubicacion` removes `latitude`, `longitude`, and `radius`; if sorting by distance, it returns to `sort=name`.
- The user's location is not persisted in the database, localStorage, or analytics by this feature. It is used only in the current request to calculate approximate geographic distance.
- Distance is approximate Haversine distance in kilometers, not driving distance or travel time.

## Data Scope

The public directory query always excludes:

- soft-deleted stores
- stores with `is_active = false`

Relations are eager loaded for the response:

- `hours`
- active `capabilities`
- active `services`

The current data volume is small enough to avoid pagination so the future map/list experience can consume the full filtered result set.
