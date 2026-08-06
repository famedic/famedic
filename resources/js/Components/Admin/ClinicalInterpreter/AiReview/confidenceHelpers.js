/**
 * AI Review & Confidence — helpers (consume existing match/validation fields only).
 */

export function reasonToHumanLines(match, pipeline = null) {
	const lines = [];
	const reason = match?.reason || match?.match_reason || null;

	switch (reason) {
		case "exact":
			lines.push("Coincidencia exacta por nombre o sinónimo");
			break;
		case "prefix":
			lines.push("Coincidencia por prefijo del nombre detectado");
			break;
		case "tokens":
			lines.push("Coincidencia por palabras clave del texto");
			break;
		case "similarity":
			lines.push("Coincidencia por similitud de texto");
			break;
		default:
			if (match?.name) {
				lines.push("Selección automática desde el catálogo Famedic");
			}
			break;
	}

	if (pipeline?.synonyms_applied) {
		lines.push("Alias o sinónimo encontrado en el catálogo");
	}
	if (pipeline?.abbreviations_expanded) {
		lines.push("Abreviatura expandida antes del matching");
	}
	if (match?.available !== false && match?.name) {
		lines.push("Disponible en catálogo Famedic");
	}

	return [...new Set(lines)];
}

/**
 * Badge levels for FASE 6.
 * @returns {{ key, label, tone, emoji }}
 */
export function resolveConfidenceBadge(item) {
	const status = item?.validation_status;
	const resolution = item?.resolution;
	const match = item?.match;
	const similarity =
		match?.similarity != null ? Number(match.similarity) : null;
	const notFound =
		!match ||
		item?.engine_status === "not_found" ||
		match?.match_status === "not_found";

	if (status === "corrected") {
		return {
			key: "manual",
			label: "Corrección manual",
			tone: "orange",
			emoji: "🟠",
		};
	}

	if (status === "ignored" && resolution === "omitted") {
		return {
			key: "omitted",
			label: "Omitido",
			tone: "zinc",
			emoji: "○",
		};
	}

	if (notFound) {
		return {
			key: "none",
			label: "Sin coincidencia",
			tone: "red",
			emoji: "🔴",
		};
	}

	if (similarity != null && similarity >= 95) {
		return {
			key: "high",
			label: "Alta confianza",
			tone: "emerald",
			emoji: "🟢",
			percent: similarity,
		};
	}

	if (similarity != null && similarity >= 80) {
		return {
			key: "review",
			label: "Revisado por operador",
			tone: "amber",
			emoji: "🟡",
			percent: similarity,
		};
	}

	if (similarity != null) {
		return {
			key: "review",
			label: "Revisado por operador",
			tone: "amber",
			emoji: "🟡",
			percent: similarity,
		};
	}

	if (status === "confirmed") {
		return {
			key: "high",
			label: "Alta confianza",
			tone: "emerald",
			emoji: "🟢",
		};
	}

	return {
		key: "unknown",
		label: "Confianza no disponible",
		tone: "zinc",
		emoji: "○",
	};
}

export function formatDurationMs(ms) {
	if (ms == null || Number.isNaN(Number(ms))) return null;
	const n = Number(ms);
	if (n < 1000) return `${Math.round(n)} ms`;
	return `${(n / 1000).toFixed(n >= 10000 ? 0 : 1)} segundos`;
}

export function formatConfidencePct(value) {
	if (value == null || Number.isNaN(Number(value))) return null;
	const n = Number(value);
	// Vision often sends 0–1; matching sends 0–100.
	const pct = n > 0 && n <= 1 ? Math.round(n * 100) : Math.round(n);
	return Math.min(100, Math.max(0, pct));
}

/**
 * Build quality metrics from live session or persisted order.
 */
export function buildQualitySnapshot({
	items = [],
	interpretPayload = null,
	order = null,
} = {}) {
	const metrics =
		interpretPayload?.interpretation_metrics ||
		order?.interpretation?.raw_metrics ||
		{};
	const interpretation = interpretPayload?.interpretation || order?.interpretation || {};
	const validation = order?.validation || {};
	const corrections = validation.corrections || [];

	const list =
		items.length > 0
			? items
			: (validation.items || []).map(normalizePersistedItem);

	const detected = list.length;
	const humanCorrections =
		items.length > 0
			? list.filter((i) => i.validation_status === "corrected").length
			: corrections.length;
	const omitted = list.filter(
		(i) =>
			i.validation_status === "ignored" && i.resolution !== "deferred",
	).length;

	const autoMatches = list.filter((i) => {
		if (!i.match) return false;
		if (i.validation_status === "corrected") return false;
		if (
			i.initial_catalog_id &&
			i.selected_catalog_id &&
			i.selected_catalog_id !== i.initial_catalog_id
		) {
			return false;
		}
		return true;
	}).length;

	return {
		detected,
		autoMatches:
			items.length > 0
				? autoMatches
				: Math.max(0, detected - humanCorrections - omitted),
		humanCorrections,
		omitted,
		durationLabel: formatDurationMs(
			metrics.duration_ms ?? interpretation.raw_metrics?.duration_ms,
		),
		model: metrics.model || interpretation.model || null,
		promptVersion:
			metrics.prompt_version || interpretation.prompt_version || null,
		estimatedCost:
			metrics.estimated_cost_usd ??
			interpretation.raw_metrics?.estimated_cost_usd ??
			null,
		promptKey: metrics.prompt_key || interpretation.prompt_key || null,
	};
}

