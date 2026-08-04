// resources/js/Pages/MedicalAttention/components/SubscribeHero.jsx
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { GiftIcon, CalendarDaysIcon } from "@heroicons/react/24/solid";
import { useForm, usePage } from "@inertiajs/react";

export default function SubscribeHero({
    formattedMedicalAttentionSubscriptionExpiresAt,
    formattedPrice,
}) {
    const trialEnabled = usePage().props.medicalAttentionTrialEnabled ?? false;
    const showTrialOffer =
        trialEnabled && !formattedMedicalAttentionSubscriptionExpiresAt;

    const { post, processing } = useForm({});

    const submitTrial = (e) => {
        e.preventDefault();
        if (!processing) {
            post(route("free-medical-attention.subscription"));
        }
    };

    return (
        <div className="relative isolate overflow-hidden rounded-t-2xl bg-gradient-to-b from-zinc-300/10 dark:from-slate-800/20">
            <div className="relative pb-24 pt-10 lg:grid lg:grid-cols-2 lg:gap-x-8 lg:px-8 lg:py-40">
                <div className="px-6 lg:px-0 lg:pt-4">
                    <div className="mx-auto max-w-2xl">
                        <div className="max-w-2xl">
                            {!showTrialOffer && (
                                <Badge
                                    color="blue"
                                    className="mb-4 px-3 py-1.5 text-sm"
                                >
                                    <CalendarDaysIcon className="size-4" />
                                    Membresía anual · 12 meses de vigencia
                                </Badge>
                            )}

                            <h1 className="text-pretty text-5xl font-semibold tracking-tight text-famedic-dark dark:text-white">
                                {showTrialOffer ? (
                                    <>
                                        Prueba{" "}
                                        <span className="text-famedic-light">
                                            1 mes de atención
                                        </span>{" "}
                                        médica para ti y tu familia sin
                                        costo!
                                    </>
                                ) : (
                                    <>
                                        Membresía{" "}
                                        <span className="text-famedic-light">
                                            anual
                                        </span>{" "}
                                        de atención médica para tu familia
                                    </>
                                )}
                            </h1>

                            <Text className="mt-6">
                                {showTrialOffer ? (
                                    <>
                                        Protege a tu familia con acceso
                                        ilimitado a atención médica
                                        profesional 24/7 desde cualquier
                                        lugar. Prueba el servicio 1 mes
                                        completamente gratis. Al terminar tu
                                        período de prueba, podrás elegir
                                        adquirir una membresía familiar anual
                                        por menos de $1 peso al día para todas
                                        las consultas médicas, asistencia
                                        psicológica y asesoría nutricional
                                        para tu familia.
                                    </>
                                ) : (
                                    <>
                                        Protege a tu familia con acceso
                                        ilimitado a atención médica
                                        profesional 24/7 durante{" "}
                                        <strong>todo un año</strong>. Una sola
                                        suscripción anual de{" "}
                                        <strong>{formattedPrice}</strong>{" "}
                                        (menos de $1 peso al día) cubre
                                        consultas médicas, asistencia
                                        psicológica y asesoría nutricional
                                        para tu familia.
                                    </>
                                )}
                            </Text>

                            {showTrialOffer ? (
                                <form
                                    onSubmit={submitTrial}
                                    className="mt-10 flex items-center gap-x-6"
                                >
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                    >
                                        <GiftIcon className="text-green-200" />
                                        Activar mi prueba sin costo
                                    </Button>
                                </form>
                            ) : (
                                <div className="mt-10 flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:gap-x-6">
                                    <Button href={route("medical-attention.checkout")}>
                                        Suscribirse — {formattedPrice} / año
                                    </Button>
                                    <Text className="text-sm text-zinc-500 dark:text-zinc-400">
                                        Pago único · vigencia 12 meses
                                    </Text>
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
