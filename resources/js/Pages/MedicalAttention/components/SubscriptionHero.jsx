// resources/js/Pages/MedicalAttention/components/SubscriptionHero.jsx
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Anchor, Code, Text } from "@/Components/Catalyst/text";
import {
    Tab,
    TabGroup,
    TabList,
    TabPanel,
    TabPanels,
} from "@/Components/Catalyst/tabs";
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
    ClipboardDocumentListIcon,
    ClockIcon,
    ComputerDesktopIcon,
    ExclamationTriangleIcon,
    ArrowTopRightOnSquareIcon,
    PhoneIcon,
    UserGroupIcon,
    QrCodeIcon,
    StarIcon as StarIconSolid,
} from "@heroicons/react/24/solid";
import { StarIcon as StarIconOutline } from "@heroicons/react/24/outline";
import { useEffect, useState } from "react";

const DEBUG_MURGUIA_ASSISTANCE = true;

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

    const debugLog = (...args) => {
        if (DEBUG_MURGUIA_ASSISTANCE) {
            console.log("[MedicalAttention][Murguia]", ...args);
        }
    };

    const requestIframe = async () => {
        if (isLoadingIframe) return;

        let popupWindow = window.open("about:blank", "_blank");
        if (popupWindow) {
            popupWindow.opener = null;
        }

        debugLog("Click en solicitar asistencia web (nueva ventana)", {
            popupOpened: Boolean(popupWindow),
            medicalAttentionIdentifier,
            hasOdessaAfiliateAccount,
        });

        if (!popupWindow) {
            setIframeError(
                "Tu navegador bloqueo la nueva pestaña. Permite popups para este sitio e intenta de nuevo."
            );
            return;
        }

        setIsLoadingIframe(true);
        setIframeError(null);

        try {
            debugLog("Solicitando URL de Murguia...");
            const response = await window.axios.get(route("murguia.iframe"));
            debugLog("Respuesta de murguia.iframe recibida", {
                hasUrl: Boolean(response?.data?.url),
                status: response?.status,
            });

            if (!response?.data?.url) {
                throw new Error("La respuesta no incluye URL.");
            }

            setIframeUrl(response.data.url);

            if (!popupWindow.closed) {
                popupWindow.location.href = response.data.url;
                debugLog("URL abierta en nueva ventana");
            }
        } catch (error) {
            debugLog("Error al solicitar asistencia", {
                message: error?.message,
                responseStatus: error?.response?.status,
                responseMessage: error?.response?.data?.message,
            });
            setIframeError(
                error?.response?.data?.message ||
                    "No pudimos abrir el portal de asistencias. Intenta de nuevo."
            );
            if (!popupWindow.closed) {
                popupWindow.close();
            }
        } finally {
            setIsLoadingIframe(false);
            debugLog("Fin del flujo de solicitud");
        }
    };

    useEffect(() => {
        debugLog("SubscriptionHero montado", {
            medicalAttentionIdentifier,
            planType,
            hasOdessaAfiliateAccount,
            familyMembersCount: familyAccounts?.length || 0,
        });
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
                    <TabGroup className="w-full">
                        <TabList className="w-full rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
                            <Tab className="flex-1">
                                {(selected) => (
                                    <div
                                        className={`rounded-lg px-4 py-2 text-sm font-medium ${
                                            selected
                                                ? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white"
                                                : "text-zinc-600 dark:text-zinc-300"
                                        }`}
                                    >
                                        Mi membresía
                                    </div>
                                )}
                            </Tab>
                            <Tab className="flex-1">
                                {(selected) => (
                                    <div
                                        className={`rounded-lg px-4 py-2 text-sm font-medium ${
                                            selected
                                                ? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white"
                                                : "text-zinc-600 dark:text-zinc-300"
                                        }`}
                                    >
                                        Asistencia web
                                    </div>
                                )}
                            </Tab>
                        </TabList>

                        <TabPanels className="mt-6">
                            <TabPanel className="space-y-8">
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
                            </TabPanel>

                            <TabPanel className="space-y-6">
                                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                                    <div className="flex items-center gap-2">
                                        <ExclamationTriangleIcon className="size-5 text-amber-500" />
                                        <Text className="font-medium">
                                            Al solicitar asistencia web, abriremos una pestaña nueva.
                                        </Text>
                                    </div>
                                    <Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                                        Te llevaremos al portal de administración de tu membresía para gestionar asistencias.
                                    </Text>
                                </div>

                                <div className="grid gap-2">
                                    <div className="flex items-center gap-2">
                                        <ComputerDesktopIcon className="size-5 text-famedic-light" />
                                        <Text className="text-sm">Portal de administración de membresía incluida</Text>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <ClockIcon className="size-5 text-famedic-light" />
                                        <Text className="text-sm">Seguimiento en tiempo real</Text>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <ClipboardDocumentListIcon className="size-5 text-famedic-light" />
                                        <Text className="text-sm">Control e historial de servicios</Text>
                                    </div>
                                </div>

                                <div className="text-center w-full">
                                    <Badge
                                        color={hasOdessaAfiliateAccount ? "yellow" : "blue"}
                                        className="mb-2"
                                    >
                                        <PhoneIcon className="size-4" />
                                        Línea de atención {hasOdessaAfiliateAccount ? "Premium" : "Básica"}
                                    </Badge>
                                    <Anchor href={`tel:+52${phoneNumber}`} className="block">
                                        <Button
                                            color={hasOdessaAfiliateAccount ? "yellow" : "blue"}
                                            className="text-2xl py-4 px-8 w-full"
                                        >
                                            <PhoneIcon className="size-6" />
                                            {formattedPhone}
                                        </Button>
                                    </Anchor>
                                </div>

                                <div className="w-full space-y-3">
                                    <Button
                                        onClick={requestIframe}
                                        disabled={isLoadingIframe}
                                        className="w-full"
                                    >
                                        <ArrowTopRightOnSquareIcon className="size-5" />
                                        {isLoadingIframe
                                            ? "Cargando portal..."
                                            : "Solicitar asistencia web"}
                                    </Button>

                                    {iframeError && (
                                        <Text className="text-sm text-red-500">
                                            {iframeError}
                                        </Text>
                                    )}
                                    {iframeUrl && (
                                        <Button
                                            outline
                                            className="w-full"
                                            href={iframeUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            Abrir asistencia en nueva ventana
                                        </Button>
                                    )}
                                </div>
                            </TabPanel>
                        </TabPanels>
                    </TabGroup>
                </div>

                <div className="mt-20 sm:mt-24 md:mx-auto md:max-w-2xl lg:mx-0 lg:mt-0 lg:w-screen">
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
                    <Text className="mt-2 text-xs text-zinc-500">
                        El portal de asistencias se abre siempre en una pestaña nueva.
                    </Text>
                </div>
            </div>
        </div>
    );
}