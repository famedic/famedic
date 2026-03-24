import { QrCodeIcon, CalendarIcon, HashtagIcon } from "@heroicons/react/24/outline";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import Card from "@/Components/Card";

export default function OrderInfoTabContent({ purchase }) {
    return (
        <div className="space-y-6">
            <Card className="p-6">
                <div className="flex items-center gap-3 mb-4">
                    <QrCodeIcon className="size-6 text-famedic-500" />
                    <Text className="text-lg font-semibold">Información de la orden</Text>
                </div>
                
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Text className="text-sm text-gray-500">Folio GDA</Text>
                        <div className="flex items-center gap-2 mt-1">
                            <Badge color="famedic" className="!text-lg">
                                <QrCodeIcon className="size-5" />
                                {purchase.gda_order_id}
                            </Badge>
                        </div>
                    </div>
                    
                    {purchase.gda_consecutivo && (
                        <div>
                            <Text className="text-sm text-gray-500">Consecutivo</Text>
                            <div className="flex items-center gap-2 mt-1">
                                <Badge color="slate" className="!text-lg font-mono">
                                    <HashtagIcon className="size-4" />
                                    {purchase.gda_consecutivo}
                                </Badge>
                            </div>
                        </div>
                    )}
                    
                    <div>
                        <Text className="text-sm text-gray-500">Fecha de creación</Text>
                        <div className="flex items-center gap-2 mt-1">
                            <CalendarIcon className="size-4 text-gray-400" />
                            <Text className="font-medium">{purchase.formatted_created_at}</Text>
                        </div>
                    </div>
                    
                    <div>
                        <Text className="text-sm text-gray-500">Marca del laboratorio</Text>
                        <div className="mt-2">
                            <img
                                src={`/images/gda/GDA-${purchase.brand?.toUpperCase() || 'GDA'}.png`}
                                className="h-12 object-contain"
                                alt={`Logo ${purchase.brand}`}
                                onError={(e) => {
                                    e.target.src = '/images/gda/GDA.png';
                                }}
                            />
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    );
}