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

/**
 * Botón PayPal para checkout de laboratorio (crear orden + capturar en servidor).
 */
export default function LaboratoryPayPalButton({
    paypalClientId,
    laboratoryBrand,
    patientId,
    addressId,
    totalCents,
    disabled,
}) {
    const containerRef = useRef(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

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
                                const { data } = await axios.post(
                                    route("paypal.create-order"),
                                    {
                                        patient_id: patientId || null,
                                        address_id: addressId,
                                        laboratory_brand: laboratoryBrand,
                                        total: totalCents,
                                    },
                                    {
                                        headers: {
                                            "X-CSRF-TOKEN": csrf,
                                            "X-Requested-With": "XMLHttpRequest",
                                            Accept: "application/json",
                                        },
                                    },
                                );
                                return data.order_id;
                            } catch (err) {
                                const msg =
                                    err.response?.data?.message ||
                                    err.message ||
                                    "No se pudo iniciar el pago con PayPal.";
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
                                route("paypal.capture-order"),
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
                                cap.laboratory_purchase_id &&
                                (cap.status === "captured" ||
                                    cap.status === "already_processed")
                            ) {
                                router.visit(
                                    route("laboratory-purchases.show", {
                                        laboratory_purchase:
                                            cap.laboratory_purchase_id,
                                    }),
                                );
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
        laboratoryBrand,
        patientId,
        addressId,
        totalCents,
        disabled,
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
