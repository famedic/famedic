import assert from "node:assert/strict";
import test from "node:test";

import {
	brandSections,
	checkoutSelectedStorePresentation,
	checkoutSelectedStoreUiState,
	compatibleStoresEndpoint,
	compatibleStoresIntroCopy,
	compatibleStoresUiState,
	deleteSelectedLaboratoryStore,
	fetchSelectedLaboratoryStore,
	filterBranchesBySearch,
	formatDistance,
	groupBranchesByMunicipality,
	isValidMexicanPostalCode,
	nearestCompatibleBranch,
	sanitizePostalCodeInput,
	validateMexicanPostalCodeForSearch,
	normalizeCompatibleStoresRequestParams,
	normalizeCompatibleStoresResponse,
	normalizeSelectedStoreResponse,
	postalCodeLocationStatus,
	saveSelectedLaboratoryStore,
	selectedStoreEndpoint,
	selectedStoreErrorMessage,
	selectionLabel,
} from "../../resources/js/lib/laboratoryCompatibleStores.js";

const compatibleBranch = (overrides = {}) => ({
	id: 1,
	name: "Sucursal Cumbres",
	brand: "swisslab",
	isCompatible: true,
	matchLevel: "CATEGORY",
	matchedRequirements: [{ capability: "laboratorio", label: "Laboratorio" }],
	missingRequirements: [],
	distanceKm: null,
	hours: { hoursSummary: "Lun-Vie 7:00-19:00" },
	...overrides,
});

test("builds the compatible stores route with optional query parameters", () => {
	const url = compatibleStoresEndpoint(
		(name) => {
			assert.equal(name, "laboratory.cart.compatible-stores");
			return "/laboratory/cart/compatible-stores";
		},
		{
			latitude: 25.67,
			longitude: -100.32,
			date: "2026-09-20",
			brand: "olab",
			postal_code: "03100",
			ignored: "",
		},
	);

	const parsedUrl = new URL(url, "http://localhost");
	assert.equal(parsedUrl.pathname, "/laboratory/cart/compatible-stores");
	assert.deepEqual(Object.fromEntries(parsedUrl.searchParams.entries()), {
		brand: "olab",
		postal_code: "03100",
		latitude: "25.67",
		longitude: "-100.32",
		date: "2026-09-20",
	});
});

test("builds selected store route names with the current brand", () => {
	const calls = [];
	const routeResolver = (name, params) => {
		calls.push([name, params]);
		return `/ziggy/${name}/${params.laboratory_brand}`;
	};

	assert.equal(
		selectedStoreEndpoint(
			"swisslab",
			"laboratory.checkout.selected-store.show",
			routeResolver,
		),
		"/ziggy/laboratory.checkout.selected-store.show/swisslab",
	);
	assert.deepEqual(calls, [
		[
			"laboratory.checkout.selected-store.show",
			{ laboratory_brand: "swisslab" },
		],
	]);
	assert.equal(
		selectedStoreEndpoint("olab", "laboratory.checkout.selected-store.store", null),
		"/laboratory/olab/checkout/selected-store",
	);
});

test("normalizes missing optional fields defensively", () => {
	const data = normalizeCompatibleStoresResponse({
		data: { branches: null },
	});

	assert.equal(data.resolution_status, "unknown");
	assert.equal(data.is_resolvable, false);
	assert.deepEqual(data.branches, []);
	assert.deepEqual(data.brands, {});
	assert.deepEqual(data.meta, {});
});

test("normalizes selected store responses including stale selections", () => {
	assert.deepEqual(
		normalizeSelectedStoreResponse({
			success: true,
			data: { selected: false, store: null, validation: null },
		}),
		{
			success: true,
			message: null,
			selected: false,
			store: null,
			validation: null,
			reason: null,
		},
	);

	assert.deepEqual(
		normalizeSelectedStoreResponse({
			success: true,
			data: {
				selected: false,
				store: null,
				validation: { status: "stale" },
			},
		}).validation,
		{ status: "stale" },
	);

	assert.equal(
		normalizeSelectedStoreResponse({
			success: true,
			data: {
				selected: true,
				store: { id: 123, name: "Sucursal Centro", brand: "swisslab" },
				validation: { status: "valid" },
			},
		}).store.name,
		"Sucursal Centro",
	);
});

