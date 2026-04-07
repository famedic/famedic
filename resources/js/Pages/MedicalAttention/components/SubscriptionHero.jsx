// resources/js/Pages/MedicalAttention/components/SubscriptionHero.jsx
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Anchor, Code, Text } from "@/Components/Catalyst/text";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/Catalyst/table";
import {
    CalendarDaysIcon,
    PhoneIcon,
    UserGroupIcon,
    QrCodeIcon,
    StarIcon as StarIconSolid,
} from "@heroicons/react/24/solid";
import { StarIcon as StarIconOutline } from "@heroicons/react/24/outline";
import { useEffect, useState } from "react";

export default function SubscriptionHero({
    formattedMedicalAttentionSubscriptionExpiresAt,
    medicalAttentionIdentifier,
    familyAccounts,
    planType,
    hasOdessaAfiliateAccount,
    phoneNumber,
    formattedPhone,
}) {
    const [isLoadingIframe, setIsLoadingIframe] = useState(false);
    const [iframeUrl, setIframeUrl] = useState(null);
    const [iframeError, setIframeError] = useState(null);

    const requestIframe = async () => {
        if (isLoadingIframe) return;

        setIsLoadingIframe(true);
        setIframeError(null);

        try {
            const response = await window.axios.get(route("murguia.iframe"));

            if (!response?.data?.url) {
                throw new Error("La respuesta no incluye URL.");
            }

            setIframeUrl(response.data.url);
        } catch (error) {
            setIframeError(
                error?.response?.data?.message ||
                    "No pudimos abrir el portal de asistencias. Intenta de nuevo."
            );
        } finally {
            setIsLoadingIframe(false);
        }
    };

    // Logs de depuración
    useEffect(() => {
        if (true) {
            console.group('🌟 COMPONENTE: SubscriptionHero');
            console.log('📥 Props:');
            console.log('  - formattedMedicalAttentionSubscriptionExpiresAt:', formattedMedicalAttentionSubscriptionExpiresAt);
            console.log('  - medicalAttentionIdentifier:', medicalAttentionIdentifier);
            console.log('  - familyAccounts:', familyAccounts);
            console.log('  - planType:', planType);
            console.log('  - hasOdessaAfiliateAccount:', hasOdessaAfiliateAccount);
            console.log('  - 📞 Teléfono:', formattedPhone);
            console.log('  - Número de familiares:', familyAccounts?.length || 0);
            
            if (familyAccounts?.length > 0) {
                console.log('👨‍👩‍👧‍👦 Lista de familiares:');
                familyAccounts.forEach((member, index) => {
                    console.log(`    ${index + 1}. ${member.full_name} - ID: ${member.customer?.medical_attention_identifier}`);
                });
            }
            console.groupEnd();
        }
    }, [formattedMedicalAttentionSubscriptionExpiresAt, medicalAttentionIdentifier, familyAccounts, planType, hasOdessaAfiliateAccount, formattedPhone]);

    return (
        <div className="relative isolate overflow-hidden rounded-t-2xl bg-gradient-to-b from-white dark:from-slate-800/50">
            {/* Badge de tipo de membresía */}
            <div className="absolute top-4 right-4 z-10">
                <Badge color={hasOdessaAfiliateAccount ? "yellow" : "blue"} className="text-lg px-4 py-2">
                    {hasOdessaAfiliateAccount ? (
                        <>
                            <StarIconSolid className="size-5 mr-1 text-yellow-400" />
                            Membresía Premium
                        </>
                    ) : (
                        <>
                            <StarIconOutline className="size-5 mr-1 text-blue-400" />
                            Membresía Básica
                        </>
                    )}
                </Badge>
            </div>

            <div className="relative pb-24 pt-10 lg:grid lg:grid-cols-2 lg:gap-x-8 lg:px-8 lg:py-40">
                <div className="mx-auto flex max-w-sm flex-col items-center space-y-10 px-6 lg:-mt-24 lg:px-0">
                    <div className="text-center">
                        <Badge color="slate">
                            <QrCodeIcon className="size-4" />
                            Número de identificación
                        </Badge>
                        <Text>
                            <span className="text-5xl text-famedic-dark dark:text-famedic-light">
                                {medicalAttentionIdentifier}
                            </span>
                        </Text>
                    </div>
                    
                    <div className="text-center">
                        <Badge color="slate">
                            <CalendarDaysIcon className="size-4" />
                            Vigencia
                        </Badge>
                        <Text>
                            {formattedMedicalAttentionSubscriptionExpiresAt}
                        </Text>
                    </div>

                    {/* Sección de teléfono con estilo diferenciado */}
                    <div className="text-center w-full">
                        <Badge 
                            color={hasOdessaAfiliateAccount ? "yellow" : "blue"}
                            className="mb-2"
                        >
                            <PhoneIcon className="size-4" />
                            Línea de atención {hasOdessaAfiliateAccount ? 'Premium' : 'Básica'}
                        </Badge>
                        <Anchor 
                            href={`tel:+52${phoneNumber}`}
                            className="block"
                        >
                            <Button
                                color={hasOdessaAfiliateAccount ? "yellow" : "blue"}
                                className="text-2xl py-4 px-8 w-full"
                            >
                                <PhoneIcon className="size-6" />
                                {formattedPhone}
                            </Button>
                        </Anchor>
                        <Text className="text-sm mt-2 text-gray-500">
                            {hasOdessaAfiliateAccount 
                                ? '⭐ Línea exclusiva para miembros Premium' 
                                : '🔵 Línea de atención general'}
                        </Text>
                    </div>

                    <div className="text-center w-full">
                        <div>
                            <Badge color="slate">
                                <UserGroupIcon className="size-4" />
                                Familiares
                            </Badge>
                        </div>
                        {familyAccounts.length > 0 ? (
                            <Table>
                                <TableHead>
                                    <TableRow>
                                        <TableHeader>Familiar</TableHeader>
                                        <TableHeader className="text-right">
                                            Número de identificación
                                        </TableHeader>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {familyAccounts.map((familyAccount) => (
                                        <TableRow key={familyAccount.id}>
                                            <TableCell>
                                                {familyAccount.full_name}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Code>
                                                    {
                                                        familyAccount
                                                            .customer
                                                            .medical_attention_identifier
                                                    }
                                                </Code>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        ) : (
                            <Text className="text-gray-500 mt-2">
                                No tienes familiares registrados
                            </Text>
                        )}
                        <Button
                            href={route("family.index")}
                            outline
                            className="mt-6"
                        >
                            Gestionar familia
                        </Button>
                    </div>

                    <Text className="text-center">
                        Marca o presiona el número {hasOdessaAfiliateAccount ? '⭐' : '🔵'} para iniciar
                        una conversación con un doctor y obtener la atención
                        médica que necesitas.
                    </Text>

                    <div className="w-full space-y-3">
                        <Button
                            onClick={requestIframe}
                            disabled={isLoadingIframe}
                            className="w-full"
                        >
                            {isLoadingIframe
                                ? "Cargando portal..."
                                : "Solicitar asistencia"}
                        </Button>

                        {iframeError && (
                            <Text className="text-sm text-red-500">
                                {iframeError}
                            </Text>
                        )}
                    </div>
                </div>

                <div className="mt-20 sm:mt-24 md:mx-auto md:max-w-2xl lg:mx-0 lg:mt-0 lg:w-screen">
                    {iframeUrl ? (
                        <iframe
                            src={iframeUrl}
                            title="Murguia asistencia"
                            className="h-[640px] w-full rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700"
                            loading="lazy"
                            allow="camera; microphone; geolocation; fullscreen"
                        />
                    ) : (
                        <video
                            controls
                            poster="https://images.pexels.com/photos/5998445/pexels-photo-5998445.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
                            className="w-full rounded-2xl object-cover shadow-2xl"
                        >
                            <source
                                src="/images/murguia.mp4"
                                type="video/mp4"
                            />
                            Your browser does not support the video tag.
                        </video>
                    )}
                </div>
            </div>
        </div>
    );
}