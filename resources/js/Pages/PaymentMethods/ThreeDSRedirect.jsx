import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { ArrowLeftIcon, CheckCircleIcon, ExclamationTriangleIcon } from "@heroicons/react/24/outline";
import { useEffect, useState } from "react";
import { router } from "@inertiajs/react";

export default function ThreeDSRedirect({ sessionId, orderId, url3ds, token3ds, iframeHtml }) {
    const [status, setStatus] = useState('loading');
    const [message, setMessage] = useState('Iniciando verificación de seguridad...');

    useEffect(() => {
        // Verificar estado después de 5 segundos
        const checkStatus = () => {
            fetch(route('payment-methods.3ds-status', { sessionId }))
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        setStatus('success');
                        setMessage('¡Verificación completada!');

                        setTimeout(() => {
                            router.visit(route('payment-methods.3ds-result', { sessionId }));
                        }, 2000);
                    } else {
                        setTimeout(checkStatus, 5000);
                    }
                })
                .catch(() => {
                    setStatus('error');
                    setMessage('Error verificando estado.');
                });
        };

        // Iniciar primera verificación después de 5 segundos
        const timer = setTimeout(checkStatus, 5000);

        return () => clearTimeout(timer);
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
                <GradientHeading noDivider>Verificación de seguridad (3DS)</GradientHeading>
            </div>

            <div className="mt-8 max-w-4xl">
                {/* Información */}
                <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                    <div className="flex items-start">
                        <ExclamationTriangleIcon className="mr-3 mt-0.5 size-5 flex-shrink-0 text-blue-600 dark:text-blue-400" />
                        <div className="flex-1">
                            <h3 className="text-sm font-medium text-blue-800 dark:text-blue-300">
                                Verificación de seguridad requerida
                            </h3>
                            <div className="mt-2 text-sm text-blue-700 dark:text-blue-400">
                                <p className="mb-2">
                                    Para proteger tu tarjeta, tu banco requiere una verificación adicional.
                                </p>
                                <ul className="ml-4 list-disc space-y-1">
                                    <li>Serás redirigido a la página de seguridad de tu banco</li>
                                    <li>Ingresa el código que recibas por SMS o en tu app bancaria</li>
                                    <li>El proceso toma solo unos segundos</li>
                                    <li>Esta verificación es obligatoria por regulaciones de seguridad</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Iframe de 3DS */}
                <div className="mb-6">
                    <div className="mb-3 flex items-center justify-between">
                        <Text className="font-medium">Portal de seguridad de tu banco</Text>
                        <div className="flex items-center gap-2">
                            <div className="h-2 w-2 animate-ping rounded-full bg-green-500"></div>
                            <Text className="text-xs text-green-600 dark:text-green-400">Conectando...</Text>
                        </div>
                    </div>

                    <div className="relative rounded-lg border border-zinc-300 bg-white shadow-sm dark:border-zinc-600 dark:bg-zinc-800">
                        <div className="absolute inset-0 flex items-center justify-center" style={{ minHeight: '400px' }}>
                            <div className="text-center">
                                <div className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                                    <CheckCircleIcon className="size-8 text-blue-600 dark:text-blue-400" />
                                </div>
                                <Text className="mt-4 font-medium text-zinc-700 dark:text-zinc-300">
                                    Redirigiendo a la página de seguridad...
                                </Text>
                                <Text className="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                                    Por favor espera mientras te conectamos con tu banco
                                </Text>
                            </div>
                        </div>
                        <div
                            className="iframe-container"
                            dangerouslySetInnerHTML={{ __html: iframeHtml }}
                        />
                    </div>
                </div>

                {/* Estado y acciones */}
                <div className="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <div className="flex items-start justify-between">
                        <div>
                            <Text className="font-medium text-zinc-900 dark:text-white">
                                Estado de la verificación
                            </Text>
                            <Text className={`mt-1 text-sm ${status === 'success' ? 'text-green-600 dark:text-green-400' :
                                status === 'error' ? 'text-red-600 dark:text-red-400' :
                                    'text-zinc-600 dark:text-zinc-400'}`}>
                                {message}
                            </Text>
                        </div>

                        <div className="flex gap-3">
                            <Button
                                href={route('payment-methods.3ds-result', { sessionId })}
                                outline
                            >
                                Ver resultado
                            </Button>
                            <Button
                                href={route('payment-methods.index')}
                                outline
                                className="border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20"
                            >
                                Cancelar
                            </Button>
                        </div>
                    </div>

                    {/* ID de sesión para debugging */}
                    <div className="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <Text className="text-xs text-zinc-500 dark:text-zinc-400">
                            ID de sesión: {sessionId} | Order ID: {orderId}
                        </Text>
                    </div>
                </div>

                {/* Instrucciones */}
                <div className="mt-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <Text className="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        ¿Necesitas ayuda?
                    </Text>
                    <ul className="space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                        <li className="flex items-start gap-2">
                            <span className="mt-1">•</span>
                            <span>Si la página de tu banco no carga, verifica tu conexión a internet</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="mt-1">•</span>
                            <span>El código de verificación es enviado por tu banco, no por Famedic</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="mt-1">•</span>
                            <span>Esta verificación es un estándar de seguridad internacional (3DS)</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="mt-1">•</span>
                            <span>La verificación solo es necesaria la primera vez que agregas esta tarjeta</span>
                        </li>
                    </ul>
                </div>
            </div>

            <style>{`
    .iframe-container iframe {
        width: 100%;
        min-height: 400px;
        border: none;
        border-radius: 0.5rem;
    }
`}</style>
        </SettingsLayout>
    );
}
