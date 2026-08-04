// resources/js/Pages/MedicalAttention/components/SubscribeModal.jsx
import {
    Dialog,
    DialogTitle,
    DialogDescription,
    DialogActions,
} from "@/Components/Catalyst/dialog";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { useForm, usePage } from "@inertiajs/react";
import { CalendarDaysIcon } from "@heroicons/react/24/solid";
import PaymentMethodStep from "@/Components/Checkout/PaymentMethodStep";
import CoverageDetails from "./CoverageDetails";

export default function SubscribeModal({
    isOpen,
    setIsOpen,
    paymentMethods = [],
    formattedPrice,
    formattedMedicalAttentionSubscriptionExpiresAt,
}) {
    const {
        medicalAttentionTrialEnabled = false,
        hasOdessaAfiliateAccount = false,
        paymentUsesMock = false,
    } = usePage().props;

    const usesPaidSubscription =
        !medicalAttentionTrialEnabled ||
        !!formattedMedicalAttentionSubscriptionExpiresAt;

    const { data, setData, post, processing, errors, clearErrors } = useForm({
        payment_method: null,
    });

    const submit = (e) => {
        e.preventDefault();

        if (!processing) {
            if (usesPaidSubscription) {
                post(route("medical-attention.subscription"));
            } else {
                post(route("free-medical-attention.subscription"));
            }
        }
    };

    return (
        <Dialog open={isOpen} onClose={() => setIsOpen(false)}>
            <form onSubmit={submit}>
                <DialogTitle>
                    {usesPaidSubscription
                        ? "Membresía anual — " + formattedPrice
                        : "Comenzar prueba gratuita"}
                </DialogTitle>
                <DialogDescription as="div" className="space-y-4">
                    {usesPaidSubscription && (
                        <Badge color="blue" className="text-sm">
                            <CalendarDaysIcon className="size-4" />
                            Plan anual · 12 meses de cobertura
                        </Badge>
                    )}

                    {usesPaidSubscription && (
                        <Text className="text-sm text-zinc-600 dark:text-zinc-400">
                            Un pago de {formattedPrice} activa tu membresía
                            familiar por un año completo.
                        </Text>
                    )}

                    {errors.general && (
                        <Text className="text-red-600 dark:text-red-400">
                            {errors.general}
                        </Text>
                    )}

                    <CoverageDetails />

                    {usesPaidSubscription && (
                        <PaymentMethodStep
                            forceMobile={true}
                            data={data}
                            setData={setData}
                            errors={errors}
                            error={errors.payment_method}
                            clearErrors={clearErrors}
                            paymentMethods={paymentMethods}
                            hasOdessaPay={hasOdessaAfiliateAccount}
                            paymentUsesMock={paymentUsesMock}
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
                        {usesPaidSubscription
                            ? "Pagar " + formattedPrice + " — plan anual"
                            : "Comenzar periodo de prueba"}
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}
