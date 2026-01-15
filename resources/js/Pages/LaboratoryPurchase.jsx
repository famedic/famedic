import SettingsLayout from "@/Layouts/SettingsLayout";
import Purchase from "@/Components/Purchase";
import Card from "@/Components/Card";
import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';

export default function LaboratoryPurchase({ laboratoryPurchase, confetti }) {
    const { props } = usePage();

    useEffect(() => {
        // Verificar si ya se envió el evento (evitar duplicados)
        if (laboratoryPurchase && !window.ga4PurchaseSent) {
            console.log('DEBUG: Enviando evento GA4 purchase para orden:', laboratoryPurchase.id);
            
            window.dataLayer = window.dataLayer || [];
            
            // Calcular valores
            const totalValue = laboratoryPurchase.total_cents / 100; // Convertir de centavos
            const taxValue = laboratoryPurchase.tax_cents ? laboratoryPurchase.tax_cents / 100 : 0;
            const shippingValue = laboratoryPurchase.shipping_cents ? laboratoryPurchase.shipping_cents / 100 : 0;
            
            // Preparar items para GA4
            const items = laboratoryPurchase.laboratory_purchase_items?.map((item, index) => ({
                item_id: item.gda_id || `lab_${item.id}`,
                item_name: item.name || 'Laboratory Test',
                price: item.price_cents ? item.price_cents / 100 : 0,
                quantity: 1, // Cada item es cantidad 1 según tu modelo
                item_category: 'Laboratory Tests',
                item_brand: laboratoryPurchase.brand?.value || 'laboratory',
                index: index,
                item_variant: item.laboratory_test?.category || 'General',
                // Agregar más campos según disponibilidad
            })) || [];
            
            // Enviar evento de compra
            window.dataLayer.push({
                event: 'purchase',
                ecommerce: {
                    transaction_id: laboratoryPurchase.id.toString(),
                    value: totalValue,
                    tax: taxValue,
                    shipping: shippingValue,
                    currency: 'MXN',
                    coupon: laboratoryPurchase.coupon_code || '',
                    items: items
                }
            });
            
            console.log('DEBUG: Evento GA4 enviado:', {
                transaction_id: laboratoryPurchase.id,
                value: totalValue,
                item_count: items.length
            });
            
            window.ga4PurchaseSent = true; // Marcar como enviado
        }
        
        // Opcional: También enviar evento view_item para cada producto
        if (laboratoryPurchase?.laboratory_purchase_items) {
            laboratoryPurchase.laboratory_purchase_items.forEach((item, index) => {
                window.dataLayer.push({
                    event: 'view_item',
                    ecommerce: {
                        currency: 'MXN',
                        value: item.price_cents ? item.price_cents / 100 : 0,
                        items: [{
                            item_id: item.gda_id || `lab_${item.id}`,
                            item_name: item.name || 'Laboratory Test',
                            price: item.price_cents ? item.price_cents / 100 : 0,
                            quantity: 1,
                            item_category: 'Laboratory Tests',
                            index: index
                        }]
                    }
                });
            });
        }
        
    }, [laboratoryPurchase]); // Solo se ejecuta cuando laboratoryPurchase cambia

    return (
        <SettingsLayout title="Pedido de laboratorio">
            <Card className="space-y-8 p-6 lg:space-y-10 lg:p-12">
                <Purchase purchase={laboratoryPurchase} isLabPurchase={true} />
            </Card>
        </SettingsLayout>
    );
}