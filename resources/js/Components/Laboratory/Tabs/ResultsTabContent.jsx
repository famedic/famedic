import { BeakerIcon, DocumentTextIcon, ArrowDownTrayIcon, EyeIcon, CloudArrowDownIcon, UserIcon } from "@heroicons/react/24/outline";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import Card from "@/Components/Card";
import { useState } from "react";
import axios from "axios";

export default function ResultsTabContent({
    laboratoryPurchase,
    latestResultsAt,
    hasResultsAvailable,
    hasManualResults,
    requireOtpThen
}) {
    const [loadingAutomatic, setLoadingAutomatic] = useState(false);
    const [loadingManual, setLoadingManual] = useState(false);
    const [error, setError] = useState("");

    // Verificar si hay resultados automáticos de GDA
    const hasAutomaticResults = hasResultsAvailable;

    // Verificar si hay resultados manuales cargados por admin
    const hasManualResultsFlag = laboratoryPurchase.results || hasManualResults;

    const doFetchAutomaticResults = async () => {
        if (loadingAutomatic) return;

        setLoadingAutomatic(true);
        setError("");

        try {
            const url = route("laboratory-purchases.results.automatic-fetch", {
                laboratoryPurchase: laboratoryPurchase.id
            });

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await axios.post(url, {}, {
                withCredentials: true,
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            });

            if (response.data.success && response.data.pdf_base64) {
                const byteCharacters = atob(response.data.pdf_base64);
                const byteNumbers = new Array(byteCharacters.length);
                for (let i = 0; i < byteCharacters.length; i++) {
                    byteNumbers[i] = byteCharacters.charCodeAt(i);
                }
                const byteArray = new Uint8Array(byteNumbers);
                const blob = new Blob([byteArray], { type: "application/pdf" });
                const urlBlob = window.URL.createObjectURL(blob);
                window.open(urlBlob, "_blank");
            } else {
                setError(response.data.message || "No se pudieron obtener los resultados automáticos");
            }
        } catch (err) {
            console.error("Error fetching automatic results:", err);
            if (err.response?.status === 404) {
                setError("Aún no hay resultados disponibles. Por favor espera a que el laboratorio los procese.");
            } else {
                setError(err.response?.data?.message || "Error al descargar los resultados automáticos");
            }
        }

        setLoadingAutomatic(false);
    };

    // Descargar resultados automáticos de GDA (protegido por OTP)
    const fetchAutomaticResults = async () => {
        if (loadingAutomatic) return;
        if (requireOtpThen && requireOtpThen(() => doFetchAutomaticResults()) === false) return;
        return doFetchAutomaticResults();
    };

    const doViewManualResults = () => {
        setLoadingManual(true);
        window.open(route("laboratory-purchases.results", {
            laboratory_purchase: laboratoryPurchase
        }), "_blank");
        setLoadingManual(false);
    };

    // Ver resultados manuales (protegido por OTP)
    const viewManualResults = () => {
        if (requireOtpThen && requireOtpThen(() => doViewManualResults()) === false) return;
        return doViewManualResults();
    };

    return (
        <div className="space-y-6">
            {/* Resultados Automáticos de GDA */}
            {hasAutomaticResults && (
                <Card className="p-6">
                    <div className="flex items-center gap-3 mb-4">
                        <CloudArrowDownIcon className="size-6 text-emerald-500" />
                        <Text className="text-lg font-semibold">Resultados Automáticos (GDA)</Text>
                        <Badge color="emerald" className="ml-2">Sincronizado</Badge>
                    </div>

                    <div className="bg-emerald-50 dark:bg-emerald-950/20 rounded-lg p-4 mb-6">
                        <div className="flex items-center gap-2 mb-2">
                            <DocumentTextIcon className="size-5 text-emerald-600" />
                            <Text className="font-medium text-emerald-700 dark:text-emerald-300">
                                Resultados disponibles
                            </Text>
                        </div>
                        {latestResultsAt && (
                            <Text className="text-sm text-emerald-600 dark:text-emerald-400">
                                Disponibles desde: {latestResultsAt}
                            </Text>
                        )}
                        <Text className="text-xs text-emerald-600 dark:text-emerald-400 mt-1">
                            Estos resultados se sincronizan automáticamente con el laboratorio GDA
                        </Text>
                    </div>

                    <Button onClick={fetchAutomaticResults} disabled={loadingAutomatic} className="w-full sm:w-auto">
                        {loadingAutomatic ? (
                            <span className="flex items-center gap-2">
                                <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                Descargando...
                            </span>
                        ) : (
                            <span className="flex items-center gap-2">
                                <ArrowDownTrayIcon className="size-4" />
                                Descargar resultados PDF
                            </span>
                        )}
                    </Button>
                </Card>
            )}

            {/* Resultados Manuales (cargados por administradores) */}
            {hasManualResultsFlag && (
                <Card className="p-6">
                    <div className="flex items-center gap-3 mb-4">
                        <UserIcon className="size-6 text-blue-500" />
                        <Text className="text-lg font-semibold">Resultados Cargados Manualmente</Text>
                        <Badge color="sky" className="ml-2">Administrativo</Badge>
                    </div>

                    <div className="bg-blue-50 dark:bg-blue-950/20 rounded-lg p-4 mb-6">
                        <div className="flex items-center gap-2 mb-2">
                            <DocumentTextIcon className="size-5 text-blue-600" />
                            <Text className="font-medium text-blue-700 dark:text-blue-300">
                                Documento disponible
                            </Text>
                        </div>
                        <Text className="text-sm text-blue-600 dark:text-blue-400">
                            Estos resultados fueron cargados por el personal de Famedic
                        </Text>
                        {laboratoryPurchase.results_uploaded_at && (
                            <Text className="text-xs text-blue-600 dark:text-blue-400 mt-1">
                                Cargado el: {laboratoryPurchase.results_uploaded_at}
                            </Text>
                        )}
                    </div>

                    <Button onClick={viewManualResults} disabled={loadingManual} outline className="w-full sm:w-auto">
                        {loadingManual ? (
                            <span className="flex items-center gap-2">
                                <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                Cargando...
                            </span>
                        ) : (
                            <span className="flex items-center gap-2">
                                <EyeIcon className="size-4" />
                                Ver resultados
                            </span>
                        )}
                    </Button>
                </Card>
            )}

            {/* Mensaje cuando no hay ningún tipo de resultados */}
            {!hasAutomaticResults && !hasManualResultsFlag && (
                <Card className="p-6">
                    <div className="text-center py-8">
                        <BeakerIcon className="size-12 text-gray-300 mx-auto mb-3" />
                        <Text className="text-gray-500">Aún no hay resultados disponibles</Text>
                        <Text className="text-sm text-gray-400 mt-1">
                            Los resultados estarán disponibles cuando:
                        </Text>
                        <ul className="text-sm text-gray-400 mt-2 list-disc list-inside">
                            <li>El laboratorio GDA los procese (sincronización automática)</li>
                            <li>El personal de Famedic los cargue manualmente</li>
                        </ul>
                        <Text className="text-xs text-gray-400 mt-3">
                            Si tienes dudas, contacta a nuestro equipo de soporte
                        </Text>
                    </div>
                </Card>
            )}

            {/* Mostrar error si existe */}
            {error && (
                <Card className="p-4 bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-800">
                    <div className="flex items-center gap-3">
                        <DocumentTextIcon className="size-5 flex-shrink-0 text-red-500" />
                        <Text className="text-red-700 dark:text-red-300">
                            <Strong>Error:</Strong> {error}
                        </Text>
                    </div>
                </Card>
            )}
        </div>
    );
}