test("calls selected store GET POST and DELETE endpoints", async () => {
	const requests = [];
	const routeResolver = (name, params) =>
		`/laboratory/${params.laboratory_brand}/checkout/selected-store?name=${name}`;
	const axiosClient = {
		get: async (url) => {
			requests.push(["get", url]);
			return {
				data: {
					success: true,
					data: {
						selected: true,
						store: { id: 5, name: "Sucursal", brand: "swisslab" },
						validation: { status: "valid" },
					},
				},
			};
		},
		post: async (url, body) => {
			requests.push(["post", url, body]);
			return {
				data: {
					success: true,
					data: {
						selected: true,
						store: {
							id: body.laboratory_store_id,
							name: "Sucursal",
							brand: "swisslab",
						},
						validation: { status: "valid" },
					},
				},
			};
		},
		delete: async (url) => {
			requests.push(["delete", url]);
			return {
				data: {
					success: true,
					data: { selected: false, store: null, validation: null },
				},
			};
		},
	};

	assert.equal(
		(
			await fetchSelectedLaboratoryStore({
				brand: "swisslab",
				axiosClient,
				routeResolver,
			})
		).store.id,
		5,
	);
	assert.equal(
		(
			await saveSelectedLaboratoryStore({
				brand: "swisslab",
				laboratoryStoreId: 9,
				axiosClient,
				routeResolver,
			})
		).store.id,
		9,
	);
	assert.equal(
		(
			await deleteSelectedLaboratoryStore({
				brand: "swisslab",
				axiosClient,
				routeResolver,
			})
		).selected,
		false,
	);

	assert.deepEqual(requests, [
		[
			"get",
			"/laboratory/swisslab/checkout/selected-store?name=laboratory.checkout.selected-store.show",
		],
		[
			"post",
			"/laboratory/swisslab/checkout/selected-store?name=laboratory.checkout.selected-store.store",
			{ laboratory_store_id: 9 },
		],
		[
			"delete",
			"/laboratory/swisslab/checkout/selected-store?name=laboratory.checkout.selected-store.destroy",
		],
	]);
});

test("maps loading error empty unresolved no-compatible and ready states", () => {
	assert.equal(
		compatibleStoresUiState({ loading: true, error: false, data: null }),
		"loading",
	);
	assert.equal(
		compatibleStoresUiState({ loading: false, error: true, data: null }),
		"error",
	);
	assert.equal(
		compatibleStoresUiState({
			loading: false,
			error: false,
			data: { resolution_status: "empty_cart", branches: [] },
		}),
		"empty",
	);
	assert.equal(
		compatibleStoresUiState({
			loading: false,
			error: false,
			data: {
				is_resolvable: false,
				meta: { cart_items_count: 2 },
				branches: [compatibleBranch({ isCompatible: false })],
			},
		}),
		"unresolved",
	);
	assert.equal(
		compatibleStoresUiState({
			loading: false,
			error: false,
			data: {
				is_resolvable: true,
				meta: { cart_items_count: 2 },
				branches: [compatibleBranch({ isCompatible: false })],
			},
		}),
		"no-compatible",
	);
	assert.equal(
		compatibleStoresUiState({
			loading: false,
			error: false,
			data: {
				is_resolvable: true,
				meta: { cart_items_count: 2 },
				branches: [compatibleBranch()],
			},
		}),
		"ready",
	);
});

test("maps checkout selected store states for the checkout notice", () => {
	assert.equal(
		checkoutSelectedStoreUiState({
			selected: true,
			store: {
				id: 1,
				name: "Sucursal Centro",
				address: "Av. Centro 123",
			},
			validation: { status: "valid" },
		}),
		"valid",
	);

	assert.equal(
		checkoutSelectedStorePresentation({
			selected: true,
			store: {
				id: 1,
				name: "Sucursal Centro",
				address: "Av. Centro 123",
			},
			validation: { status: "valid" },
		}).actionLabel,
		"Cambiar sucursal",
	);

	assert.equal(
		checkoutSelectedStorePresentation({
			selected: false,
			store: null,
			validation: { status: "stale" },
		}).actionLabel,
		"Actualizar sucursal",
	);

	assert.equal(
		checkoutSelectedStorePresentation({
			selected: false,
			store: null,
			validation: { status: "stale" },
		}).title,
		"La recomendación necesita actualizarse",
	);

	assert.equal(
		checkoutSelectedStorePresentation({
			selected: false,
			store: null,
			validation: { status: "invalid", reason: "branch_not_compatible" },
		}).title,
		"Revisa tu sucursal recomendada",
	);

	const missing = checkoutSelectedStorePresentation({
		selected: false,
		store: null,
		validation: null,
	});
	assert.equal(missing.title, "Sucursales recomendadas");
	assert.equal(missing.actionLabel, "Ver sucursales recomendadas");
	assert.equal(missing.tone, "info");
	assert.equal(missing.message.includes("antes de continuar"), false);
});

test("keeps multibrand compatible branches separated", () => {
	const sections = brandSections({
		is_resolvable: true,
		brands: {
			swisslab: {
				branches: [compatibleBranch({ id: 1, brand: "swisslab" })],
			},
			olab: {
				branches: [
					compatibleBranch({ id: 2, brand: "olab" }),
					compatibleBranch({
						id: 3,
						brand: "olab",
						isCompatible: false,
					}),
				],
			},
		},
	});

	assert.deepEqual(
		sections.map((section) => [
			section.brand,
			section.branches.map((b) => b.id),
		]),
		[
			["swisslab", [1]],
			["olab", [2]],
		],
	);
});

test("groups compatible branches by municipality without mixing incompatible rows", () => {
	const groups = groupBranchesByMunicipality([
		compatibleBranch({ id: 1, municipality: "Apodaca" }),
		compatibleBranch({ id: 2, municipality: "Monterrey" }),
		compatibleBranch({ id: 3, municipality: "Apodaca" }),
		compatibleBranch({
			id: 4,
			municipality: "Guadalupe",
			isCompatible: false,
		}),
	]);

	assert.deepEqual(
		groups.map((group) => [
			group.municipality,
			group.branches.map((branch) => branch.id),
		]),
		[
			["Apodaca", [1, 3]],
			["Monterrey", [2]],
		],
	);
});

