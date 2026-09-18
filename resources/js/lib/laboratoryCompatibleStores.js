export function compatibleStoresEndpoint(routeResolver, params = {}) {
	const baseUrl =
		typeof routeResolver === "function"
			? routeResolver("laboratory.cart.compatible-stores")
			: "/laboratory/cart/compatible-stores";

	const query = new URLSearchParams();
	const normalizedParams = normalizeCompatibleStoresRequestParams(params);

	for (const [key, value] of Object.entries(normalizedParams)) {
		if (value !== null && value !== undefined && value !== "") {
			query.set(key, String(value));
		}
	}

	const queryString = query.toString();

	return queryString ? `${baseUrl}?${queryString}` : baseUrl;
}

export function normalizeCompatibleStoresRequestParams(params = {}) {
	const normalized = {
		brand: params.brand,
	};

	if (params.postal_code) {
		normalized.postal_code = params.postal_code;
	}

	if (params.clear_postal_code) {
		normalized.clear_postal_code = "1";
	}

	if (params.latitude !== null && params.latitude !== undefined && params.latitude !== "") {
		normalized.latitude = params.latitude;
	}

	if (params.longitude !== null && params.longitude !== undefined && params.longitude !== "") {
		normalized.longitude = params.longitude;
	}

	if (params.date) {
		normalized.date = params.date;
	}

	return normalized;
}

export function selectedStoreEndpoint(
	brand,
	routeName = "laboratory.checkout.selected-store.show",
	routeResolver = globalThis.route,
) {
	if (typeof routeResolver === "function") {
		return routeResolver(routeName, { laboratory_brand: brand });
	}

	return `/laboratory/${brand}/checkout/selected-store`;
}

export async function fetchCompatibleLaboratoryStores({
	axiosClient = globalThis.window?.axios,
	routeResolver = globalThis.route,
	params = {},
} = {}) {
	if (!axiosClient?.get) {
		throw new Error("HTTP client is not available");
	}

	const { data } = await axiosClient.get(
		compatibleStoresEndpoint(routeResolver, params),
	);

	return normalizeCompatibleStoresResponse(data);
}

export async function fetchSelectedLaboratoryStore({
	brand,
	axiosClient = globalThis.window?.axios,
	routeResolver = globalThis.route,
} = {}) {
	if (!axiosClient?.get) {
		throw new Error("HTTP client is not available");
	}

	const { data } = await axiosClient.get(
		selectedStoreEndpoint(
			brand,
			"laboratory.checkout.selected-store.show",
			routeResolver,
		),
	);

	return normalizeSelectedStoreResponse(data);
}

export async function saveSelectedLaboratoryStore({
	brand,
	laboratoryStoreId,
	axiosClient = globalThis.window?.axios,
	routeResolver = globalThis.route,
} = {}) {
	if (!axiosClient?.post) {
		throw new Error("HTTP client is not available");
	}

	const { data } = await axiosClient.post(
		selectedStoreEndpoint(
			brand,
			"laboratory.checkout.selected-store.store",
			routeResolver,
		),
		{ laboratory_store_id: laboratoryStoreId },
	);

	return normalizeSelectedStoreResponse(data);
}

export async function deleteSelectedLaboratoryStore({
	brand,
	axiosClient = globalThis.window?.axios,
	routeResolver = globalThis.route,
} = {}) {
	if (!axiosClient?.delete) {
		throw new Error("HTTP client is not available");
	}

	const { data } = await axiosClient.delete(
		selectedStoreEndpoint(
			brand,
			"laboratory.checkout.selected-store.destroy",
			routeResolver,
		),
	);

	return normalizeSelectedStoreResponse(data);
}

export function normalizeCompatibleStoresResponse(payload) {
	const data = payload?.data ?? payload ?? {};

	return {
		resolution_status: data.resolution_status ?? "unknown",
		is_resolvable: Boolean(data.is_resolvable),
		confidence: data.confidence ?? null,
		unresolved_reasons: Array.isArray(data.unresolved_reasons)
			? data.unresolved_reasons
			: [],
		brands: isPlainObject(data.brands) ? data.brands : {},
		branches: Array.isArray(data.branches) ? data.branches : [],
		compatible_branches_count: Number.isFinite(
			Number(data.compatible_branches_count),
		)
			? Number(data.compatible_branches_count)
			: 0,
		reasons: Array.isArray(data.reasons) ? data.reasons : [],
		meta: isPlainObject(data.meta) ? data.meta : {},
	};
}

