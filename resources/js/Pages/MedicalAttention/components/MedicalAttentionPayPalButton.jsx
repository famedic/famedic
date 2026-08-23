import { useEffect, useRef, useState } from "react";
import axios from "axios";
import { router } from "@inertiajs/react";
import { Text } from "@/Components/Catalyst/text";

function loadPayPalScript(clientId) {
    return new Promise((resolve, reject) => {
        if (window.paypal) {
            resolve(window.paypal);
            return;
        }
        const existing = document.querySelector(
            'script[src*="paypal.com/sdk/js"]',
        );
        if (existing) {
            existing.addEventListener("load", () => resolve(window.paypal));
            existing.addEventListener("error", reject);
            return;
        }
        const script = document.createElement("script");
        script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=MXN&intent=capture`;
        script.async = true;
        script.onload = () => resolve(window.paypal);
        script.onerror = () =>
            reject(new Error("No se pudo cargar el SDK de PayPal"));
        document.body.appendChild(script);
    });
}

export default function MedicalAttentionPayPalButton({
    paypalClientId,
    disabled = false,
    recoveryContextUuid = null,
    recoveryPayPalCancelUrl = null,
}) {
    const containerRef = useRef(null);
    const transactionIdRef = useRef(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    const notifyPayPalCancel = async () => {
        if (!recoveryPayPalCancelUrl || !recoveryContextUuid) {
            return;
        }

        const csrf =
            document.querySelector('meta[name="csrf-token"]')?.content || "";

        try {
            await axios.post(
                recoveryPayPalCancelUrl,
                {
                    recovery_context_uuid: recoveryContextUuid,
                    transaction_id: transactionIdRef.current,
                },
                {
                    headers: {
                        "X-CSRF-TOKEN": csrf,
                        "X-Requested-With": "XMLHttpRequest",
                        Accept: "application/json",
                    },
                },
            );
        } catch {
            // Best-effort cancel notification.
        }
    };

    useEffect(() => {
        if (!paypalClientId || disabled) {
            setLoading(false);
            return;
        }

        let cancelled = false;

        (async () => {
            try {
                const paypal = await loadPayPalScript(paypalClientId);
                if (cancelled || !containerRef.current) return;

                await paypal
                    .Buttons({
                        style: { layout: "vertical", label: "pay" },
                        createOrder: async () => {
                            setError(null);
                            const csrf =
                                document.querySelector(
                                    'meta[name="csrf-token"]',
                                )?.content || "";
                            try {
                                const payload = {};

                                if (recoveryContextUuid) {
                                    payload.recovery_context_uuid =
                                        recoveryContextUuid;
                                }

                                const { data } = await axios.post(
                                    route(
                                        "medical-attention.paypal.create-order",
                                    ),
                                    payload,
                                    {
                                        headers: {
                                            "X-CSRF-TOKEN": csrf,
                                            "X-Requested-With": "XMLHttpRequest",
                                            Accept: "application/json",
                                        },
                                    },
                                );
                                transactionIdRef.current =
                                    data.transaction_id ?? null;

                                return data.order_id;
                            } catch (err) {
                                const data = err.response?.data ?? {};
                                let msg =
                                    data.message ||
                                    err.message ||
                                    "No se pudo iniciar el pago con PayPal.";
                                if (data.support_reference) {
                                    msg += ` Referencia: ${data.support_reference}`;
                                }
                                setError(msg);
                                throw new Error(msg);
                            }
                        },
                        onApprove: async (data) => {
                            const csrf =
                                document.querySelector(
                                    'meta[name="csrf-token"]',
                                )?.content || "";
                            const res = await axios.post(
                                route(
                                    "medical-attention.paypal.capture-order",
                                ),
                                { order_id: data.orderID },
                                {
                                    headers: {
                                        "X-CSRF-TOKEN": csrf,
                                        "X-Requested-With": "XMLHttpRequest",
                                        Accept: "application/json",
                                    },
                                },
                            );
                            const cap = res.data;
                            if (
                                cap.redirect_url &&
                                (cap.status === "captured" ||
                                    cap.status === "already_processed")
                            ) {
                                router.visit(cap.redirect_url);
                                return;
                            }
                            setError(
                                "No se pudo confirmar el pago. Contacta soporte si se te cobró.",
                            );
                        },
                        onError: (err) => {
                            console.error(err);
                            setError(
                                "Error en el pago con PayPal. Intenta de nuevo.",
                            );
                        },
                        onCancel: () => {
                            setError(null);
                            notifyPayPalCancel();
                        },
                    })
                    .render(containerRef.current);
            } catch (e) {
                console.error(e);
                setError(
                    "No se pudo iniciar PayPal. Verifica tu conexión o intenta más tarde.",
                );
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();

        return () => {
            cancelled = true;
            if (containerRef.current) {
                containerRef.current.innerHTML = "";
            }
        };
    }, [
        paypalClientId,
        disabled,
        recoveryContextUuid,
        recoveryPayPalCancelUrl,
    ]);

    if (!paypalClientId) {
        return (
            <Text className="text-sm text-amber-600">
                PayPal no está configurado en este entorno.
            </Text>
        );
    }

    return (
        <div className="w-full space-y-2">
            {loading && (
                <Text className="text-sm text-zinc-500">
                    Cargando PayPal…
                </Text>
            )}
            <div ref={containerRef} className="w-full min-h-[48px]" />
            {error && (
                <Text className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </Text>
            )}
        </div>
    );
}
