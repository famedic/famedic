export function getCsrf() {
	return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

/**
 * Calls existing interpret API. No backend changes.
 * @param {File} file
 * @returns {Promise<{ ok: true, data: object } | { ok: false, message: string, status?: number }>}
 */
export async function interpretDocument(file) {
	const form = new FormData();
	form.append("document", file);

	try {
		const res = await fetch(route("admin.clinical-interpreter.interpret"), {
			method: "POST",
			headers: {
				Accept: "application/json",
				"X-Requested-With": "XMLHttpRequest",
				"X-CSRF-TOKEN": getCsrf(),
			},
			credentials: "same-origin",
			body: form,
		});

		const data = await res.json().catch(() => ({}));

		if (!res.ok || !data.ok) {
			let message =
				"No pudimos interpretar esta receta. Intenta nuevamente o cambia la foto.";

			if (res.status === 429) {
				message =
					"Hay mucha demanda en este momento. Espera unos segundos e inténtalo de nuevo.";
			} else if (res.status === 422) {
				message =
					"Esta imagen no se pudo usar. Prueba con otra foto más clara y completa.";
			} else if (
				data.error_type === "invalid_json" ||
				data.error_type === "interpretation_failed"
			) {
				message =
					"No logramos leer bien esta receta. Prueba con mejor luz o una captura más nítida.";
			} else if (
				typeof data.message === "string" &&
				data.message &&
				!/json|exception|stack|sql|openai|token/i.test(data.message)
			) {
				message = data.message;
			}

			return { ok: false, message, status: res.status };
		}

		return { ok: true, data };
	} catch {
		return {
			ok: false,
			message:
				"Se interrumpió la conexión. Revisa tu red e intenta nuevamente.",
		};
	}
}

/**
 * Normalize summary fields from interpret payload for the UI.
 */
export function buildInterpretSummary(payload) {
	const interpretation = payload?.interpretation || {};
	const metrics = payload?.interpretation_metrics || {};
	const aiConfig = payload?.ai_config || {};
	// FASE 2: prefer Vision detections, not catalog matching.
	const studies = Array.isArray(interpretation?.studies)
		? interpretation.studies
		: [];

	const overall =
		interpretation?.vision_confidence?.overall ??
		interpretation?.ai_json?.confidence?.overall ??
		null;

	const confidencePct =
		overall != null && !Number.isNaN(Number(overall))
			? Math.round(Number(overall) <= 1 ? Number(overall) * 100 : Number(overall))
			: null;

	const durationMs = metrics.duration_ms ?? metrics.latency_ms ?? null;
	const durationLabel =
		durationMs != null
			? durationMs >= 1000
				? `${(durationMs / 1000).toFixed(1)} s`
				: `${durationMs} ms`
			: null;

	return {
		patientName: interpretation?.patient?.name || null,
		patientAge: interpretation?.patient?.age ?? null,
		patientSex: interpretation?.patient?.sex ?? null,
		studiesCount: studies.length,
		studies: studies.map((s, i) => ({
			id: s.detection_id || s.id || `study-${i}`,
			name: s.detected_name || s.name || "Estudio",
			confidence: s.confidence ?? s.detection_confidence ?? null,
		})),
		confidencePct,
		durationLabel,
		model: metrics.model || aiConfig.model || null,
		sessionId: interpretation?.session_id || null,
		document: payload?.document || null,
		raw: payload,
	};
}

export const PROCESSING_STEPS = [
	{ id: "read", label: "Leyendo documento" },
	{ id: "extract", label: "Extrayendo texto" },
	{ id: "interpret", label: "Interpretando receta" },
	{ id: "detect", label: "Detectando estudios" },
	{ id: "prepare", label: "Preparando resultados" },
];

/** Approximate total for ETA UI (not a hard SLA). */
export const ESTIMATED_PROCESS_MS = 14_000;
