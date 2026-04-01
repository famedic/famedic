// resources/js/Pages/MedicalAttention/components/SubscribeModal.jsx
import {
    Dialog,
    DialogTitle,
    DialogDescription,
    DialogActions,
} from "@/Components/Catalyst/dialog";
import { Button } from "@/Components/Catalyst/button";
import { useForm } from "@inertiajs/react";
import { useEffect } from "react";
import PaymentMethodStep from "@/Components/Checkout/PaymentMethodStep";
import CoverageDetails from "./CoverageDetails";

export default function SubscribeModal({
    isOpen,
    setIsOpen,
    paymentMethods,
    formattedPrice,
    formattedMedicalAttentionSubscriptionExpiresAt,
}) {
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        payment_method: null,
    });

    // Logs de depuración
    useEffect(() => {
        if (true) {
            console.group('📋 COMPONENTE: SubscribeModal');
            console.log('📥 Props:');
            console.log('  - isOpen:', isOpen);
            console.log('  - formattedPrice:', formattedPrice);
            console.log('  - formattedMedicalAttentionSubscriptionExpiresAt:', formattedMedicalAttentionSubscriptionExpiresAt);
            
            if (formattedMedicalAttentionSubscriptionExpiresAt) {
                console.log('💰 Modo: Pago - Suscripción por', formattedPrice);
            } else {
                console.log('🎁 Modo: Prueba gratuita');
            }
            
            console.log('📊 Estado del formulario:');
            console.log('  - payment_method seleccionado:', data.payment_method);
            console.groupEnd();
        }
    }, [isOpen, formattedPrice, formattedMedicalAttentionSubscriptionExpiresAt, data.payment_method]);

    const submit = (e) => {
        e.preventDefault();

        if (!processing) {
            if (formattedMedicalAttentionSubscriptionExpiresAt) {
                console.log('🚀 Enviando solicitud de suscripción de pago');
                post(route("medical-attention.subscription"));
            } else {
                console.log('🚀 Enviando solicitud de prueba gratuita');
                post(route("free-medical-attention.subscription"));
            }
        }
    };

    return (
        <Dialog open={isOpen} onClose={setIsOpen}>
            <form onSubmit={submit}>
                <DialogTitle>
                    {formattedMedicalAttentionSubscriptionExpiresAt
                        ? "Suscribirse por " + formattedPrice
                        : "Comenzar prueba gratuita"}
                </DialogTitle>
                <DialogDescription as="div" className="space-y-4">
                    {!formattedMedicalAttentionSubscriptionExpiresAt && (
                        <p className="text-zinc-700 dark:text-slate-300">
                            No se necesita una tarjeta de crédito para el
                            período de prueba.
                        </p>
                    )}

                    <CoverageDetails />

                    {formattedMedicalAttentionSubscriptionExpiresAt && (
                        <PaymentMethodStep
                            forceMobile={true}
                            data={data}
                            setData={setData}
                            errors={errors}
                            error={errors.payment_method}
                            clearErrors={clearErrors}
                            paymentMethods={paymentMethods}
                            hasOdessaPay={true}
                            addCardReturnUrl={route("medical-attention")}
                        />
                    )}
                </DialogDescription>
                <DialogActions>
                    <Button
                        disabled={processing}
                        dusk="cancel"
                        plain
                        type="button"
                        onClick={() => setIsOpen(false)}
                        autoFocus
                    >
                        Cancelar
                    </Button>
                    <Button
                        disabled={processing}
                        type="submit"
                        className={processing && "opacity-0"}
                    >
                        {formattedMedicalAttentionSubscriptionExpiresAt
                            ? "Suscribirse por " + formattedPrice
                            : "Comenzar periodo de prueba"}
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}