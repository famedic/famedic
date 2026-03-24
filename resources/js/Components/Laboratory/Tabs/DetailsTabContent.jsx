import { ShoppingBagIcon, CurrencyDollarIcon, TagIcon } from "@heroicons/react/24/outline";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import { Divider } from "@/Components/Catalyst/divider";
import Card from "@/Components/Card";

export default function DetailsTabContent({ purchase }) {
    const purchaseItems = purchase.laboratory_purchase_items || [];
    
    // Helper function for formatting prices
    const formatPrice = (price) => {
        if (!price) return '$0.00';
        if (typeof price === 'number') {
            return new Intl.NumberFormat('es-MX', {
                style: 'currency',
                currency: 'MXN'
            }).format(price);
        }
        return price;
    };
    
    return (
        <div className="space-y-6">
            {/* Items List */}
            <Card className="p-6">
                <div className="flex items-center gap-3 mb-6">
                    <ShoppingBagIcon className="size-6 text-famedic-500" />
                    <Text className="text-lg font-semibold">Estudios solicitados</Text>
                </div>
                
                <div className="space-y-4">
                    {purchaseItems.length === 0 ? (
                        <Text className="text-gray-500 text-center py-4">No hay estudios registrados</Text>
                    ) : (
                        purchaseItems.map((item, index) => (
                            <div key={item.id}>
                                <div className="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-3">
                                    <div className="flex-1">
                                        <div className="flex items-start gap-2">
                                            <TagIcon className="size-4 text-gray-400 mt-1 flex-shrink-0" />
                                            <div>
                                                <Text className="font-medium">{item.name}</Text>
                                                {item.indications && (
                                                    <Text className="text-sm text-gray-500 mt-1">
                                                        {item.indications}
                                                    </Text>
                                                )}
                                                {item.gda_id && (
                                                    <Text className="text-xs font-mono text-gray-400 mt-1">
                                                        Código: {item.gda_id}
                                                    </Text>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <Text className="font-medium">
                                            {item.formatted_price || formatPrice(item.price)}
                                        </Text>
                                    </div>
                                </div>
                                {index < purchaseItems.length - 1 && <Divider className="my-4" />}
                            </div>
                        ))
                    )}
                </div>
            </Card>
            
            {/* Totals */}
            <Card className="p-6">
                <div className="flex items-center gap-3 mb-4">
                    <CurrencyDollarIcon className="size-6 text-famedic-500" />
                    <Text className="text-lg font-semibold">Resumen de pago</Text>
                </div>
                
                <div className="space-y-3">
                    <div className="flex justify-between">
                        <Text>Subtotal</Text>
                        <Text>{purchase.formatted_subtotal || formatPrice(purchase.subtotal)}</Text>
                    </div>
                    
                    {purchase.tax_cents !== 0 && (
                        <div className="flex justify-between">
                            <Text>IVA</Text>
                            <Text>{purchase.formatted_tax || formatPrice(purchase.tax)}</Text>
                        </div>
                    )}
                    
                    {purchase.discount_cents !== 0 && (
                        <div className="flex justify-between text-green-600">
                            <Text>Descuento</Text>
                            <Text>-{purchase.formatted_discount || formatPrice(purchase.discount)}</Text>
                        </div>
                    )}
                    
                    <Divider className="my-2" />
                    
                    <div className="flex justify-between">
                        <Subheading>Total</Subheading>
                        <Subheading>{purchase.formatted_total || formatPrice(purchase.total)}</Subheading>
                    </div>
                </div>
            </Card>
        </div>
    );
}