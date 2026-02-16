import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { 
    CheckCircleIcon, 
    XCircleIcon, 
    ArrowLeftIcon, 
    CreditCardIcon,
    ExclamationTriangleIcon 
} from "@heroicons/react/24/outline";
import { useEffect } from "react";
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
    useEffect(() => {
        // Si fue exitoso, marcar como vista después de 5 segundos
        if (success) {
            const timer = setTimeout(() => {
                router.visit(route('payment-methods.index'), {
                    preserveScroll: true,
                    preserveState: false,
                });
            }, 5000);

            return () => clearTimeout(timer);
        }
    }, [success]);

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
                    {success ? '¡Verificación exitosa!' : 'Verificación incompleta'}
                </GradientHeading>
            </div>

            <div className="mt-8 max-w-2xl">
                {/* Tarjeta de resultado */}
                <div className={`rounded-xl border p-8 ${
                    success 
                        ? 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20' 
                        : 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20'
                }`}>
                    <div className="flex flex-col items-center text-center">
                        <div className={`inline-flex h-20 w-20 items-center justify-center rounded-full ${
                            success 
                                ? 'bg-green-100 dark:bg-green-900/30' 
                                : 'bg-red-100 dark:bg-red-900/30'
                        }`}>
                            {success ? (
                                <CheckCircleIcon className="size-12 text-green-600 dark:text-green-400" />
                            ) : (
                                <XCircleIcon className="size-12 text-red-600 dark:text-red-400" />
                            )}
                        </div>
                        
                        <Text className={`mt-6 text-2xl font-bold ${
                            success 
                                ? 'text-green-800 dark:text-green-300' 
                                : 'text-red-800 dark:text-red-300'
                        }`}>
                            {message}
                        </Text>
                        
                        <Text className="mt-4 text-zinc-600 dark:text-zinc-400">
                            {success 
                                ? 'Tu tarjeta ha sido verificada y agregada exitosamente a tus métodos de pago.'
                                : 'No pudimos completar la verificación de tu tarjeta. Puedes intentar nuevamente.'
                            }
                        </Text>
                        
                        {/* Detalles de la tarjeta */}
                        {cardLastFour && (
                            <div className="mt-8 w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-4">
                                        <CreditCardIcon className="size-8 stroke-zinc-400" />
                                        <div>
                                            <Text className="font-medium text-zinc-900 dark:text-white">
                                                **** **** **** {cardLastFour}
                                            </Text>
                                            <Text className="text-sm text-zinc-500 dark:text-zinc-400">
                                                Verificación realizada el {new Date(createdAt).toLocaleDateString('es-MX')}
                                            </Text>
                                        </div>
                                    </div>
                                    <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ${
                                        success 
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' 
                                            : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
                                    }`}>
                                        {success ? 'VERIFICADA' : 'PENDIENTE'}
                                    </span>
                                </div>
                                
                                {amount && (
                                    <div className="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                        <Text className="text-sm text-zinc-600 dark:text-zinc-400">
                                            Cargo de verificación: <strong>${amount} MXN</strong>
                                        </Text>
                                        <Text className="text-xs text-zinc-500 dark:text-zinc-400">
                                            Este cargo será reembolsado en 24-48 horas hábiles
                                        </Text>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {/* Acciones */}
                <div className="mt-8 flex flex-wrap gap-4">
                    <Button
                        href={route('payment-methods.index')}
                        className="min-w-32"
                    >
                        <ArrowLeftIcon className="mr-2 size-4" />
                        Volver a mis tarjetas
                    </Button>
                    
                    {!success && (
                        <>
                            <Button
                                href={route('payment-methods.create')}
                                outline
                            >
                                Intentar con otra tarjeta
                            </Button>
                            
                            <Button
                                href={`mailto:soporte@famedic.com?subject=Problema%20con%20verificación%203DS&body=ID%20de%20sesión:%20${sessionId}`}
                                outline
                                className="border-blue-300 text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-400 dark:hover:bg-blue-900/20"
                            >
                                Contactar soporte
                            </Button>
                        </>
                    )}
                </div>

                {/* Información adicional */}
                <div className="mt-8 rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <div className="flex items-start">
                        <ExclamationTriangleIcon className="mr-3 mt-0.5 size-5 flex-shrink-0 text-zinc-500" />
                        <div>
                            <Text className="font-medium text-zinc-900 dark:text-white">
                                ¿Qué es 3DS?
                            </Text>
                            <Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                3D Secure (3DS) es un protocolo de seguridad que verifica que tú 
                                seas el verdadero titular de la tarjeta. Esta verificación es 
                                requerida por tu banco para protegerte contra fraudes.
                            </Text>
                            <ul className="mt-3 space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>Solo necesitas verificarla una vez por tarjeta</span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>Los pagos futuros no requerirán esta verificación</span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>Es un estándar internacional de seguridad bancaria</span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>Famedic nunca tiene acceso a tu código de verificación</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    {/* ID de sesión para referencia */}
                    <div className="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <Text className="text-xs text-zinc-500 dark:text-zinc-400">
                            ID de sesión: {sessionId} | Estado: {status}
                        </Text>
                    </div>
                </div>
                
                {/* Redirección automática */}
                {success && (
                    <div className="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-center dark:border-blue-800 dark:bg-blue-900/20">
                        <Text className="text-sm text-blue-700 dark:text-blue-300">
                            Serás redirigido automáticamente en 5 segundos...
                        </Text>
                    </div>
                )}
            </div>
        </SettingsLayout>
    );
}