export function normalizePersistedItem(raw = {}) {
	return {
		detection_id: raw.detection_id,
		type: raw.type || "laboratory",
		detected_name: raw.detected_name,
		match: raw.match
			? {
					...raw.match,
					similarity: raw.match.similarity ?? raw.similarity ?? null,
					reason: raw.match.reason ?? raw.reason ?? null,
				}
			: null,
		alternatives: raw.alternatives || [],
		engine_status: raw.engine_status || null,
		pipeline: raw.pipeline || null,
		detection_confidence: raw.detection_confidence ?? null,
		selected_catalog_id: raw.selected_catalog_id || raw.match?.catalog_id || null,
		initial_catalog_id: raw.initial_catalog_id || null,
		validation_status: raw.validation_status || "confirmed",
		resolution: raw.resolution || null,
		name: raw.name || raw.match?.name || null,
		laboratory: raw.laboratory || raw.match?.laboratory || null,
		price: raw.price || raw.match?.price || null,
	};
}

/**
 * Infer human findings from warnings + validation items.
 */
export function buildFindings({
	items = [],
	interpretPayload = null,
	order = null,
} = {}) {
	const interpretation =
		interpretPayload?.interpretation || order?.interpretation || {};
	const warnings = [
		...(interpretation.warnings || []),
		...(interpretPayload?.vision?.raw_json?.warnings || []),
		...(interpretation.ai_json?.warnings || []),
	]
		.map((w) => (typeof w === "string" ? w : w?.message || ""))
		.filter(Boolean);

	const list =
		items.length > 0
			? items
			: (order?.validation?.items || []).map(normalizePersistedItem);

	const names = list.map((i) => (i.detected_name || "").trim().toLowerCase()).filter(Boolean);
	const nameCounts = names.reduce((acc, n) => {
		acc[n] = (acc[n] || 0) + 1;
		return acc;
	}, {});
	const hasDuplicates = Object.values(nameCounts).some((c) => c > 1);

	const hasSimilar = list.some((i) => {
		const alts = (i.alternatives || []).filter(
			(a) => a.catalog_id !== i.match?.catalog_id,
		);
		return alts.some((a) => Number(a.similarity) >= 85);
	});

	const hasAmbiguous = list.some(
		(i) =>
			i.engine_status === "partial" ||
			(i.alternatives || []).filter((a) => Number(a.similarity) >= 92)
				.length >= 2,
	);

	const warningBlob = warnings.join(" ").toLowerCase();
	const hasIllegible =
		/ilegibl|poco legib|borros|unclear|low.?quality|no legible/.test(
			warningBlob,
		) ||
		list.some((i) => {
			const c = formatConfidencePct(i.detection_confidence);
			return c != null && c < 55;
		});

	const findings = [];
	if (hasDuplicates) findings.push({ id: "duplicates", label: "Estudios repetidos" });
	if (hasSimilar) findings.push({ id: "similar", label: "Estudios similares" });
	if (hasAmbiguous) findings.push({ id: "ambiguous", label: "Estudios potencialmente ambiguos" });
	if (hasIllegible) findings.push({ id: "illegible", label: "Escritura poco legible" });

	return findings;
}

export function buildLearningInsights({ items = [], order = null } = {}) {
	const list =
		items.length > 0
			? items
			: (order?.validation?.items || []).map(normalizePersistedItem);
	const corrections = order?.validation?.corrections || [];

	const hasManualCorrection =
		list.some((i) => i.validation_status === "corrected") ||
		corrections.length > 0;

	const hasNewMatch = list.some((i) => {
		if (i.validation_status !== "corrected") return false;
		return Boolean(i.match?.name || i.selected_catalog_id);
	});

	const hasNewVariant = list.some((i) => {
		const detected = (i.detected_name || "").trim().toLowerCase();
		const chosen = (i.match?.name || i.name || "").trim().toLowerCase();
		if (!detected || !chosen) return false;
		return detected !== chosen;
	});

	const signals = [];
	if (hasManualCorrection) signals.push("Corrección manual");
	if (hasNewMatch) signals.push("Nueva coincidencia");
	if (hasNewVariant) signals.push("Nueva variante encontrada");

	return signals;
}

export function buildDecisionHistory(order) {
	if (!order) return [];
	const summary = order.summary || {};
	const validation = order.validation || {};
	const checkout = order.integrations?.checkout || {};
	const events = order.integrations?.timeline?.events || [];
	const operator =
		validation.operator_name ||
		summary.operator?.name ||
		summary.operator ||
		null;

	const findEvent = (id) => events.find((e) => e.id === id);

	const steps = [
		{
			id: "interpret",
			label: "Interpretación IA",
			at: order.document?.uploaded_at || summary.created_at,
			actor: "AI Laboratory Interpreter",
			done: true,
		},
		{
			id: "matching",
			label: "Matching",
			at: order.document?.uploaded_at || summary.created_at,
			actor: "Matching Engine",
			done: true,
		},
		{
			id: "validation",
			label: "Validación humana",
			at: validation.validated_at || summary.validated_at,
			actor: operator || "Operador",
			done: Boolean(validation.validated_at || summary.validated_at),
		},
		{
			id: "order",
			label: "Laboratory Order",
			at: summary.created_at,
			actor: operator || "Sistema",
			done: true,
		},
		{
			id: "checkout",
			label: "Checkout iniciado",
			at:
				findEvent("checkout_prepared")?.at ||
				checkout.prepared_at ||
				null,
			actor: checkout.customer_name || "Checkout Famedic",
			done: Boolean(
				checkout.checkout_url ||
					findEvent("checkout_prepared") ||
					summary.status === "checkout_started" ||
					summary.status === "completed",
			),
		},
		{
			id: "purchase",
			label: "Compra completada",
			at: findEvent("payment_completed")?.at || checkout.paid_at || null,
			actor: "Famedic",
			done: summary.status === "completed",
		},
	];

	return steps;
}
