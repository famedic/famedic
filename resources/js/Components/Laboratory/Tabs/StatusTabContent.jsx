import { ChartBarIcon, CheckCircleIcon, ClockIcon } from "@heroicons/react/24/outline";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";
import LaboratoryStatusPanel from "@/Components/Laboratory/LaboratoryStatusPanel";

export default function StatusTabContent({ 
    laboratoryPurchase, 
    latestSampleCollectionAt, 
    latestResultsAt, 
    hasSampleCollected, 
    hasResultsAvailable 
}) {
    return (
        <div className="space-y-6">
            <Card className="p-6">
                <div className="flex items-center gap-3 mb-4">
                    <ChartBarIcon className="size-6 text-famedic-500" />
                    <Text className="text-lg font-semibold">Sincronización automática</Text>
                </div>
                <Text className="text-gray-500 mb-4">
                    Esta información se actualiza automáticamente desde el sistema del laboratorio GDA.
                </Text>
                
                {/* Timeline */}
                <div className="space-y-4 mt-6">
                    <div className="flex items-start gap-3">
                        <div className="flex-shrink-0">
                            {hasSampleCollected ? (
                                <CheckCircleIcon className="size-5 text-emerald-500" />
                            ) : (
                                <ClockIcon className="size-5 text-gray-400" />
                            )}
                        </div>
                        <div className="flex-1">
                            <Text className="font-medium">Toma de muestra</Text>
                            {hasSampleCollected && latestSampleCollectionAt ? (
                                <Text className="text-sm text-gray-500">{latestSampleCollectionAt}</Text>
                            ) : (
                                <Text className="text-sm text-gray-400">Pendiente</Text>
                            )}
                        </div>
                    </div>
                    
                    <div className="flex items-start gap-3">
                        <div className="flex-shrink-0">
                            {hasResultsAvailable ? (
                                <CheckCircleIcon className="size-5 text-emerald-500" />
                            ) : (
                                <ClockIcon className="size-5 text-gray-400" />
                            )}
                        </div>
                        <div className="flex-1">
                            <Text className="font-medium">Resultados disponibles</Text>
                            {hasResultsAvailable && latestResultsAt ? (
                                <Text className="text-sm text-gray-500">{latestResultsAt}</Text>
                            ) : (
                                <Text className="text-sm text-gray-400">En proceso</Text>
                            )}
                        </div>
                    </div>
                </div>
            </Card>
            
            {/* Original Status Panel with actions */}
            {(hasSampleCollected || hasResultsAvailable) && (
                <LaboratoryStatusPanel
                    laboratoryPurchase={laboratoryPurchase}
                    latestSampleCollectionAt={latestSampleCollectionAt}
                    latestResultsAt={latestResultsAt}
                    hasSampleCollected={hasSampleCollected}
                    hasResultsAvailable={hasResultsAvailable}
                />
            )}
        </div>
    );
}