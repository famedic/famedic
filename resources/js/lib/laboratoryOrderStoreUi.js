export function hasConfirmedLaboratoryStore(appointment) {
	return Boolean(appointment?.laboratory_store);
}

export function hasPreferredLaboratoryStore(purchase) {
	return Boolean(purchase?.preferred_laboratory_store);
}

export function hasLaboratoryStoreTabContext(purchase, appointment) {
	return (
		hasPreferredLaboratoryStore(purchase) ||
		hasConfirmedLaboratoryStore(appointment)
	);
}

export function buildLaboratoryOrderTabs(hasStoreContext) {
	return [
		{ key: "summary", label: "Resumen" },
		{
			key: "store",
			label: hasStoreContext ? "Sucursal" : "Sucursales",
		},
		{ key: "instructions", label: "Instrucciones" },
	];
}

export function formatStoreHours(store) {
	if (!store) return null;

	const parts = [
		store.weekly_hours ? `Lun–vie: ${store.weekly_hours}` : null,
		store.saturday_hours ? `Sáb: ${store.saturday_hours}` : null,
		store.sunday_hours ? `Dom: ${store.sunday_hours}` : null,
	].filter(Boolean);

	return parts.length > 0 ? parts.join(" · ") : null;
}

export function formatStoreLocationLines(store) {
	if (!store) return [];

	const lines = [];

	if (store.address?.trim()) {
		lines.push(store.address.trim());
	}

	const locality = [
		store.neighborhood,
		store.postal_code,
	]
		.filter(Boolean)
		.join(", ");

	if (locality) {
		lines.push(locality);
	}

	const region = [store.state, store.municipality || store.city]
		.filter(Boolean)
		.join(", ");

	if (region) {
		lines.push(region);
	}

	return lines;
}

export function normalizeLaboratoryOrderTab(tab) {
	if (!tab) return "summary";

	const normalized = String(tab).toLowerCase();

	if (normalized === "patient" || normalized === "resumen" || normalized === "summary") {
		return "summary";
	}

	if (
		normalized === "store" ||
		normalized === "stores" ||
		normalized === "sucursal" ||
		normalized === "sucursales" ||
		normalized === "branch" ||
		normalized === "branches"
	) {
		return "store";
	}

	if (normalized === "instructions" || normalized === "instrucciones") {
		return "instructions";
	}

	if (normalized === "invoice" || normalized === "facturas") {
		return "invoice";
	}

	return "summary";
}
