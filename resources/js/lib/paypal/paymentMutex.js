/**
 * Mutex compartido entre botones CARD y PAYPAL.
 *
 * La sesión de checkout permanece activa desde que inicia createOrder hasta
 * onApprove (captura), onCancel o onError. No se libera al recibir el order ID.
 *
 * Deuda backend: cada create-order puede generar una transacción pending distinta.
 */
export function createPayPalPaymentMutex() {
    let checkoutSessionActive = false;
    let createInFlight = false;
    let capturingOrder = false;
    /** @type {Promise<unknown> | null} */
    let createPromise = null;

    return {
        isBusy() {
            return checkoutSessionActive || capturingOrder;
        },
        isCheckoutSessionActive() {
            return checkoutSessionActive;
        },
        isCapturing() {
            return capturingOrder;
        },
        releaseCheckoutSession() {
            checkoutSessionActive = false;
            createInFlight = false;
            createPromise = null;
        },
        /**
         * @template T
         * @param {() => Promise<T>} fn
         * @returns {Promise<T>}
         */
        async runCreate(fn) {
            if (capturingOrder) {
                throw new Error("Pago en proceso");
            }
            if (checkoutSessionActive && createInFlight && createPromise) {
                return createPromise;
            }
            if (checkoutSessionActive && !createInFlight) {
                throw new Error("Ya hay un pago PayPal en curso");
            }

            checkoutSessionActive = true;
            createInFlight = true;
            createPromise = (async () => {
                try {
                    return await fn();
                } catch (error) {
                    checkoutSessionActive = false;
                    throw error;
                } finally {
                    createInFlight = false;
                    createPromise = null;
                }
            })();

            return createPromise;
        },
        /**
         * @template T
         * @param {() => Promise<T>} fn
         * @returns {Promise<T>}
         */
        async runCapture(fn) {
            if (capturingOrder) {
                throw new Error("Captura en proceso");
            }

            capturingOrder = true;
            try {
                return await fn();
            } finally {
                capturingOrder = false;
                checkoutSessionActive = false;
            }
        },
    };
}
