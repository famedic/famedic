import { useMemo } from "react";
import { Link, useForm } from "@inertiajs/react";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { ChevronLeftIcon } from "@heroicons/react/16/solid";
import CheckoutLayout from "@/Layouts/CheckoutLayout";
import PaymentMethodStep from "@/Components/Checkout/PaymentMethodStep";
import CheckoutPlanDetails from "./components/CheckoutPlanDetails";
import MedicalAttentionPayPalButton from "./components/MedicalAttentionPayPalButton";

export default function MedicalAttentionCheckout({
    formattedPrice,
    paymentMethods = [],
    paymentUsesMock = false,
    hasOdessaPay = false,
    hasPayPal = false,
    paypalClientId = null,
    hasOdessaAfiliateAccount = false,
    checkoutReturnUrl,
}) {
    const initialPaymentMethod =
        new URLSearchParams(window.location.search).get("payment_method") ||
        null;

    const { data, setData, post, processing, errors, clearErrors } = useForm({
        payment_method: initialPaymentMethod,
    });

    const addCardReturnUrl = useMemo(() => {
        const params = {};
        if (data.payment_method) {
            params.payment_method = data.payment_method;
        }

        return (
            checkoutReturnUrl ||
            route("medical-attention.checkout", params)
        );
    }, [data.payment_method, checkoutReturnUrl]);

    const summaryDetails = useMemo(
        () => [
            { label: "Plan", value: "Membresía médica anual" },
            { label: "Vigencia", value: "12 meses" },
            { label: "Cobertura", value: "Titular, cónyuge e hijos" },
            { label: "Total", value: formattedPrice },
        ],
        [formattedPrice],
    );

    const summaryItems = useMemo(
        () => [
            {
                heading: "Membresía médica anual",
                description:
                    "Telemedicina 24/7 y asistencias telefónicas para tu familia.",
                price: formattedPrice,
                showDefaultImage: true,
                features: [
                    "Telemedicina ilimitada 24/7",
                    "Asistencia psicológica, nutricional y legal",
                    hasOdessaAfiliateAccount
                        ? "Beneficios premium Odessa"
                        : "Plan básico Famedic",
                ],
            },
        ],
        [formattedPrice, hasOdessaAfiliateAccount],
    );

    const submit = async (e) => {
        e.preventDefault();

        if (!processing) {
            post(route("medical-attention.subscription"));
        }
    };

    const isPayPalSelected = data.payment_method === "paypal";

    return (
        <CheckoutLayout
            title="Comprar membresía"
            paymentDisabled={!data.payment_method || processing}
            onlinePaymentDisabled={
                isPayPalSelected
                    ? false
                    : !data.payment_method || processing
            }
            paymentProcessing={processing}
            submit={submit}
            summaryDetails={summaryDetails}
            items={summaryItems}
            alternateOnlinePayment={
                hasPayPal &&
                paypalClientId &&
                isPayPalSelected ? (
                    <MedicalAttentionPayPalButton
                        paypalClientId={paypalClientId}
                        disabled={processing}
                    />
                ) : undefined
            }
            header={
                <div className="flex flex-col gap-3">
                    <Link
                        href={route("medical-attention")}
                        className="inline-flex items-center gap-1 text-sm text-zinc-600 hover:text-famedic-light dark:text-zinc-400"
                    >
                        <ChevronLeftIcon className="size-4" />
                        Atención médica
                    </Link>
                    <GradientHeading noDivider>
                        Comprar membresía
                    </GradientHeading>
                    <Subheading>
                        <span className="text-xl lg:text-2xl">
                            Revisa tu plan y elige cómo deseas pagar.
                        </span>
                    </Subheading>
                </div>
            }
        >
            <CheckoutPlanDetails
                formattedPrice={formattedPrice}
                hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
            />

            {errors.general && (
                <Text className="text-red-600 dark:text-red-400">
                    {errors.general}
                </Text>
            )}

            <PaymentMethodStep
                forceMobile={true}
                data={data}
                setData={setData}
                errors={errors}
                error={errors.payment_method}
                clearErrors={clearErrors}
                paymentMethods={paymentMethods}
                hasOdessaPay={hasOdessaPay}
                hasPayPal={hasPayPal}
                paymentUsesMock={paymentUsesMock}
                addCardReturnUrl={addCardReturnUrl}
                description="Selecciona cómo deseas pagar tu membresía anual."
            />
        </CheckoutLayout>
    );
}