export function normalizeSelectedStoreResponse(payload) {
	const data = payload?.data ?? payload ?? {};

	return {
		success: payload?.success ?? true,
		message: payload?.message ?? null,
		selected: Boolean(data.selected),
		store: isPlainObject(data.store) ? data.store : null,
		validation: isPlainObject(data.validation) ? data.validation : null,
		reason: data.reason ?? null,
	};
}

export function compatibleStoresUiState({ loading, error, data }) {
	if (loading) return "loading";
	if (error) return "error";

	const normalized = normalizeCompatibleStoresResponse(data);
	const cartItemsCount = Number(normalized.meta.cart_items_count ?? 0);

	if (
		normalized.resolution_status === "empty_cart" ||
		(cartItemsCount === 0 && normalized.branches.length === 0)
	) {
		return "empty";
	}

	if (!normalized.is_resolvable) {
		return "unresolved";
	}

	if (compatibleBranches(normalized.branches).length === 0) {
		return "no-compatible";
	}

	return "ready";
}

export function compatibleBranches(branches = []) {
	return Array.isArray(branches)
		? branches.filter((branch) => branch?.isCompatible === true)
		: [];
}

export function brandSections(data) {
	const normalized = normalizeCompatibleStoresResponse(data);
	const entries = Object.entries(normalized.brands || {});

	if (entries.length === 0) {
		return [
			{
				brand: null,
				branches: compatibleBranches(normalized.branches),
			},
		];
	}

	return entries
		.map(([brand, value]) => ({
			brand,
			branches: compatibleBranches(value?.branches),
		}))
		.filter((section) => section.branches.length > 0);
}

export function storeMunicipality(branch) {
	return (
		branch?.municipality ||
		branch?.city ||
		branch?.state ||
		"Municipio por confirmar"
	);
}

export function groupBranchesByMunicipality(branches = []) {
	const groups = new Map();

	compatibleBranches(branches).forEach((branch) => {
		const municipality = storeMunicipality(branch);
		if (!groups.has(municipality)) {
			groups.set(municipality, []);
		}
		groups.get(municipality).push(branch);
	});

	return Array.from(groups.entries()).map(([municipality, groupBranches]) => ({
		municipality,
		branches: groupBranches,
	}));
}

export function filterBranchesBySearch(branches = [], query = "") {
	const normalizedQuery = query.trim().toLocaleLowerCase();

	if (!normalizedQuery) {
		return branches;
	}

	return branches.filter((branch) =>
		[
			branch?.name,
			branch?.address,
			branch?.municipality,
			branch?.city,
			branch?.state,
		]
			.filter(Boolean)
			.some((value) =>
				String(value).toLocaleLowerCase().includes(normalizedQuery),
			),
	);
}

export function isValidMexicanPostalCode(postalCode = "") {
	return /^\d{5}$/.test(String(postalCode).trim());
}

export function validateMexicanPostalCodeForSearch(postalCode = "") {
	const digits = String(postalCode).trim().replace(/\D/g, "");

	if (digits.length === 0) {
		return {
			valid: false,
			error: "Ingresa un código postal mexicano de 5 dígitos.",
		};
	}

	if (digits.length > 5) {
		return {
			valid: false,
			error: "El código postal debe tener exactamente 5 dígitos.",
		};
	}

	if (digits.length < 5) {
		return {
			valid: false,
			error: "Ingresa un código postal mexicano de 5 dígitos.",
		};
	}

	return {
		valid: true,
		error: null,
		postalCode: digits,
	};
}

export function sanitizePostalCodeInput(value = "") {
	return String(value).replace(/\D/g, "");
}

export function compatibleStoresIntroCopy(state, locationStatus = "missing") {
	if (state !== "ready") {
		return "Famedic puede recomendar sucursales con base en los requisitos conocidos de los estudios de tu carrito.";
	}

	if (locationStatus === "resolved") {
		return "Encontramos sucursales compatibles cerca de ti. Puedes elegir una ahora o continuar sin seleccionar.";
	}

	return "Encontramos sucursales compatibles para tus estudios. Puedes elegir una ahora o continuar sin seleccionar.";
}

