import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import {
    ArrowLeftIcon,
    ShieldCheckIcon,
    LockClosedIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon
} from "@heroicons/react/24/outline";
import { useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";

export default function ThreeDSRedirect({ sessionId, url3ds, token3ds }) {

    const iframeRef = useRef(null);

    const isFrictionless = !url3ds || !token3ds;

    const [status, setStatus] = useState(
        isFrictionless ? "frictionless" : "processing"
    );

    const [message, setMessage] = useState(
        isFrictionless
            ? "Verificación automática completada. Confirmando con el banco..."
            : "Esperando autorización..."
    );

    /* ==========================================================
     * Enviar challenge SOLO si existe URL 3DS
     * ========================================================== */
    useEffect(() => {
        if (isFrictionless) return;
        if (!iframeRef.current) return;

        const form = document.createElement("form");
        form.method = "POST";
        form.action = url3ds;
        form.target = "threeDSFrame";

        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "creq";
        input.value = token3ds;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);

    }, [url3ds, token3ds, isFrictionless]);

    /* ==========================================================
     * Polling estado 3DS
     * ========================================================== */
    useEffect(() => {

        const interval = setInterval(() => {

            fetch(route("payment-methods.3ds-status", { sessionId }))
                .then(res => res.json())
                .then(data => {

                    if (data.final) {

                        clearInterval(interval);

                        if (data.status === "completed") {
                            setStatus("success");
                            setMessage(data.message);

                            setTimeout(() => {
                                router.visit(route("payment-methods.3ds-result", { sessionId }));
                            }, 1500);

                        } else {

                            setStatus("error");
                            setMessage(data.message);
                        }
                    }

                })
                .catch(() => {
                    clearInterval(interval);
                    setStatus("error");
                    setMessage("Error verificando estado");
                });

        }, 3000);

        return () => clearInterval(interval);

    }, [sessionId]);

    return (
        <SettingsLayout title="Verificación de seguridad">

            <div className="flex items-center gap-4">
                <Button
                    href={route("payment-methods.index")}
                    outline
                    className="size-10 p-0"
                >
                    <ArrowLeftIcon />
                </Button>
                <GradientHeading noDivider>
                    Verificación segura 3D Secure
                </GradientHeading>
            </div>

            <div className="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-8">

                {/* PANEL IZQUIERDO */}
                <div className="flex flex-col items-center text-center">

                    {(status === "processing" || status === "frictionless") && (
                        <>
                            <div className="relative">
                                <div className="h-20 w-20 animate-spin rounded-full border-4 border-blue-600 border-t-transparent"></div>
                                <div className="absolute inset-0 flex items-center justify-center">
                                    <ShieldCheckIcon className="size-8 text-blue-600" />
                                </div>
                            </div>

                            <h2 className="mt-6 text-lg font-semibold text-white">
                                {isFrictionless
                                    ? "Verificación automática"
                                    : "Conectando con tu banco..."}
                            </h2>

                            <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400 max-w-md">
                                {message}
                            </p>
                        </>
                    )}

                    {status === "success" && (
                        <div className="flex flex-col items-center">
                            <CheckCircleIcon className="size-16 text-green-600" />
                            <h2 className="mt-4 font-semibold text-green-700">
                                Autorización exitosa
                            </h2>
                        </div>
                    )}

                    {status === "error" && (
                        <div className="flex flex-col items-center text-center">
                            <ExclamationTriangleIcon className="size-16 text-red-600" />
                            <h2 className="mt-4 font-semibold text-red-700">
                                No se pudo completar el proceso
                            </h2>
                            <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400 max-w-md">
                                {message}
                            </p>

                            <Button
                                className="mt-6"
                                href={route("payment-methods.create")}
                            >
                                Intentar nuevamente
                            </Button>
                        </div>
                    )}

                    <div className="mt-6 rounded-lg bg-blue-50 px-6 py-4 text-sm text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                        <div className="flex items-center gap-2 justify-center">
                            <LockClosedIcon className="size-4" />
                            <span>No cierres esta ventana</span>
                        </div>
                    </div>

                    <div className="mt-8 text-xs text-zinc-500">
                        ID sesión: {sessionId}
                    </div>

                </div>

                {/* IFRAME SOLO SI HAY CHALLENGE */}
                {!isFrictionless && (
                    <div className="border rounded-lg shadow-sm overflow-hidden bg-white">
                        <iframe
                            name="threeDSFrame"
                            ref={iframeRef}
                            className="w-full h-[600px]"
                            title="3D Secure Challenge"
                        />
                    </div>
                )}

            </div>

        </SettingsLayout>
    );
}