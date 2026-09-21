export const STATUS_META = {
	NOT_AVAILABLE: { label: "No disponible", color: "slate" },
	RECEIVED: { label: "PDF recibido", color: "sky" },
	PROCESSING: { label: "Procesando", color: "amber" },
	STRUCTURED: { label: "Estructurado", color: "blue" },
	REVIEW: { label: "Revisión", color: "violet" },
	PUBLISHED: { label: "Publicado", color: "emerald" },
	FAILED: { label: "Error", color: "red" },
};

export function statusMeta(status) {
	return STATUS_META[status] || { label: status || "—", color: "zinc" };
}

export function formatDateTime(value) {
	if (!value) return "—";

	try {
		return new Date(value).toLocaleString("es-MX", {
			dateStyle: "medium",
			timeStyle: "short",
		});
	} catch {
		return "—";
	}
}

export function compactNumber(value) {
	return value === null || value === undefined ? "—" : value;
}
