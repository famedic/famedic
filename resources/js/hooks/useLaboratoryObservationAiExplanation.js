import { useCallback, useEffect, useRef, useState } from "react";
import {
	AI_EXPLANATION_POLL_INTERVAL_MS,
	AI_EXPLANATION_POLL_MAX_MS,
	AI_EXPLANATION_STATUS,
	isAiExplanationProcessingStatus,
	isAiExplanationTerminalStatus,
	requestLaboratoryAiExplanation,
	updateLaboratoryAiExplanationConsent,
} from "@/lib/laboratoryAiExplanation";

const INITIAL_STATE = {
	status: AI_EXPLANATION_STATUS.IDLE,
	explanation: null,
	limitations: null,
	errorMessage: null,
	consentModalOpen: false,
	featureAvailable: true,
};

/**
 * @param {{
 *   purchaseId?: number | string | null,
 *   observationId?: number | string | null,
 *   enabled?: boolean,
 * }} options
 */
export function useLaboratoryObservationAiExplanation({
	purchaseId,
	observationId,
	enabled = true,
}) {
	const [state, setState] = useState(INITIAL_STATE);
	const pollingTimerRef = useRef(null);
	const pollingStartedAtRef = useRef(null);
	const abortControllerRef = useRef(null);
	const requestIdRef = useRef(0);

	const clearPolling = useCallback(() => {
		if (pollingTimerRef.current) {
			clearInterval(pollingTimerRef.current);
			pollingTimerRef.current = null;
		}
		pollingStartedAtRef.current = null;
	}, []);

	const abortInFlight = useCallback(() => {
		if (abortControllerRef.current) {
			abortControllerRef.current.abort();
			abortControllerRef.current = null;
		}
	}, []);

	const applyPayload = useCallback((payload) => {
		const status = String(payload?.status || AI_EXPLANATION_STATUS.ERROR);

		if (status === AI_EXPLANATION_STATUS.FEATURE_DISABLED) {
			setState((prev) => ({
				...prev,
				status: AI_EXPLANATION_STATUS.FEATURE_DISABLED,
				featureAvailable: false,
				consentModalOpen: false,
				explanation: null,
				limitations: null,
				errorMessage: null,
			}));

			return { status, terminal: true };
		}

		if (status === AI_EXPLANATION_STATUS.CONSENT_REQUIRED) {
			setState((prev) => ({
				...prev,
				status: AI_EXPLANATION_STATUS.CONSENT_REQUIRED,
				consentModalOpen: true,
				errorMessage: null,
			}));

			return { status, terminal: true };
		}

		setState((prev) => ({
			...prev,
			status,
			explanation: status === AI_EXPLANATION_STATUS.READY ? payload?.explanation ?? null : null,
			limitations: status === AI_EXPLANATION_STATUS.READY ? payload?.limitations ?? null : null,
			errorMessage: null,
			consentModalOpen: false,
		}));

		return { status, terminal: isAiExplanationTerminalStatus(status) };
	}, []);

	const pollOnce = useCallback(async () => {
		if (!purchaseId || !observationId || !enabled) {
			return { status: AI_EXPLANATION_STATUS.IDLE, terminal: true };
		}

		const requestId = ++requestIdRef.current;
		abortInFlight();
		const controller = new AbortController();
		abortControllerRef.current = controller;

		try {
			const payload = await requestLaboratoryAiExplanation(purchaseId, observationId, {
				signal: controller.signal,
			});

			if (requestId !== requestIdRef.current) {
				return { status: AI_EXPLANATION_STATUS.IDLE, terminal: true };
			}

			return applyPayload(payload);
		} catch (error) {
			if (error?.name === "AbortError") {
				return { status: AI_EXPLANATION_STATUS.IDLE, terminal: true };
			}

			if (requestId !== requestIdRef.current) {
				return { status: AI_EXPLANATION_STATUS.IDLE, terminal: true };
			}

			setState((prev) => ({
				...prev,
				status: AI_EXPLANATION_STATUS.ERROR,
				errorMessage: error?.message || "No pudimos preparar la explicación en este momento.",
				consentModalOpen: false,
			}));

			return { status: AI_EXPLANATION_STATUS.ERROR, terminal: true };
		}
	}, [abortInFlight, applyPayload, enabled, observationId, purchaseId]);

	const startPolling = useCallback(() => {
		clearPolling();
		pollingStartedAtRef.current = Date.now();

		pollingTimerRef.current = setInterval(() => {
			const elapsed = Date.now() - (pollingStartedAtRef.current || Date.now());
			if (elapsed >= AI_EXPLANATION_POLL_MAX_MS) {
				clearPolling();
				setState((prev) => ({
					...prev,
					status: AI_EXPLANATION_STATUS.FAILED,
					errorMessage: "No pudimos preparar la explicación en este momento.",
				}));
				return;
			}

			void pollOnce().then(({ status, terminal }) => {
				if (terminal || !isAiExplanationProcessingStatus(status)) {
					clearPolling();
				}
			});
		}, AI_EXPLANATION_POLL_INTERVAL_MS);
	}, [clearPolling, pollOnce]);

	const requestExplanation = useCallback(async () => {
		if (!purchaseId || !observationId || !enabled) {
			return;
		}

		if (state.status === AI_EXPLANATION_STATUS.READY && state.explanation) {
			return;
		}

		setState((prev) => ({
			...prev,
			status: AI_EXPLANATION_STATUS.PENDING,
			errorMessage: null,
		}));

		const { status, terminal } = await pollOnce();

		if (isAiExplanationProcessingStatus(status) && !terminal) {
			startPolling();
		}
	}, [enabled, observationId, pollOnce, purchaseId, startPolling, state.explanation, state.status]);

	const acceptConsent = useCallback(async () => {
		if (!purchaseId) {
			return;
		}

		await updateLaboratoryAiExplanationConsent(purchaseId, "accept");
		setState((prev) => ({ ...prev, consentModalOpen: false }));
		await requestExplanation();
	}, [purchaseId, requestExplanation]);

	const closeConsentModal = useCallback(() => {
		setState((prev) => ({
			...prev,
			consentModalOpen: false,
			status:
				prev.status === AI_EXPLANATION_STATUS.CONSENT_REQUIRED
					? AI_EXPLANATION_STATUS.IDLE
					: prev.status,
		}));
	}, []);

	const retry = useCallback(async () => {
		setState((prev) => ({
			...prev,
			status: AI_EXPLANATION_STATUS.PENDING,
			errorMessage: null,
			explanation: null,
			limitations: null,
		}));
		await requestExplanation();
	}, [requestExplanation]);

	useEffect(() => {
		return () => {
			clearPolling();
			abortInFlight();
			requestIdRef.current += 1;
		};
	}, [abortInFlight, clearPolling, observationId]);

	useEffect(() => {
		clearPolling();
		abortInFlight();
	}, [abortInFlight, clearPolling, observationId, purchaseId]);

	const showAction =
		enabled &&
		state.featureAvailable &&
		state.status !== AI_EXPLANATION_STATUS.FEATURE_DISABLED;

	return {
		...state,
		showAction,
		isProcessing: isAiExplanationProcessingStatus(state.status),
		requestExplanation,
		acceptConsent,
		closeConsentModal,
		retry,
	};
}
