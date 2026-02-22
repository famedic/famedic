import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
    CheckCircleIcon,
    XCircleIcon,
    ArrowLeftIcon,
    CreditCardIcon,
    ShieldCheckIcon
} from "@heroicons/react/24/outline";
import { useEffect, useState } from "react";
import { router } from "@inertiajs/react";

export default function ThreeDSResult({
    sessionId,
    success,
    message,
    status,
    cardLastFour,
    amount,
    createdAt
}) {

    const [countdown, setCountdown] = useState(5);

    /* ==========================================================
     * AUTO REDIRECT
     * ========================================================== */

    useEffect(() => {
        if (!success) return;

        const interval = setInterval(() => {
            setCountdown((prev) => prev - 1);
        }, 1000);

        const redirectTimer = setTimeout(() => {
            router.visit(route("payment-methods.index"));
        }, 5000);

        return () => {
            clearInterval(interval);
            clearTimeout(redirectTimer);
        };
    }, [success]);

    const goNow = () => {
        router.visit(route("payment-methods.index"));
    };

    return (
        <SettingsLayout title="Resultado de verificación">

            <div className="flex items-center gap-4">
                <Button
                    href={route("payment-methods.index")}
                    outline
                    className="size-10 p-0"
                >
                    <ArrowLeftIcon />
                </Button>
                <GradientHeading noDivider>
                    {success ? "Tarjeta verificada" : "Verificación no completada"}
                </GradientHeading>
            </div>

            <div className="mt-12 max-w-2xl">

                {/* RESULT CARD */}
                <div className={`rounded-2xl p-10 text-center shadow-sm border ${
                    success
                        ? "border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20"
                        : "border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20"
                }`}>

                    <div className="flex flex-col items-center">

                        <div className={`relative ${
                            success ? "text-green-600" : "text-red-600"
                        }`}>
                            {success ? (
                                <CheckCircleIcon className="size-20 animate-bounce" />
                            ) : (
                                <XCircleIcon className="size-20" />
                            )}
                        </div>

                        <h2 className={`mt-6 text-2xl font-bold ${
                            success
                                ? "text-green-800 dark:text-green-300"
                                : "text-red-800 dark:text-red-300"
                        }`}>
                            {message}
                        </h2>

                        <p className="mt-4 text-zinc-600 dark:text-zinc-400 max-w-md">
                            {success
                                ? "Tu tarjeta fue verificada correctamente y ahora está lista para usarse."
                                : "No pudimos completar la verificación. Puedes intentarlo nuevamente."}
                        </p>

                        {/* CARD INFO */}
                        {cardLastFour && (
                            <div className="mt-8 w-full rounded-xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 p-6 text-left">

                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-3">
                                        <CreditCardIcon className="size-6 text-zinc-400" />
                                        <div>
                                            <Text className="font-medium">
                                                **** **** **** {cardLastFour}
                                            </Text>
                                            <Text className="text-xs text-zinc-500">
                                                {new Date(createdAt).toLocaleDateString("es-MX")}
                                            </Text>
                                        </div>
                                    </div>

                                    <span className={`px-3 py-1 text-xs rounded-full font-medium ${
                                        success
                                            ? "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300"
                                            : "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300"
                                    }`}>
                                        {success ? "ACTIVA" : "NO VERIFICADA"}
                                    </span>
                                </div>

                                {amount && (
                                    <div className="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                        <Text className="text-sm">
                                            Cargo de verificación: <strong>${amount} MXN</strong>
                                        </Text>
                                        <Text className="text-xs text-zinc-500">
                                            Se reembolsará en 24–48 horas
                                        </Text>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* REDIRECT PROGRESS */}
                        {success && (
                            <div className="mt-8 w-full">

                                <div className="h-2 w-full rounded-full bg-green-100 dark:bg-green-900/40 overflow-hidden">
                                    <div
                                        className="h-full bg-green-500 transition-all duration-1000"
                                        style={{ width: `${(5 - countdown) * 20}%` }}
                                    />
                                </div>

                                <Text className="mt-3 text-sm text-green-700 dark:text-green-300">
                                    Redirigiendo en {countdown} segundos...
                                </Text>

                                <Button
                                    onClick={goNow}
                                    className="mt-4"
                                >
                                    Ir ahora
                                </Button>

                            </div>
                        )}

                        {!success && (
                            <div className="mt-8 flex gap-4">
                                <Button
                                    href={route("payment-methods.create")}
                                >
                                    Intentar nuevamente
                                </Button>

                                <Button
                                    href={`mailto:soporte@famedic.com?subject=Problema%203DS&body=ID%20de%20sesión:%20${sessionId}`}
                                    outline
                                >
                                    Contactar soporte
                                </Button>
                            </div>
                        )}

                    </div>
                </div>

                {/* INFO 3DS */}
                <div className="mt-10 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6">

                    <div className="flex items-start gap-3">
                        <ShieldCheckIcon className="size-5 text-zinc-500 mt-1" />
                        <div>
                            <Text className="font-medium">
                                Seguridad 3D Secure
                            </Text>
                            <Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                Es una verificación adicional requerida por tu banco para confirmar
                                que eres el titular legítimo de la tarjeta.
                            </Text>
                        </div>
                    </div>

                    <div className="mt-6 border-t border-zinc-200 dark:border-zinc-700 pt-4 text-xs text-zinc-500">
                        ID sesión: {sessionId} · Estado: {status}
                    </div>

                </div>

            </div>
        </SettingsLayout>
    );
}