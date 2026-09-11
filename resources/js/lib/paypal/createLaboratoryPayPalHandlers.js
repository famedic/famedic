import axios from "axios";
import { router } from "@inertiajs/react";

function readCsrfToken() {
    return (
        document.querySelector('meta[name="csrf-token"]')?.content || ""
    );
}

/**
 * Factory compartida para callbacks de paypal.Buttons (CARD y PAYPAL).
 *
 * @param {{
 *   laboratoryBrand: string,
 *   patientId: string | number | null,
 *   addressId: string | number,
 *   totalCents: number,
 *   couponId?: number | null,
 *   promoValidationToken?: string | null,
 *   mutex: ReturnType<import('./paymentMutex').createPayPalPaymentMutex>,
 *   onPaymentPhaseChange?: (phase: 'idle' | 'creating' | 'open' | 'capturing') => void,
 *   onUserMessage?: (message: string | null) => void,
 *   onCancelled?: () => void,
 *   isMounted?: () => boolean,
 * }} options
 */
export function createLaboratoryPayPalHandlers({
    laboratoryBrand,
    patientId,
    addressId,
    totalCents,
    couponId = null,
    promoValidationToken = null,
    mutex,
    onPaymentPhaseChange,
    onUserMessage,
    onCancelled,
    isMounted = () => true,
}) {
    const safeSetPhase = (phase) => {
        if (isMounted()) {
            onPaymentPhaseChange?.(phase);
        }
    };

    const safeSetMessage = (message) => {
        if (isMounted()) {
            onUserMessage?.(message);
        }
    };

    const releaseSession = () => {
        mutex.releaseCheckoutSession();
        safeSetPhase("idle");
    };

    const createOrder = async () => {
        safeSetMessage(null);

        return mutex.runCreate(async () => {
            safeSetPhase("creating");

            try {
                const { data } = await axios.post(
                    route("paypal.create-order"),
                    {
                        patient_id: patientId || null,
                        address_id: addressId,
                        laboratory_brand: laboratoryBrand,
                        total: totalCents,
                        coupon_id: couponId,
                        promo_validation_token: promoValidationToken,
                    },
                    {
                        headers: {
                            "X-CSRF-TOKEN": readCsrfToken(),
                            "X-Requested-With": "XMLHttpRequest",
                            Accept: "application/json",
                        },
                    },
                );

                safeSetPhase("open");
                return data.order_id;
            } catch (err) {
                mutex.releaseCheckoutSession();
                safeSetPhase("idle");
                const msg =
                    err.response?.data?.message ||
                    err.message ||
                    "No se pudo iniciar el pago con PayPal.";
                safeSetMessage(msg);
                throw new Error(msg);
            }
        });
    };

    const onApprove = async (data) => {
        safeSetMessage(null);

        return mutex.runCapture(async () => {
            safeSetPhase("capturing");

            try {
                const res = await axios.post(
                    route("paypal.capture-order"),
                    { order_id: data.orderID },
                    {
                        headers: {
                            "X-CSRF-TOKEN": readCsrfToken(),
                            "X-Requested-With": "XMLHttpRequest",
                            Accept: "application/json",
                        },
                    },
                );

                const cap = res.data;

                if (
                    cap.laboratory_purchase_id &&
                    (cap.status === "captured" ||
                        cap.status === "already_processed")
                ) {
                    router.visit(
                        route("laboratory-purchases.show", {
                            laboratory_purchase: cap.laboratory_purchase_id,
                        }),
                    );
                    return;
                }

                safeSetPhase("idle");
                safeSetMessage(
                    "No pudimos confirmar el estado del pago. Verifica la información antes de intentarlo nuevamente.",
                );
            } catch (err) {
                safeSetPhase("idle");
                const msg =
                    err.response?.data?.message ||
                    "No pudimos confirmar el estado del pago. Verifica la información antes de intentarlo nuevamente.";
                safeSetMessage(msg);
                throw err;
            }
        });
    };

    const onCancel = () => {
        releaseSession();
        safeSetMessage(
            "El pago fue cancelado. Puedes intentarlo nuevamente o elegir otro método.",
        );
        onCancelled?.();
    };

    const onError = (err) => {
        console.error(err);
        releaseSession();
        safeSetMessage(
            "Error en el pago con PayPal. Intenta de nuevo o elige otro método.",
        );
    };

    return {
        createOrder,
        onApprove,
        onCancel,
        onError,
    };
}
