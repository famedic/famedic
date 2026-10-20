export const AI_EXPLANATION_STATUS = {
	IDLE: "idle",
	CONSENT_REQUIRED: "consent_required",
	PENDING: "pending",
	GENERATING: "generating",
	READY: "ready",
	FAILED: "failed",
	INVALID: "invalid",
	FEATURE_DISABLED: "feature_disabled",
	ERROR: "error",
};

export const AI_EXPLANATION_POLL_INTERVAL_MS = 2500;
export const AI_EXPLANATION_POLL_MAX_MS = 60000;

const TERMINAL_STATUSES = new Set([
	AI_EXPLANATION_STATUS.READY,
	AI_EXPLANATION_STATUS.FAILED,
	AI_EXPLANATION_STATUS.INVALID,
	AI_EXPLANATION_STATUS.FEATURE_DISABLED,
]);

export function isAiExplanationTerminalStatus(status) {
	return TERMINAL_STATUSES.has(status);
}

export function isAiExplanationProcessingStatus(status) {
	return status === AI_EXPLANATION_STATUS.PENDING || status === AI_EXPLANATION_STATUS.GENERATING;
}

function getCsrfToken() {
	if (typeof document === "undefined") {
		return "";
	}

	return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

/**
 * @param {number} status
 * @param {string} [fallback]
 */
export function mapAiExplanationHttpError(status, fallback = "") {
	switch (status) {
		case 401:
			return { code: "unauthorized", message: "Tu sesión expiró. Vuelve a iniciar sesión." };
		case 403:
			return { code: "forbidden", message: "No tienes acceso a esta explicación." };
		case 404:
			return { code: "not_found", message: "Esta explicación no está disponible." };
		case 422:
			return { code: "validation", message: "No pudimos procesar tu solicitud. Intenta de nuevo." };
		case 429:
			return { code: "rate_limited", message: "Inténtalo nuevamente en unos momentos." };
		case 500:
		case 502:
		case 503:
			return {
				code: "server",
				message: "Estamos teniendo problemas para preparar la explicación.",
			};
		default:
			return {
				code: "unknown",
				message: fallback || "No pudimos preparar la explicación en este momento.",
			};
	}
}

/**
 * @param {number | string} purchaseId
 * @param {number | string} observationId
 * @param {{ routeFn?: typeof route, fetchFn?: typeof fetch, signal?: AbortSignal }} [options]
 */
export async function requestLaboratoryAiExplanation(purchaseId, observationId, options = {}) {
	const routeFn = options.routeFn ?? globalThis.route;
	const fetchFn = options.fetchFn ?? globalThis.fetch;

	const response = await fetchFn(
		routeFn("laboratory-purchases.structured-results.explanation", {
			laboratory_purchase: purchaseId,
			observation: observationId,
		}),
		{
			method: "POST",
			credentials: "same-origin",
			signal: options.signal,
			headers: {
				Accept: "application/json",
				"Content-Type": "application/json",
				"X-CSRF-TOKEN": getCsrfToken(),
			},
			body: JSON.stringify({}),
		},
	);

	const payload = await response.json().catch(() => ({}));

	if (!response.ok) {
		const mapped = mapAiExplanationHttpError(response.status, payload?.message);
		const error = new Error(mapped.message);
		error.status = response.status;
		error.code = mapped.code;
		throw error;
	}

	return payload?.data ?? {};
}

/**
 * @param {number | string} purchaseId
 * @param {"accept" | "decline"} action
 * @param {{ routeFn?: typeof route, fetchFn?: typeof fetch, signal?: AbortSignal }} [options]
 */
export async function updateLaboratoryAiExplanationConsent(purchaseId, action, options = {}) {
	const routeFn = options.routeFn ?? globalThis.route;
	const fetchFn = options.fetchFn ?? globalThis.fetch;

	const response = await fetchFn(
		routeFn("laboratory-purchases.ai-explanation-consent", {
			laboratory_purchase: purchaseId,
		}),
		{
			method: "POST",
			credentials: "same-origin",
			signal: options.signal,
			headers: {
				Accept: "application/json",
				"Content-Type": "application/json",
				"X-CSRF-TOKEN": getCsrfToken(),
			},
			body: JSON.stringify({ action }),
		},
	);

	const payload = await response.json().catch(() => ({}));

	if (!response.ok) {
		const mapped = mapAiExplanationHttpError(response.status, payload?.message);
		const error = new Error(mapped.message);
		error.status = response.status;
		error.code = mapped.code;
		throw error;
	}

	return payload?.data ?? {};
}
