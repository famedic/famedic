import CheckoutLayout from "@/Layouts/CheckoutLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    ClockIcon,
} from "@heroicons/react/24/outline";
import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";

function resolveStatusView(session) {
    const proc = (session.bnrg_codigo_proc || "").toUpperCase();

    if (session.status === "approved" || proc === "A") {
        return {
            type: "success",
            title: "Pago aprobado",
            message:
                session.bnrg_text ||
                "Tu pago fue autorizado correctamente.",
        };
    }

    if (proc === "D") {
        return {
            type: "error",
            title: "Pago declinado",
            message:
                session.bnrg_text ||
                "El banco declinó la transacción.",
        };
    }

    if (proc === "R") {
        return {
            type: "error",
            title: "Pago rechazado",
            message:
                session.bnrg_text ||
                "El banco rechazó la autenticación.",
        };
    }

    if (proc === "T" || session.status === "timeout") {
        return {
            type: "warning",
            title: "Tiempo agotado",
            message:
                session.bnrg_text ||
                "La autenticación 3D Secure expiró.",
        };
    }

    return {
        type: "error",
        title: "No se pudo completar el pago",
        message:
            session.bnrg_text ||
            "Ocurrió un error durante la autenticación.",
    };
}

export default function HeyBanco3dsResult({ session, purchaseUrl }) {
    const [currentSession, setCurrentSession] = useState(session);
    const view = resolveStatusView(currentSession);

    useEffect(() => {
        if (currentSession.status !== "redirect_required" && currentSession.status !== "pending") {
            return;
        }

        const interval = setInterval(() => {
            fetch(route("payments.hey-banco.3ds.status", { session: session.id }))
                .then((res) => res.json())
                .then((data) => {
                    if (data.final) {
                        clearInterval(interval);
                        setCurrentSession((prev) => ({
                            ...prev,
                            status: data.status,
                            bnrg_codigo_proc: data.bnrg_codigo_proc,
                            bnrg_text: data.message,
                        }));

                        if (data.approved && purchaseUrl) {
                            setTimeout(() => router.visit(purchaseUrl), 1500);
                        }
                    }
                })
                .catch(() => clearInterval(interval));
        }, 4000);

        return () => clearInterval(interval);
    }, [currentSession.status, purchaseUrl, session.id]);

    const Icon =
        view.type === "success"
            ? CheckCircleIcon
            : view.type === "warning"
              ? ClockIcon
              : ExclamationTriangleIcon;

    const iconClass =
        view.type === "success"
            ? "text-green-600"
            : view.type === "warning"
              ? "text-amber-600"
              : "text-red-600";

    return (
        <CheckoutLayout title="Resultado del pago">
            <div className="mx-auto max-w-lg py-16 text-center">
                <Icon className={`mx-auto size-16 ${iconClass}`} />

                <GradientHeading className="mt-8" noDivider>
                    {view.title}
                </GradientHeading>

                <Text className="mt-4 text-zinc-600 dark:text-zinc-400">
                    {view.message}
                </Text>

                {purchaseUrl && view.type === "success" && (
                    <Button className="mt-8" href={purchaseUrl}>
                        Ver mi pedido
                    </Button>
                )}

                {view.type !== "success" && (
                    <Button
                        className="mt-8"
                        outline
                        onClick={() => window.history.back()}
                    >
                        Volver al checkout
                    </Button>
                )}
            </div>
        </CheckoutLayout>
    );
}
