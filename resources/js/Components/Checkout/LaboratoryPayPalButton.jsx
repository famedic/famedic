import { useEffect, useMemo, useRef, useState } from "react";
import clsx from "clsx";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import { loadPayPalSdk } from "@/lib/paypal/loadPayPalSdk";
import { createPayPalPaymentMutex } from "@/lib/paypal/paymentMutex";
import { createLaboratoryPayPalHandlers } from "@/lib/paypal/createLaboratoryPayPalHandlers";

const BUTTON_STYLE = { layout: "vertical", label: "pay" };

/**
 * Checkout PayPal de laboratorio: botones CARD y PAYPAL independientes en área principal.
 */
export default function LaboratoryPayPalButton({
    paypalClientId,
    fundingEligibility,
    laboratoryBrand,
    patientId,
    addressId,
    totalCents,
    couponId = null,
    promoValidationToken = null,
    disabled = false,
}) {
    const cardContainerRef = useRef(null);
    const paypalContainerRef = useRef(null);
    const cardActionsRef = useRef(null);
    const paypalActionsRef = useRef(null);
    const mountedRef = useRef(true);
    const mutexRef = useRef(createPayPalPaymentMutex());

    const [sdkLoading, setSdkLoading] = useState(true);
    const [paymentPhase, setPaymentPhase] = useState("idle");
    const [userMessage, setUserMessage] = useState(null);

    const {
        loading: eligibilityLoading,
        ready: eligibilityReady,
        error: eligibilityError,
        cardEligible,
        paypalEligible,
        retry: retryEligibility,
    } = fundingEligibility ?? {
        loading: true,
        ready: false,
        error: null,
        cardEligible: false,
        paypalEligible: false,
        retry: () => {},
    };

    const anyEligible = cardEligible || paypalEligible;
    const checkoutBusy = paymentPhase !== "idle";

    const safeSetPaymentPhase = (phase) => {
        if (mountedRef.current) {
            setPaymentPhase(phase);
        }
    };

    const safeSetUserMessage = (message) => {
        if (mountedRef.current) {
            setUserMessage(message);
        }
    };

    const syncButtonInteractivity = (busy) => {
        if (busy) {
            cardActionsRef.current?.disable?.();
            paypalActionsRef.current?.disable?.();
            return;
        }

        if (cardEligible) {
            cardActionsRef.current?.enable?.();
        }
        if (paypalEligible) {
            paypalActionsRef.current?.enable?.();
        }
    };

    const handlers = useMemo(
        () =>
            createLaboratoryPayPalHandlers({
                laboratoryBrand,
                patientId,
                addressId,
                totalCents,
                couponId,
                promoValidationToken,
                mutex: mutexRef.current,
                onPaymentPhaseChange: safeSetPaymentPhase,
                onUserMessage: safeSetUserMessage,
                isMounted: () => mountedRef.current,
            }),
        [
            laboratoryBrand,
            patientId,
            addressId,
            totalCents,
            couponId,
            promoValidationToken,
        ],
    );

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            mutexRef.current.releaseCheckoutSession();
            cardActionsRef.current = null;
            paypalActionsRef.current = null;
        };
    }, []);

    useEffect(() => {
        syncButtonInteractivity(checkoutBusy || disabled);
    }, [checkoutBusy, disabled, cardEligible, paypalEligible]);

    useEffect(() => {
        if (
            !paypalClientId ||
            disabled ||
            eligibilityLoading ||
            !eligibilityReady ||
            !anyEligible
        ) {
            if (mountedRef.current) {
                setSdkLoading(false);
            }
            return;
        }

        let cancelled = false;
        let cardButtons = null;
        let paypalButtons = null;

        if (mountedRef.current) {
            setSdkLoading(true);
            setUserMessage(null);
        }

        (async () => {
            try {
                const paypal = await loadPayPalSdk({ clientId: paypalClientId });
                if (cancelled || !mountedRef.current) {
                    return;
                }

                const sharedOptions = {
                    style: BUTTON_STYLE,
                    createOrder: handlers.createOrder,
                    onApprove: handlers.onApprove,
                    onCancel: handlers.onCancel,
                    onError: handlers.onError,
                };

                if (
                    cardEligible &&
                    cardContainerRef.current &&
                    cardContainerRef.current.childElementCount === 0
                ) {
                    cardButtons = paypal.Buttons({
                        ...sharedOptions,
                        fundingSource: paypal.FUNDING.CARD,
                        onInit: (_data, actions) => {
                            cardActionsRef.current = actions;
                            syncButtonInteractivity(checkoutBusy || disabled);
                        },
                    });
                    if (cardButtons.isEligible()) {
                        await cardButtons.render(cardContainerRef.current);
                    }
                }

                if (
                    paypalEligible &&
                    paypalContainerRef.current &&
                    paypalContainerRef.current.childElementCount === 0
                ) {
                    paypalButtons = paypal.Buttons({
                        ...sharedOptions,
                        fundingSource: paypal.FUNDING.PAYPAL,
                        onInit: (_data, actions) => {
                            paypalActionsRef.current = actions;
                            syncButtonInteractivity(checkoutBusy || disabled);
                        },
                    });
                    if (paypalButtons.isEligible()) {
                        await paypalButtons.render(paypalContainerRef.current);
                    }
                }
            } catch (error) {
                console.error(error);
                if (mountedRef.current && !cancelled) {
                    safeSetUserMessage(
                        "No pudimos cargar PayPal. Reintenta o elige otro método de pago.",
                    );
                }
            } finally {
                if (mountedRef.current && !cancelled) {
                    setSdkLoading(false);
                }
            }
        })();

        return () => {
            cancelled = true;
            cardButtons?.close?.();
            paypalButtons?.close?.();
            cardActionsRef.current = null;
            paypalActionsRef.current = null;
            if (cardContainerRef.current) {
                cardContainerRef.current.innerHTML = "";
            }
            if (paypalContainerRef.current) {
                paypalContainerRef.current.innerHTML = "";
            }
        };
    }, [
        paypalClientId,
        disabled,
        eligibilityLoading,
        eligibilityReady,
        anyEligible,
        cardEligible,
        paypalEligible,
        handlers,
    ]);

    if (!paypalClientId) {
        return (
            <Text className="text-sm text-amber-600">
                PayPal no está configurado en este entorno.
            </Text>
        );
    }

    const showCardSection = eligibilityReady && cardEligible;
    const showPaypalSection = eligibilityReady && paypalEligible;
    const showSeparator = showCardSection && showPaypalSection;

    return (
        <section
            aria-labelledby="laboratory-paypal-checkout-heading"
            aria-busy={checkoutBusy}
            className={clsx(
                "w-full max-w-full space-y-5 rounded-lg border border-[#003087]/15 bg-white p-4 shadow-sm sm:p-6",
                "dark:border-[#009cde]/20 dark:bg-slate-900",
                disabled && "opacity-60",
            )}
        >
            <div className="space-y-1">
                <Subheading id="laboratory-paypal-checkout-heading">
                    Elige cómo pagar con PayPal
                </Subheading>
                {(eligibilityLoading || sdkLoading) && (
                    <Text className="text-sm text-zinc-500">
                        Cargando opciones de pago…
                    </Text>
                )}
                {paymentPhase === "creating" && (
                    <Text className="text-sm text-zinc-600 dark:text-slate-400">
                        Creando orden de pago…
                    </Text>
                )}
                {paymentPhase === "open" && (
                    <Text className="text-sm text-zinc-600 dark:text-slate-400">
                        Completa el pago en PayPal para continuar.
                    </Text>
                )}
                {paymentPhase === "capturing" && (
                    <Text className="text-sm text-zinc-600 dark:text-slate-400">
                        Procesando pago…
                    </Text>
                )}
            </div>

            {eligibilityError && (
                <PayPalAlert
                    message="No pudimos cargar PayPal. Reintenta o elige otro método de pago."
                    onRetry={retryEligibility}
                />
            )}

            {!eligibilityLoading &&
                eligibilityReady &&
                !anyEligible &&
                !eligibilityError && (
                    <PayPalAlert
                        message="PayPal no tiene métodos de pago disponibles en este momento. Reintenta o elige otro método de pago."
                        onRetry={retryEligibility}
                    />
                )}

            {showCardSection && (
                <div
                    className={clsx(
                        "space-y-3",
                        checkoutBusy &&
                            paymentPhase !== "open" &&
                            "opacity-60",
                    )}
                >
                    <div className="space-y-1">
                        <Text className="font-medium text-zinc-900 dark:text-slate-100">
                            Tarjeta de crédito o débito
                        </Text>
                        <Text className="text-sm text-zinc-600 dark:text-slate-400">
                            Procesada por PayPal. No necesitas una cuenta
                            PayPal.
                        </Text>
                    </div>
                    <div
                        ref={cardContainerRef}
                        className="paypal-card-container w-full min-h-[48px] max-w-full overflow-x-hidden"
                    />
                </div>
            )}

            {showSeparator && (
                <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-zinc-200 dark:bg-slate-700" />
                    <Text className="text-sm font-medium text-zinc-500 dark:text-slate-400">
                        o
                    </Text>
                    <div className="h-px flex-1 bg-zinc-200 dark:bg-slate-700" />
                </div>
            )}

            {showPaypalSection && (
                <div
                    className={clsx(
                        "space-y-3",
                        checkoutBusy &&
                            paymentPhase !== "open" &&
                            "opacity-60",
                    )}
                >
                    <div className="space-y-1">
                        <Text className="font-medium text-zinc-900 dark:text-slate-100">
                            Cuenta PayPal
                        </Text>
                        <Text className="text-sm text-zinc-600 dark:text-slate-400">
                            Inicia sesión y utiliza tus métodos guardados.
                        </Text>
                    </div>
                    <div
                        ref={paypalContainerRef}
                        className="w-full min-h-[48px] max-w-full overflow-x-hidden"
                    />
                </div>
            )}

            {userMessage && (
                <Text
                    role="alert"
                    className="text-sm text-red-600 dark:text-red-400"
                >
                    {userMessage}
                </Text>
            )}

            {!eligibilityLoading && (
                <Text className="text-xs text-zinc-500 dark:text-slate-500">
                    Puedes volver al paso anterior para cambiar el método de
                    pago.
                </Text>
            )}
        </section>
    );
}

function PayPalAlert({ message, onRetry }) {
    return (
        <div className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-950/30">
            <Text className="text-sm text-amber-900 dark:text-amber-100">
                {message}
            </Text>
            <Button type="button" outline onClick={onRetry}>
                Reintentar
            </Button>
        </div>
    );
}
