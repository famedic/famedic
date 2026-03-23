import { BuildingStorefrontIcon, MapPinIcon, ClockIcon, PhoneIcon } from "@heroicons/react/24/outline";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import Card from "@/Components/Card";

export default function StoresTabContent({ purchase }) {
    const hasAppointment = purchase.laboratory_appointment;
    
    if (hasAppointment) {
        const appointment = purchase.laboratory_appointment;
        const store = appointment.laboratory_store;
        
        return (
            <div className="space-y-6">
                <Card className="p-6">
                    <div className="flex items-center gap-3 mb-4">
                        <BuildingStorefrontIcon className="size-6 text-famedic-500" />
                        <Text className="text-lg font-semibold">Sucursal asignada</Text>
                    </div>
                    
                    <div className="bg-blue-50 dark:bg-blue-950/20 rounded-lg p-4 mb-4">
                        <Text className="font-medium text-lg mb-2">{store?.name || 'Sucursal no especificada'}</Text>
                        <div className="space-y-2 text-sm">
                            <div className="flex items-start gap-2">
                                <MapPinIcon className="size-4 text-gray-500 mt-0.5 flex-shrink-0" />
                                <Text>{store?.address || 'Dirección no disponible'}</Text>
                            </div>
                            {store?.phone && (
                                <div className="flex items-center gap-2">
                                    <PhoneIcon className="size-4 text-gray-500" />
                                    <Text>{store.phone}</Text>
                                </div>
                            )}
                        </div>
                    </div>
                    
                    <div className="flex items-center gap-2 mb-4">
                        <ClockIcon className="size-4 text-famedic-500" />
                        <Text>
                            <Strong>Cita programada:</Strong> {appointment.formatted_appointment_date || 'Fecha por confirmar'}
                        </Text>
                    </div>
                    
                    {store?.google_maps_url && (
                        <a href={store.google_maps_url} target="_blank" rel="noopener noreferrer">
                            <Button outline className="w-full sm:w-auto">
                                <MapPinIcon className="size-4" />
                                Ver en Google Maps
                            </Button>
                        </a>
                    )}
                    
                    {appointment.notes && (
                        <div className="mt-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <Text className="text-sm text-gray-500">{appointment.notes}</Text>
                        </div>
                    )}
                </Card>
            </div>
        );
    }
    
    return (
        <div className="space-y-6">
            <Card className="p-6">
                <div className="flex items-center gap-3 mb-4">
                    <BuildingStorefrontIcon className="size-6 text-famedic-500" />
                    <Text className="text-lg font-semibold">Sucursales disponibles</Text>
                </div>
                
                <Text className="mb-4">
                    Puedes acudir a cualquiera de las sucursales de {purchase.brand || 'GDA'} para realizarte tus estudios
                </Text>
                
                <Button
                    href={route("laboratory-stores.index", { brand: purchase.brand })}
                    outline
                >
                    <MapPinIcon className="size-4" />
                    Ver todas las sucursales
                </Button>
            </Card>
        </div>
    );
}