test("filters branches by store name address and municipality", () => {
	const branches = [
		compatibleBranch({
			id: 1,
			name: "Sucursal Palmas",
			address: "Plaza Las Palmas",
			municipality: "Apodaca",
		}),
		compatibleBranch({
			id: 2,
			name: "Centro",
			address: "Av. Hidalgo",
			municipality: "Monterrey",
		}),
	];

	assert.deepEqual(
		filterBranchesBySearch(branches, "apodaca").map((branch) => branch.id),
		[1],
	);
	assert.deepEqual(
		filterBranchesBySearch(branches, "hidalgo").map((branch) => branch.id),
		[2],
	);
});

test("formats distance and branch selection copy for the UI", () => {
	assert.equal(formatDistance(4.24), "4.2 km");
	assert.equal(formatDistance(12.6), "13 km");
	assert.equal(formatDistance(null), null);
	assert.equal(selectionLabel(false), "Seleccionar sucursal");
	assert.equal(selectionLabel(true), "Sucursal seleccionada");
});

test("builds explicit postal code clear requests without reusing stale params", () => {
	assert.deepEqual(
		normalizeCompatibleStoresRequestParams({
			brand: "swisslab",
			postal_code: "",
			clear_postal_code: true,
		}),
		{
			brand: "swisslab",
			clear_postal_code: "1",
		},
	);

	assert.equal(
		compatibleStoresEndpoint(
			(name) => {
				assert.equal(name, "laboratory.cart.compatible-stores");
				return "/laboratory/cart/compatible-stores";
			},
			{
				brand: "swisslab",
				clear_postal_code: true,
			},
		),
		"/laboratory/cart/compatible-stores?brand=swisslab&clear_postal_code=1",
	);
});

test("validates Mexican postal codes and exposes location status", () => {
	assert.equal(isValidMexicanPostalCode("03100"), true);
	assert.equal(isValidMexicanPostalCode("3100"), false);
	assert.equal(isValidMexicanPostalCode("0310A"), false);
	assert.equal(
		postalCodeLocationStatus({
			meta: { postal_code_location_status: "resolved" },
		}),
		"resolved",
	);
	assert.equal(postalCodeLocationStatus({}), "missing");
});

test("uses proximity copy only when the postal code location is resolved", () => {
	assert.match(
		compatibleStoresIntroCopy("ready", "resolved"),
		/cerca de ti/,
	);
	assert.doesNotMatch(
		compatibleStoresIntroCopy("ready", "unresolved"),
		/cerca de ti/,
	);
	assert.match(
		compatibleStoresIntroCopy("ready", "unresolved"),
		/compatibles para tus estudios/,
	);
	assert.doesNotMatch(
		compatibleStoresIntroCopy("ready", "missing"),
		/cerca de ti/,
	);
	assert.match(
		compatibleStoresIntroCopy("ready", "missing"),
		/compatibles para tus estudios/,
	);
	assert.doesNotMatch(
		compatibleStoresIntroCopy("loading", "missing"),
		/cerca de ti/,
	);
});

test("rejects postal codes longer than five digits without silently truncating", () => {
	assert.equal(sanitizePostalCodeInput("123456"), "123456");

	const validation = validateMexicanPostalCodeForSearch("123456");
	assert.equal(validation.valid, false);
	assert.match(validation.error, /exactamente 5 dígitos/);
	assert.equal(validateMexicanPostalCodeForSearch("03100").valid, true);
	assert.equal(validateMexicanPostalCodeForSearch("03100").postalCode, "03100");

	const incomplete = validateMexicanPostalCodeForSearch("1234");
	assert.equal(incomplete.valid, false);
	assert.match(incomplete.error, /5 dígitos/);
	assert.equal(validateMexicanPostalCodeForSearch("0310A").valid, false);
});

test("selects the nearest compatible branch with distance", () => {
	const branch = nearestCompatibleBranch([
		compatibleBranch({ id: 1, distanceKm: null }),
		compatibleBranch({ id: 2, distanceKm: 1.4 }),
		compatibleBranch({ id: 3, distanceKm: 0.2, isCompatible: false }),
	]);

	assert.equal(branch.id, 2);
});

test("maps selected store 422 compatibility errors to friendly copy", () => {
	assert.equal(
		selectedStoreErrorMessage({
			response: {
				status: 422,
				data: { data: { reason: "branch_not_compatible" } },
			},
		}),
		"Esta sucursal ya no coincide con los requisitos actuales de tus estudios. Actualizamos las recomendaciones.",
	);

	assert.equal(
		selectedStoreErrorMessage({
			response: {
				status: 422,
				data: {
					message: "La sucursal seleccionada no está activa.",
					data: { reason: "branch_inactive" },
				},
			},
		}),
		"La sucursal seleccionada no está activa.",
	);
});
