export const primaryMetricIds = [
	"total",
	"auto",
	"fallback",
	"review",
	"technical",
];
export const filterKeys = [
	"search",
	"date_from",
	"date_to",
	"decision_status",
	"brand",
	"technical_status",
	"fallback_category",
	"fallback_reason",
	"openai_status",
	"needs_provider_review",
	"rule",
	"mode",
];
export const advancedFilterKeys = filterKeys.slice(5);
export function filterValues(filters = {}) {
	return Object.fromEntries(
		filterKeys.map((key) => [key, filters[key] || ""]),
	);
}
export function filterQuery(values) {
	return Object.fromEntries(
		Object.entries(filterValues(values)).filter(
			([, value]) => value !== "",
		),
	);
}
export const statusLabels = {
	AUTO_CONSOLIDATED: "AUTO · Consolidado",
	FALLBACK_ORIGINAL: "FALLBACK · Originales",
	generated: "Resumen generado",
	stale: "Resumen invalidado",
	queued: "En cola",
	processing: "En proceso",
	failed: "Fallo técnico",
	succeeded: "Ejecución exitosa",
	succeeded_without_summary: "Éxito sin resumen",
	not_run: "Sin ejecución",
	without_summary: "Sin resumen",
	v3: "V3",
	legacy: "Legacy",
	pending: "Sin resumen",
	AI_READY: "Resumen disponible",
	AI_PENDING: "Preparación pendiente",
	AI_FAILED: "Originales por fallo",
	FALLBACK: "Indicaciones originales",
};
export function statusLabel(value) {
	return statusLabels[value] || value || "Sin decisión";
}
export function formatDate(value) {
	return value
		? new Date(value).toLocaleString("es-MX", {
				timeZone: "America/Mexico_City",
				dateStyle: "medium",
				timeStyle: "short",
			})
		: "—";
}
