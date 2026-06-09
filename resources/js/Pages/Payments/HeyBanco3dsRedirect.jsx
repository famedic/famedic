import CheckoutLayout from "@/Layouts/CheckoutLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { ShieldCheckIcon } from "@heroicons/react/24/outline";
import { useEffect } from "react";

export default function HeyBanco3dsRedirect({ sessionId, redirectUrl }) {
    useEffect(() => {
        if (!redirectUrl) {
            return;
        }

        const timer = setTimeout(() => {
            window.location.href = redirectUrl;
        }, 800);

        return () => clearTimeout(timer);
    }, [redirectUrl]);

    return (
        <CheckoutLayout title="Autenticación bancaria">
            <div className="mx-auto max-w-lg py-16 text-center">
                <div className="relative mx-auto h-20 w-20">
                    <div className="h-20 w-20 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
                    <div className="absolute inset-0 flex items-center justify-center">
                        <ShieldCheckIcon className="size-8 text-blue-600" />
                    </div>
                </div>

                <GradientHeading className="mt-8" noDivider>
                    Redirigiendo a autenticación bancaria
                </GradientHeading>

                <Text className="mt-4 text-zinc-600 dark:text-zinc-400">
                    Serás enviado al portal seguro de Hey Banco / Banregio para
                    completar la verificación 3D Secure. No cierres esta ventana.
                </Text>

                <Text className="mt-6 text-xs text-zinc-500">
                    Sesión: {sessionId}
                </Text>
            </div>
        </CheckoutLayout>
    );
}
