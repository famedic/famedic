import { useState } from "react";
import axios from "axios";
import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import Card from "@/Components/Card";
import { 
    ClockIcon,
    DocumentTextIcon,
    ArrowDownTrayIcon,
    CheckCircleIcon,
    BeakerIcon
} from "@heroicons/react/24/outline";

export default function LaboratoryStatusPanel({
    laboratoryPurchase,
    latestSampleCollectionAt,
    latestResultsAt,
    hasSampleCollected,
    hasResultsAvailable
}) {
    const [loadingResults, setLoadingResults] = useState(false);
    const [errorMessage, setErrorMessage] = useState("");

    const fetchResults = async () => {
        if (loadingResults) return;
        
        setLoadingResults(true);
        setErrorMessage("");

        try {
            const url = route(
                "laboratory-purchases.results.automatic-fetch",
                { laboratoryPurchase: laboratoryPurchase.id }
            );

            console.log("🌐 Fetching results from:", url);

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

            const response = await axios.post(url, {}, {
                withCredentials: true,
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });

            if (response.data.success && response.data.pdf_base64) {
                const pdfBase64 = response.data.pdf_base64;
                
                const byteCharacters = atob(pdfBase64);
                const byteNumbers = new Array(byteCharacters.length)
                    .fill(0)
                    .map((_, i) => byteCharacters.charCodeAt(i));
                const byteArray = new Uint8Array(byteNumbers);
                const blob = new Blob([byteArray], { type: "application/pdf" });
                const urlBlob = window.URL.createObjectURL(blob);
                window.open(urlBlob, "_blank");
            } else {
                setErrorMessage(response.data.message || "No se pudieron obtener los resultados");
            }

        } catch (error) {
            console.error("Error fetching results:", error);
            
            if (error.response) {
                if (error.response.status === 403) {
                    setErrorMessage("No autorizado para ver estos resultados");
                } else if (error.response.status === 404) {
                    setErrorMessage("Resultados no encontrados o aún no disponibles");
                } else if (error.response.status === 419) {
                    setErrorMessage("Sesión expirada. Por favor recarga la página");
                } else if (error.response.status === 500) {
                    setErrorMessage("Error interno del servidor. Contacta a soporte");
                } else {
                    setErrorMessage(error.response.data.message || "Error al obtener los resultados");
                }
            } else if (error.request) {
                setErrorMessage("No se pudo conectar con el servidor");
            } else {
                setErrorMessage("Error inesperado al procesar la solicitud");
            }
        }

        setLoadingResults(false);
    };

    // Si no hay ninguna información de GDA, no mostrar nada
    if (!hasSampleCollected && !hasResultsAvailable) {
        return null;
    }

    return (
        <Card className="p-6 lg:p-8">
            <div className="space-y-6">
                {/* Header */}
                <div>
                    <Subheading className="flex items-center gap-2">
                        <BeakerIcon className="size-5 stroke-famedic-500 dark:stroke-famedic-400" />
                        Estado del estudio
                    </Subheading>
                    <Text className="mt-1">
                        Información sincronizada automáticamente con el laboratorio.
                    </Text>
                </div>

                {/* Error Message */}
                {errorMessage && (
                    <Card className="p-4 bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-800">
                        <div className="flex items-center gap-3">
                            <ClockIcon className="size-5 flex-shrink-0 text-red-500" />
                            <Text className="text-red-700 dark:text-red-300">
                                <Strong>Error:</Strong> {errorMessage}
                            </Text>
                        </div>
                    </Card>
                )}

                {/* Status Cards */}
                <div className="space-y-4">
                    {/* Sample Collection - Solo mostrar si hay muestra tomada */}
                    {hasSampleCollected && (
                        <div className="flex items-center justify-between bg-white dark:bg-slate-900 border rounded-lg p-4">
                            <div className="flex flex-col">
                                <div className="flex items-center gap-2">
                                    <BeakerIcon className="size-5 stroke-amber-500 dark:stroke-amber-400" />
                                    <Text className="font-medium">Toma de muestra</Text>
                                </div>
                                {latestSampleCollectionAt && (
                                    <Text className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                                        {latestSampleCollectionAt}
                                    </Text>
                                )}
                            </div>
                            <div className="flex items-center">
                                <Badge color="amber">Muestra tomada</Badge>
                            </div>
                        </div>
                    )}

                    {/* Results - Siempre mostrar si hay resultados disponibles */}
                    {hasResultsAvailable && (
                        <div className="flex items-center justify-between bg-white dark:bg-slate-900 border rounded-lg p-4">
                            <div className="flex flex-col">
                                <div className="flex items-center gap-2">
                                    <DocumentTextIcon className="size-5 stroke-emerald-500 dark:stroke-emerald-400" />
                                    <Text className="font-medium">Resultados</Text>
                                </div>
                                {latestResultsAt && (
                                    <Text className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                                        {latestResultsAt}
                                    </Text>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <Badge color="emerald">Disponibles</Badge>
                                
                                <Button
                                    onClick={fetchResults}
                                    disabled={loadingResults}
                                    outline
                                    className="min-w-[120px]"
                                >
                                    {loadingResults ? (
                                        <span className="flex items-center gap-2">
                                            <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                            </svg>
                                            <span>Descargando...</span>
                                        </span>
                                    ) : (
                                        <span className="flex items-center gap-2">
                                            <ArrowDownTrayIcon className="size-4" />
                                            <span>Descargar</span>
                                        </span>
                                    )}
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </Card>
    );
}