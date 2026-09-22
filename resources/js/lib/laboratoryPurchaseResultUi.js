const PATIENT_STATUS_LABELS = {
	complete: "Listo",
	legacy_available: "Disponible",
	pending_interpretation: "En proceso",
	awaiting_sample: "Toma de muestra",
	manual_review: "En revisión",
	error: "En seguimiento",
	pending: "En proceso",
};

const PATIENT_STATUS_MESSAGES = {
	complete: "Todos tus estudios ya tienen interpretación final.",
	legacy_available: "Hay un documento de resultados disponible para consultar.",
	pending_interpretation: "El laboratorio ya está procesando tus resultados.",
	awaiting_sample:
		"El siguiente paso es acudir a tu toma de muestra. Cuando el laboratorio la confirme, aquí verás que tus resultados están en proceso.",
	manual_review: "Nuestro equipo está validando tus resultados.",
	error: "Estamos dando seguimiento a tu orden.",
	pending:
		"Ya registramos tu toma de muestra. El laboratorio está procesando tus resultados y te avisaremos cuando estén listos.",
};

/**
 * Estado coherente para UI del paciente: no marcar "listo" sin documento consultable.
 */
export function resolveEffectiveResultStatus(resultControl, hasResults = false) {
	const raw = resultControl?.overall_status || (hasResults ? "legacy_available" : "pending");

	if (
		(raw === "complete" || raw === "legacy_available") &&
		!hasResults &&
		!resultControl?.can_view_results
	) {
		return "pending";
	}

	return raw;
}

export function patientResultStatusLabel(resultControl, hasResults = false) {
	const status = resolveEffectiveResultStatus(resultControl, hasResults);

	return PATIENT_STATUS_LABELS[status] || PATIENT_STATUS_LABELS.pending;
}

export function patientResultStatusMessage(resultControl, hasResults = false) {
	const status = resolveEffectiveResultStatus(resultControl, hasResults);

	return PATIENT_STATUS_MESSAGES[status] || PATIENT_STATUS_MESSAGES.pending;
}

export function patientResultStatusColor(resultControl, hasResults = false) {
	const status = resolveEffectiveResultStatus(resultControl, hasResults);

	if (status === "complete" || status === "legacy_available") return "green";
	if (status === "manual_review" || status === "error") return "red";
	if (status === "pending_interpretation" || status === "pending") return "amber";
	if (status === "awaiting_sample") return "sky";

	return hasResults ? "amber" : "slate";
}