export function postalCodeLocationStatus(data) {
	return normalizeCompatibleStoresResponse(data).meta
		?.postal_code_location_status ?? "missing";
}

export function nearestCompatibleBranch(branches = []) {
	return compatibleBranches(branches).find(
		(branch) =>
			branch?.distanceKm !== null &&
			branch?.distanceKm !== undefined &&
			branch?.distanceKm !== "" &&
			Number.isFinite(Number(branch.distanceKm)),
	) ?? null;
}

export function branchesExcept(branches = [], excludedBranch = null) {
	if (!excludedBranch) return branches;

	return branches.filter((branch) => branch?.id !== excludedBranch.id);
}

export function formatDistance(distanceKm) {
	if (distanceKm === null || distanceKm === undefined || distanceKm === "") {
		return null;
	}

	const distance = Number(distanceKm);

	if (!Number.isFinite(distance)) return null;

	return `${distance.toFixed(distance < 10 ? 1 : 0)} km`;
}

export function formatHours(hours) {
	return hours?.hoursSummary || null;
}

export function readableRequirement(requirement) {
	return requirement?.label || requirement?.capability || null;
}

export function safeReason(reason) {
	const publicReasons = {
		no_active_stores_for_brand:
			"No hay sucursales activas para una de las marcas del carrito.",
		empty_cart: "El carrito no tiene estudios.",
	};

	return publicReasons[reason] ?? null;
}

export function selectionLabel(isSelected) {
	return isSelected ? "Sucursal seleccionada" : "Seleccionar sucursal";
}

export function selectedStoreErrorMessage(error) {
	const response = error?.response?.data;
	const reason = response?.data?.reason;

	if (
		reason === "branch_not_compatible" ||
		reason === "cart_not_resolvable"
	) {
		return "Esta sucursal ya no coincide con los requisitos actuales de tus estudios. Actualizamos las recomendaciones.";
	}

	return (
		response?.message ||
		"No pudimos guardar la sucursal seleccionada. Intenta nuevamente."
	);
}

export function checkoutSelectedStoreUiState(selection) {
	const normalized = normalizeSelectedStoreResponse(selection);

	if (
		normalized.selected &&
		normalized.store &&
		normalized.validation?.status === "valid"
	) {
		return "valid";
	}

	if (normalized.validation?.status === "stale") {
		return "stale";
	}

	if (normalized.validation?.status === "invalid") {
		return "invalid";
	}

	return "missing";
}

export function checkoutSelectedStorePresentation(selection) {
	const state = checkoutSelectedStoreUiState(selection);
	const normalized = normalizeSelectedStoreResponse(selection);

	if (state === "valid") {
		return {
			state,
			tone: "success",
			title: "Sucursal seleccionada",
			message:
				normalized.store?.address ||
				"Compatible con los requisitos conocidos de tus estudios.",
			actionLabel: "Cambiar sucursal",
			storeName: normalized.store?.name ?? null,
		};
	}

	if (state === "stale") {
		return {
			state,
			tone: "warning",
			title: "La recomendación necesita actualizarse",
			message:
				"Los estudios de tu carrito cambiaron y necesitamos actualizar la recomendación de sucursal.",
			actionLabel: "Actualizar sucursal",
			storeName: null,
		};
	}

	if (state === "invalid") {
		return {
			state,
			tone: "warning",
			title: "Revisa tu sucursal recomendada",
			message:
				"La sucursal seleccionada ya no coincide con los requisitos actuales de tus estudios.",
			actionLabel: "Elegir otra sucursal",
			storeName: null,
		};
	}

	return {
		state,
		tone: "info",
		title: "Sucursales recomendadas",
		message:
			"Famedic encontró sucursales que cumplen con los requisitos conocidos de tus estudios. Puedes elegir una si lo deseas.",
		actionLabel: "Ver sucursales recomendadas",
		storeName: null,
	};
}

function isPlainObject(value) {
	return value !== null && typeof value === "object" && !Array.isArray(value);
}
