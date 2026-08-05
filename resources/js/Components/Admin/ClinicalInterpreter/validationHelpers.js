import { PHARMACY_UI_ENABLED } from "./productScope";

export function validationStatusMeta(status) {
	switch (status) {
		case "confirmed":
			return { label: "Confirmado", color: "emerald" };
		case "corrected":
			return { label: "Corregido", color: "sky" };
		case "ignored":
			return { label: "Ignorado", color: "zinc" };
		case "pending":
		default:
			return { label: "Pendiente", color: "amber" };
	}
}

export function buildValidationItems(matches) {
	const studies = matches?.studies || [];
	// v1.0: only laboratory items enter human validation.
	const medications = PHARMACY_UI_ENABLED ? matches?.medications || [] : [];
	const rows = [...studies, ...medications];

	return rows.map((row) => ({
		detection_id: row.detection_id,
		type: row.type,
		detected_name: row.detected_name,
		match: row.match || null,
		alternatives: row.alternatives || [],
		engine_status: row.engine_status,
		detection_confidence: row.detection_confidence ?? null,
		pipeline: row.pipeline || null,
		selected_catalog_id:
			row.selected_catalog_id || row.match?.catalog_id || null,
		initial_catalog_id:
			row.selected_catalog_id || row.match?.catalog_id || null,
		initial_match_name: row.match?.name || null,
		validation_status: "pending",
	}));
}

export function validationProgress(items) {
	const total = items.length;
	const resolved = items.filter((i) =>
		["confirmed", "corrected", "ignored"].includes(i.validation_status),
	).length;
	const pending = items.filter((i) => i.validation_status === "pending").length;
	const confirmed = items.filter((i) =>
		["confirmed", "corrected"].includes(i.validation_status),
	).length;
	const ignored = items.filter((i) => i.validation_status === "ignored").length;
	const percent = total === 0 ? 0 : Math.round((resolved / total) * 100);
	const complete = total > 0 && pending === 0;

	return {
		total,
		resolved,
		pending,
		confirmed,
		ignored,
		percent,
		complete,
	};
}

export function pipelineStages({
	hasInterpretation,
	hasMatching,
	validationPercent,
	validationComplete,
}) {
	return [
		{
			id: "interpretation",
			label: "Interpretación IA",
			done: Boolean(hasInterpretation),
		},
		{
			id: "matching",
			label: "Matching",
			done: Boolean(hasMatching),
		},
		{
			id: "validation",
			label: "Validación",
			done: Boolean(validationComplete),
			percent: validationPercent,
		},
	];
}
