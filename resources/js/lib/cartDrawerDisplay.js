const FAILURE_STATUSES = ["declined", "error", "failed"];
const APPROVED_STATUSES = ["approved", "completed", "success", "succeeded"];

function normalizeStatus(value) {
	return String(value || "")
		.trim()
		.toLowerCase();
}

export function compactStatusLabel(value) {
	return String(value || "")
		.replace(/^Pago /i, "")
		.replace(/^Intento /i, "")
		.trim();
}

export function displayStatusLabel(value) {
	const compact = compactStatusLabel(value);
	const normalized = normalizeStatus(compact || value);

	return (
		{
			approved: "Aprobado",
			aprobado: "Aprobado",
			completed: "Completado",
			completado: "Completado",
			success: "Aprobado",
			succeeded: "Aprobado",
			synced: "Sincronizado",
			sincronizado: "Sincronizado",
			declined: "Rechazado",
			rechazado: "Rechazado",
			error: "Error",
			failed: "Fallido",
			fallido: "Fallido",
			pending: "Pendiente",
			pendiente: "Pendiente",
			processing: "Procesando",
			procesando: "Procesando",
			current: "Actual",
			actual: "Actual",
			skipped: "Omitido",
			omitido: "Omitido",
		}[normalized] || compact
	);
}

export function paymentHistorySummary(input = []) {
	const items = Array.isArray(input) ? input : input?.items || [];

	if (!items.length) {
		return "Sin registros";
	}

	const allAttempts = items.every((item) => item.type === "payment_attempt");
	const noun = allAttempts ? "intento" : "registro";
	const failed = items.filter((item) =>
		FAILURE_STATUSES.includes(normalizeStatus(item.status)),
	).length;
	const approved = items.filter(
		(item) =>
			APPROVED_STATUSES.includes(normalizeStatus(item.status)) ||
			item.type === "final_payment",
	).length;

	return [
		`${items.length} ${noun}${items.length === 1 ? "" : "s"}`,
		failed ? `${failed} fallo${failed === 1 ? "" : "s"}` : null,
		approved ? `${approved} aprobado${approved === 1 ? "" : "s"}` : null,
	]
		.filter(Boolean)
		.join(" | ");
}
