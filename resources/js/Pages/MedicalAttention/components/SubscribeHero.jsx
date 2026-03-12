// resources/js/Pages/MedicalAttention/components/SubscribeHero.jsx
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { GiftIcon } from "@heroicons/react/24/solid";
import { useEffect } from "react";

export default function SubscribeHero({
    setIsOpen,
    formattedMedicalAttentionSubscriptionExpiresAt,
    formattedPrice,
}) {
    // Logs de depuración
    useEffect(() => {
        if (true) {
            console.group('🎬 COMPONENTE: SubscribeHero');
            console.log('📥 Props:');
            console.log('  - formattedMedicalAttentionSubscriptionExpiresAt:', formattedMedicalAttentionSubscriptionExpiresAt);
            console.log('  - formattedPrice:', formattedPrice);
            
            if (formattedMedicalAttentionSubscriptionExpiresAt) {
                console.log('ℹ️ Mostrando opción de pago:', formattedPrice);
            } else {
                console.log('ℹ️ Mostrando opción de prueba gratuita');
            }
            console.groupEnd();
        }
    }, [formattedMedicalAttentionSubscriptionExpiresAt, formattedPrice]);

    return (
        <div className="relative isolate overflow-hidden rounded-t-2xl bg-gradient-to-b from-zinc-300/10 dark:from-slate-800/20">
            <div className="relative pb-24 pt-10 lg:grid lg:grid-cols-2 lg:gap-x-8 lg:px-8 lg:py-40">
                <div className="px-6 lg:px-0 lg:pt-4">
                    <div className="mx-auto max-w-2xl">
                        <div className="max-w-2xl">
                            <h1 className="text-pretty text-5xl font-semibold tracking-tight text-famedic-dark dark:text-white">
                                {formattedMedicalAttentionSubscriptionExpiresAt ? (
                                    <>
                                        Brindamos atención médica a tu
                                        familia por menos de{" "}
                                        <span className="text-famedic-light">
                                            $1mxn al día
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        Prueba{" "}
                                        <span className="text-famedic-light">
                                            1 mes de atención
                                        </span>{" "}
                                        médica para ti y tu familia sin
                                        costo!
                                    </>
                                )}
                            </h1>

                            <Text className="mt-6">
                                Protege a tu familia con acceso ilimitado a
                                atención médica profesional 24/7 desde
                                cualquier lugar. Prueba el servicio 1 mes
                                completamente gratis. Al terminar tu período
                                de prueba, podrás elegir adquirir una
                                membresía familiar por menos de $1 peso al
                                día para todas las consultas médicas,
                                asistencia psicológica y asesoría
                                nutricional para tu familia por todo un año.
                            </Text>

                            {!formattedMedicalAttentionSubscriptionExpiresAt ? (
                                <div className="mt-10 flex items-center gap-x-6">
                                    <Button onClick={() => setIsOpen(true)}>
                                        <GiftIcon className="text-green-200" />
                                        Activar mi prueba sin costo
                                    </Button>
                                </div>
                            ) : (
                                <div className="mt-10 flex items-center gap-x-6">
                                    <Button onClick={() => setIsOpen(true)}>
                                        Suscribirse por {formattedPrice}
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
                <div className="mt-20 sm:mt-24 md:mx-auto md:max-w-2xl lg:mx-0 lg:mt-0 lg:w-screen">
                    <video
                        controls
                        poster="https://images.pexels.com/photos/5998445/pexels-photo-5998445.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
                        className="w-full rounded-2xl object-cover"
                    >
                        <source
                            src="/images/murguia.mp4"
                            type="video/mp4"
                        />
                        Your browser does not support the video tag.
                    </video>
                </div>
            </div>
        </div>
    );
}