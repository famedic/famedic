import { UserIcon } from "@heroicons/react/24/outline";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import Card from "@/Components/Card";

export default function PatientTabContent({ purchase }) {
    return (
        <div className="space-y-6">
            <Card className="p-6">
                <div className="flex items-center gap-3 mb-4">
                    <UserIcon className="size-6 text-famedic-500" />
                    <Text className="text-lg font-semibold">Información del paciente</Text>
                </div>
                
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Text className="text-sm text-gray-500">Nombre completo</Text>
                        <Text className="font-medium mt-1">
                            {purchase.temporarly_hide_gda_order_id 
                                ? "Nombre de paciente pendiente" 
                                : purchase.full_name}
                        </Text>
                    </div>
                    
                    <div>
                        <Text className="text-sm text-gray-500">Teléfono</Text>
                        <Text className="font-medium mt-1">{purchase.phone}</Text>
                    </div>
                    
                    <div>
                        <Text className="text-sm text-gray-500">Fecha de nacimiento</Text>
                        <Text className="font-medium mt-1">{purchase.formatted_birth_date || 'No especificada'}</Text>
                    </div>
                    
                    <div>
                        <Text className="text-sm text-gray-500">Género</Text>
                        <Badge color="slate" className="mt-1">{purchase.formatted_gender || 'No especificado'}</Badge>
                    </div>
                </div>
            </Card>
            
            {purchase.notes && (
                <Card className="p-6">
                    <Text className="text-sm text-gray-500 mb-2">Notas adicionales</Text>
                    <Text>{purchase.notes}</Text>
                </Card>
            )}
        </div>
    );
}