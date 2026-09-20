import { useCallback, useEffect, useRef, useState } from "react";
import {
	fetchLaboratoryStructuredResults,
	STRUCTURED_RESULTS_FETCH_STATE,
} from "@/lib/laboratoryStructuredPatientResults";

const INITIAL_STATE = {
	status: STRUCTURED_RESULTS_FETCH_STATE.IDLE,
	data: null,
};

/**
 * @param {number | string | null | undefined} purchaseId
 * @param {{ enabled?: boolean, otpRequired?: boolean, otpVerified?: boolean }} [options]
 */
export function useLaboratoryStructuredResults(
	purchaseId,
	{ enabled = true, otpRequired = false, otpVerified = false } = {},
) {
	const [state, setState] = useState(INITIAL_STATE);
	const requestIdRef = useRef(0);

	const canFetch = Boolean(purchaseId) && enabled && (!otpRequired || otpVerified);

	const load = useCallback(async () => {
		if (!canFetch) {
			setState({
				status: otpRequired && !otpVerified
					? STRUCTURED_RESULTS_FETCH_STATE.OTP_PENDING
					: STRUCTURED_RESULTS_FETCH_STATE.IDLE,
				data: null,
			});
			return;
		}

		const requestId = ++requestIdRef.current;
		setState((prev) => ({
			...prev,
			status: STRUCTURED_RESULTS_FETCH_STATE.LOADING,
		}));

		try {
			const result = await fetchLaboratoryStructuredResults(purchaseId);

			if (requestId !== requestIdRef.current) {
				return;
			}

			setState({
				status: result.state,
				data: result.data,
			});
		} catch {
			if (requestId !== requestIdRef.current) {
				return;
			}

			setState({
				status: STRUCTURED_RESULTS_FETCH_STATE.ERROR,
				data: null,
			});
		}
	}, [canFetch, otpRequired, otpVerified, purchaseId]);

	useEffect(() => {
		void load();
	}, [load]);

	return {
		status: state.status,
		data: state.data,
		reload: load,
		isLoading: state.status === STRUCTURED_RESULTS_FETCH_STATE.LOADING,
		hasStructuredResults: state.status === STRUCTURED_RESULTS_FETCH_STATE.AVAILABLE && Boolean(state.data),
	};
}
