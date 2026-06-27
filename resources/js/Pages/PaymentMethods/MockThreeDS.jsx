import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import EnvironmentBadge from "@/Components/EnvironmentBadge";
import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";

export default function MockThreeDS({
    sessionId,
    cardLastFour,
    amount,
}) {
    const [processing, setProcessing] = useState(false);
    const [message, setMessage] = useState(null);

    const pollStatus = () => {
        setProcessing(true);
        fetch(route("payment-methods.3ds-status", { sessionId }))
            .then((res) => res.json())
            .then((data) => {
                if (data.final) {
                    router.visit(route("payment-methods.3ds-result", { sessionId }));
                    return;
                }
                setMessage(data.message ?? "Procesando...");
                setTimeout(pollStatus, 800);
            })
            .catch(() => {
                setMessage("Error en simulación 3DS");
                setProcessing(false);
            });
    };

    useEffect(() => {
        const timer = setTimeout(pollStatus, 600);
        return () => clearTimeout(timer);
    }, []);

    return (
        <SettingsLayout title="Verificación simulada">
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <GradientHeading noDivider>3D Secure (simulación)</GradientHeading>
                <EnvironmentBadge />
            </div>

            <div className="rounded-xl border border-amber-200/80 bg-amber-50/80 p-6 dark:border-amber-800/40 dark:bg-amber-950/30">
                <Text className="text-sm text-amber-900 dark:text-amber-100">
                    Ambiente de pruebas: no se contacta a EfevooPay ni se realiza ningún cargo real.
                </Text>
                <Text className="mt-2 text-sm text-amber-800/90 dark:text-amber-200/90">
                    Tarjeta terminada en {cardLastFour} · monto de verificación ${Number(amount).toFixed(2)} MXN (simulado)
                </Text>
                {message && (
                    <Text className="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
                        {message}
                    </Text>
                )}
                <div className="mt-6 flex gap-3">
                    <Button onClick={pollStatus} disabled={processing}>
                        {processing ? "Procesando..." : "Confirmar verificación"}
                    </Button>
                    <Button
                        href={route("payment-methods.create")}
                        plain
                    >
                        Cancelar
                    </Button>
                </div>
            </div>
        </SettingsLayout>
    );
}
