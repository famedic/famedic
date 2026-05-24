export function buildLaboratoryPurchaseQueryParams(data) {
	return Object.fromEntries(
		Object.entries(data).filter(([, value]) => value !== "" && value != null),
	);
}
