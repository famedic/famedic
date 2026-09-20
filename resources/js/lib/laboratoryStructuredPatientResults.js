export const STRUCTURED_RESULTS_FETCH_STATE = {
	IDLE: "idle",
	LOADING: "loading",
	AVAILABLE: "available",
	UNAVAILABLE: "unavailable",
	UNAUTHORIZED: "unauthorized",
	FORBIDDEN: "forbidden",
	ERROR: "error",
	OTP_PENDING: "otp_pending",
};

/**
 * @param {{ value?: unknown, value_type?: string, unit?: string | null }} observation
 */
export function formatStructuredObservationValue(observation) {
	const unit = observation?.unit ? String(observation.unit).trim() : "";
	const value = observation?.value;

	if (value === null || value === undefined || value === "") {
		return unit ? `— ${unit}` : "—";
	}

	const valueText = String(value);

	return unit ? `${valueText} ${unit}` : valueText;
}

/**
 * @param {{ text?: string | null, low?: number | null, high?: number | null } | null | undefined} reference
 * @param {string | null | undefined} unit
 */
export function formatStructuredReference(reference, unit = "") {
	if (!reference) {
		return null;
	}

	const text = reference.text ? String(reference.text).trim() : "";
	if (text) {
		return text;
	}

	const low = reference.low;
	const high = reference.high;

	if (low != null && high != null) {
		const suffix = unit ? ` ${unit}` : "";

		return `${low}–${high}${suffix}`;
	}

	if (low != null) {
		return `≥ ${low}${unit ? ` ${unit}` : ""}`;
	}

	if (high != null) {
		return `≤ ${high}${unit ? ` ${unit}` : ""}`;
	}

	return null;
}

/**
 * @param {string | null | undefined} status
 * @param {boolean | null | undefined} abnormal
 */
export function getStructuredObservationStatusPresentation(status, abnormal = false) {
	const normalized = String(status || "unknown").toLowerCase();

	if (normalized === "not_applicable") {
		return {
			label: null,
			showIndicator: false,
			badgeColor: "zinc",
			icon: null,
		};
	}

	if (normalized === "normal") {
		return {
			label: "En rango",
			showIndicator: true,
			badgeColor: "green",
			icon: "check",
		};
	}

	if (normalized === "low" || normalized === "high" || abnormal === true) {
		return {
			label: "Fuera del rango",
			showIndicator: true,
			badgeColor: "amber",
			icon: "warning",
		};
	}

	if (normalized === "unknown") {
		return {
			label: "No se pudo determinar",
			showIndicator: true,
			badgeColor: "zinc",
			icon: "info",
		};
	}

	return {
		label: "No se pudo determinar",
		showIndicator: true,
		badgeColor: "zinc",
		icon: "info",
	};
}

/**
 * @param {number | string | null | undefined} purchaseId
 * @param {{ routeFn?: typeof route, fetchFn?: typeof fetch }} [options]
 */
export async function fetchLaboratoryStructuredResults(purchaseId, options = {}) {
	const routeFn = options.routeFn ?? globalThis.route;
	const fetchFn = options.fetchFn ?? globalThis.fetch;

	if (!purchaseId) {
		return { state: STRUCTURED_RESULTS_FETCH_STATE.UNAVAILABLE, data: null };
	}

	const url = routeFn("laboratory-purchases.structured-results", {
		laboratory_purchase: purchaseId,
	});

	const response = await fetchFn(url, {
		method: "GET",
		credentials: "same-origin",
		headers: { Accept: "application/json" },
	});

	if (response.status === 404) {
		return { state: STRUCTURED_RESULTS_FETCH_STATE.UNAVAILABLE, data: null };
	}

	if (response.status === 401) {
		return { state: STRUCTURED_RESULTS_FETCH_STATE.UNAUTHORIZED, data: null };
	}

	if (response.status === 403) {
		return { state: STRUCTURED_RESULTS_FETCH_STATE.FORBIDDEN, data: null };
	}

	if (!response.ok) {
		return { state: STRUCTURED_RESULTS_FETCH_STATE.ERROR, data: null };
	}

	const payload = await response.json().catch(() => ({}));

	return {
		state: STRUCTURED_RESULTS_FETCH_STATE.AVAILABLE,
		data: payload?.data ?? null,
	};
}
