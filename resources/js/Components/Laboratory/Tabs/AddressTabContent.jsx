import { MapPinIcon, HomeIcon, BuildingOfficeIcon } from "@heroicons/react/24/outline";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";

export default function AddressTabContent({ purchase }) {
    return (
        <Card className="p-6">
            <div className="flex items-center gap-3 mb-4">
                <MapPinIcon className="size-6 text-famedic-500" />
                <Text className="text-lg font-semibold">Dirección de envío</Text>
            </div>
            
            <div className="space-y-2">
                <Text>
                    {purchase.street} {purchase.number}
                </Text>
                <Text>
                    {purchase.neighborhood}, {purchase.zipcode}
                </Text>
                <Text>
                    {purchase.city}, {purchase.state}
                </Text>
                {purchase.additional_references && (
                    <Text className="text-sm text-gray-500 mt-3">
                        Referencias: {purchase.additional_references}
                    </Text>
                )}
            </div>
        </Card>
    );
}