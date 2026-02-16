import { useForm } from "@inertiajs/react";
import { useState, useEffect } from "react";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";

export default function PaymentMethodDeleteConfirmation({
    isOpen,
    close,
    paymentMethod,
}) {
    const { delete: destroy, processing } = useForm({});
    const [cachedPaymentMethod, setCachedPaymentMethod] = useState(null);

    // Sincronizar cuando se abra el modal
    useEffect(() => {
        if (isOpen && paymentMethod) {
            setCachedPaymentMethod(paymentMethod);
        }
    }, [isOpen, paymentMethod]);

    // Si no hay método seleccionado, no renderizar nada
    if (!isOpen || !cachedPaymentMethod) {
        return null;
    }

    const handleDestroy = () => {
    if (!processing && cachedPaymentMethod?.id) {
        destroy(
            route("payment-methods.destroy", {
                payment_method: cachedPaymentMethod.id, 
            }),
            {
                preserveScroll: true,
                onSuccess: () => close(),
            }
        );
    }
};


    // Datos seguros
    const brand = cachedPaymentMethod?.card_brand || "";
    const last4 = cachedPaymentMethod?.card_last_four || "";

    return (
        <DeleteConfirmationModal
            isOpen={isOpen}
            close={close}
            title={`Eliminar tarjeta "${brand} •••• ${last4}"`}
            description="¿Estás seguro de que deseas eliminar tu tarjeta? Esta acción no se puede deshacer."
            processing={processing}
            destroy={handleDestroy}
        />
    );
}
