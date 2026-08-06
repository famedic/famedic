/**
 * Client-side lab preference for Wizard finalize (no Commercial Engine changes).
 */

export function catalogOptionsForItem(item) {
	const opts = [];
	const push = (opt) => {
		if (!opt?.catalog_id) return;
		if (opts.some((o) => o.catalog_id === opt.catalog_id)) return;
		opts.push(opt);
	};
	push(item?.match);
	(item?.alternatives || []).forEach(push);
	return opts;
}

export function collectLaboratoryBrands(items = []) {
	const brands = new Set();
	items
		.filter((i) => ["confirmed", "corrected"].includes(i.validation_status))
		.forEach((item) => {
			catalogOptionsForItem(item).forEach((opt) => {
				const label = opt.laboratory || opt.brand;
				if (label) brands.add(label);
			});
		});
	return [...brands].sort((a, b) => a.localeCompare(b, "es"));
}

function priceCents(opt) {
	if (opt?.price_cents != null && !Number.isNaN(Number(opt.price_cents))) {
		return Number(opt.price_cents);
	}
	const raw = String(opt?.price || "").replace(/[^0-9.]/g, "");
	if (!raw) return Number.POSITIVE_INFINITY;
	return Math.round(parseFloat(raw) * 100);
}

function deliveryScore(opt) {
	const t = String(opt?.delivery_time || "").toLowerCase();
	if (!t) return 60;
	if (t.includes("requiere cita")) return 90;
	if (t.includes("mismo") || t.includes("24")) return 10;
	if (t.includes("48") || t.includes("2 d")) return 25;
	if (t.includes("72") || t.includes("3 d")) return 40;
	return 55;
}

function withChosenMatch(item, chosen) {
	if (!chosen) return null;
	const changed =
		item.initial_catalog_id && chosen.catalog_id !== item.initial_catalog_id;
	return {
		...item,
		match: chosen,
		selected_catalog_id: chosen.catalog_id,
		validation_status: changed ? "corrected" : "confirmed",
		resolution: null,
	};
}

/**
 * @param {'best_price'|'fastest'|'specific'} strategy
 * @param {string|null} brand
 */
export function applyLaboratoryStrategy(items = [], strategy, brand = null) {
	const included = items.filter((i) =>
		["confirmed", "corrected"].includes(i.validation_status),
	);
	const rest = items.filter(
		(i) => !["confirmed", "corrected"].includes(i.validation_status),
	);

	const selected = [];
	const unavailable = [];

	for (const item of included) {
		const opts = catalogOptionsForItem(item);
		let chosen = null;

		if (strategy === "best_price") {
			chosen = [...opts].sort((a, b) => priceCents(a) - priceCents(b))[0] || null;
		} else if (strategy === "fastest") {
			chosen =
				[...opts].sort((a, b) => deliveryScore(a) - deliveryScore(b))[0] ||
				null;
		} else if (strategy === "specific") {
			chosen =
				opts.find((o) => (o.laboratory || o.brand) === brand) || null;
			if (!chosen) {
				unavailable.push(item);
				continue;
			}
		} else {
			chosen = item.match || opts[0] || null;
		}

		const next = withChosenMatch(item, chosen);
		if (next) selected.push(next);
		else unavailable.push(item);
	}

	return {
		selected,
		unavailable,
		/** Items ready to send: selected + non-included (ignored/deferred) + unavailable as ignored */
		composeOrderItems(mode = "include_available_only") {
			const dropped = unavailable.map((item) => ({
				...item,
				validation_status: "ignored",
				resolution: "omitted",
				match: null,
				selected_catalog_id: null,
			}));
			if (mode === "include_available_only") {
				return [...selected, ...rest, ...dropped];
			}
			// accept_anyway: still only include available; unavailable stay omitted
			return [...selected, ...rest, ...dropped];
		},
	};
}

export function summarizeValidationItems(items = []) {
	const total = items.length;
	const confirmed = items.filter((i) => i.validation_status === "confirmed").length;
	const corrected = items.filter((i) => i.validation_status === "corrected").length;
	const omitted = items.filter(
		(i) =>
			i.validation_status === "ignored" &&
			(i.resolution === "omitted" || !i.resolution),
	).length;
	const deferred = items.filter(
		(i) =>
			i.validation_status === "ignored" && i.resolution === "deferred",
	).length;
	const pending = items.filter((i) => i.validation_status === "pending").length;
	const included = confirmed + corrected;
	const allDone = total > 0 && pending === 0;

	return {
		total,
		confirmed,
		corrected,
		included,
		omitted,
		deferred,
		pending,
		/** "Pendientes" in final summary = deferred review */
		pendingReview: deferred,
		allDone,
	};
}

/** API-safe items: strip client-only UI state; keep AI Review transparency fields. */
export function toApiValidationItems(items = []) {
	return items
		.filter((i) => i.type === "laboratory")
		.map((item) => ({
			detection_id: item.detection_id,
			type: item.type || "laboratory",
			validation_status: ["confirmed", "corrected", "ignored", "pending"].includes(
				item.validation_status,
			)
				? item.validation_status
				: "pending",
			resolution: item.resolution || null,
			detected_name: item.detected_name || null,
			selected_catalog_id: item.selected_catalog_id || item.match?.catalog_id || null,
			initial_catalog_id: item.initial_catalog_id || null,
			engine_status: item.engine_status || null,
			detection_confidence: item.detection_confidence ?? null,
			pipeline: item.pipeline
				? {
						synonyms_applied: Boolean(item.pipeline.synonyms_applied),
						abbreviations_expanded: Boolean(
							item.pipeline.abbreviations_expanded,
						),
					}
				: null,
			match: item.match
				? {
						catalog_id: item.match.catalog_id,
						name: item.match.name,
						code: item.match.code,
						sku: item.match.sku,
						laboratory: item.match.laboratory,
						brand: item.match.brand,
						available: item.match.available,
						delivery_time: item.match.delivery_time,
						similarity: item.match.similarity ?? null,
						reason: item.match.reason || item.match.match_reason || null,
						match_status: item.match.match_status || null,
					}
				: null,
			alternatives: (item.alternatives || [])
				.slice(0, 8)
				.map((alt) => ({
					catalog_id: alt.catalog_id,
					name: alt.name,
					similarity: alt.similarity ?? null,
					reason: alt.reason || null,
					laboratory: alt.laboratory || null,
				})),
		}));
}
