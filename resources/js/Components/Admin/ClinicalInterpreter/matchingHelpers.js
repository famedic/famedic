export function confidenceLabel(value) {
	if (value == null) return "—";
	return `${Math.round(value * 100)}%`;
}

export function detectionStatusMeta(status) {
	switch (status) {
		case "available":
			return { label: "Disponible", color: "emerald" };
		case "needs_review":
			return { label: "Requiere revisión", color: "amber" };
		default:
			return { label: "Pendiente", color: "zinc" };
	}
}

export function matchStatusMeta(status) {
	switch (status) {
		case "exact":
			return { label: "Coincidencia exacta", color: "emerald" };
		case "partial":
			return { label: "Coincidencia parcial", color: "amber" };
		case "not_found":
			return { label: "No encontrado", color: "red" };
		case "pending_validation":
			return { label: "Pendiente de validación", color: "sky" };
		default:
			return { label: status || "—", color: "zinc" };
	}
}

export function uiStateMeta(state) {
	switch (state) {
		case "searching":
			return { label: "Buscando…", color: "sky" };
		case "analyzing":
			return { label: "Analizando…", color: "sky" };
		case "match_found":
			return { label: "Coincidencia encontrada", color: "emerald" };
		case "not_found":
			return { label: "No encontrado", color: "red" };
		case "needs_validation":
			return { label: "Requiere validación", color: "amber" };
		case "accepted":
			return { label: "Aceptada", color: "emerald" };
		case "ignored":
			return { label: "Ignorada", color: "zinc" };
		case "manual":
			return { label: "Selección manual", color: "sky" };
		default:
			return { label: state || "—", color: "zinc" };
	}
}

export function recomputeSummary(matches) {
	const list = [
		...(matches.medications || []),
		...(matches.studies || []),
	];

	const by = (type, status) =>
		list.filter((m) => m.type === type && m.engine_status === status).length;

	const pending = list.filter((m) => {
		if (m.user_decision === "accepted" || m.user_decision === "manual") {
			return false;
		}
		if (m.user_decision === "ignored") {
			return false;
		}
		return (
			m.ui_state === "needs_validation" ||
			m.ui_state === "not_found" ||
			m.engine_status !== "exact"
		);
	}).length;

	return {
		medications_found: by("medication", "exact"),
		medications_partial: by("medication", "partial"),
		medications_not_found: by("medication", "not_found"),
		studies_found: by("laboratory", "exact"),
		studies_similar: by("laboratory", "partial"),
		studies_not_found: by("laboratory", "not_found"),
		studies_total: list.filter((m) => m.type === "laboratory").length,
		success_rate: (() => {
			const labs = list.filter((m) => m.type === "laboratory");
			if (!labs.length) return 0;
			const success = labs.filter(
				(m) =>
					m.engine_status === "exact" ||
					m.user_decision === "accepted" ||
					m.user_decision === "manual",
			).length;
			return Math.round((success / labs.length) * 100);
		})(),
		pending_validation: pending,
		total_detections: list.length,
		exact_total: list.filter((m) => m.engine_status === "exact").length,
		partial_total: list.filter((m) => m.engine_status === "partial").length,
		not_found_total: list.filter((m) => m.engine_status === "not_found")
			.length,
	};
}
