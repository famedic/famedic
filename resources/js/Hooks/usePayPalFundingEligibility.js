import { useCallback, useEffect, useRef, useState } from "react";
import {
    DEFAULT_PAYPAL_SDK_CONFIG,
    loadPayPalSdk,
} from "@/lib/paypal/loadPayPalSdk";

const INITIAL_STATE = {
    loading: false,
    ready: false,
    error: null,
    paypalEligible: false,
    cardEligible: false,
};

/**
 * Detecta elegibilidad de funding sources sin renderizar botones.
 *
 * @param {string | null | undefined} paypalClientId
 */
export function usePayPalFundingEligibility(paypalClientId) {
    const [state, setState] = useState(() =>
        paypalClientId
            ? { ...INITIAL_STATE, loading: true }
            : { ...INITIAL_STATE },
    );
    const mountedRef = useRef(true);
    const [retryToken, setRetryToken] = useState(0);

    const runCheck = useCallback(async () => {
        if (!paypalClientId) {
            if (mountedRef.current) {
                setState({ ...INITIAL_STATE });
            }
            return;
        }

        if (mountedRef.current) {
            setState((current) => ({
                ...current,
                loading: true,
                error: null,
            }));
        }

        try {
            const paypal = await loadPayPalSdk({
                clientId: paypalClientId,
                ...DEFAULT_PAYPAL_SDK_CONFIG,
            });

            if (!mountedRef.current) {
                return;
            }

            const cardEligible = paypal
                .Buttons({ fundingSource: paypal.FUNDING.CARD })
                .isEligible();
            const paypalEligible = paypal
                .Buttons({ fundingSource: paypal.FUNDING.PAYPAL })
                .isEligible();

            setState({
                loading: false,
                ready: true,
                error: null,
                cardEligible,
                paypalEligible,
            });
        } catch (error) {
            if (!mountedRef.current) {
                return;
            }

            setState({
                loading: false,
                ready: false,
                error:
                    error instanceof Error
                        ? error.message
                        : "No se pudo cargar el SDK de PayPal",
                paypalEligible: false,
                cardEligible: false,
            });
        }
    }, [paypalClientId]);

    useEffect(() => {
        mountedRef.current = true;
        runCheck();

        return () => {
            mountedRef.current = false;
        };
    }, [runCheck, retryToken]);

    const retry = useCallback(() => {
        setRetryToken((value) => value + 1);
    }, []);

    return {
        ...state,
        retry,
    };
}
