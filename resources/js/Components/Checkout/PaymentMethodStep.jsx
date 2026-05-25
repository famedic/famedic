// PaymentMethodStep.jsx - Versión actualizada
import { useMemo } from "react";
import clsx from "clsx";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { useClose } from "@headlessui/react";
import { PlusIcon, CreditCardIcon } from "@heroicons/react/24/solid";
import CheckoutStep from "@/Components/Checkout/CheckoutStep";
import CheckoutWizardStep from "@/Components/Checkout/CheckoutWizardStep";
import CheckoutSelectionCard from "@/Components/Checkout/CheckoutSelectionCard";
import CreditCardBrand from "@/Components/CreditCardBrand";

const PAYPAL_LOGO_LIGHT =
	"https://cdn.simpleicons.org/paypal/003087";
const PAYPAL_LOGO_DARK =
	"https://cdn.simpleicons.org/paypal/FFFFFF";

function PayPalWordmark({ className = "h-7" }) {
	return (
		<>
			<img
				src={PAYPAL_LOGO_LIGHT}
				alt="PayPal"
				className={clsx(className, "w-auto max-w-[5.5rem] object-contain dark:hidden")}
			/>
			<img
				src={PAYPAL_LOGO_DARK}
				alt="PayPal"
				className={clsx(
					className,
					"hidden w-auto max-w-[5.5rem] object-contain dark:block",
				)}
			/>
		</>
	);
}

export default function PaymentMethodStep({
    data,
    setData,
    errors,
    clearErrors,
    description = "Selecciona el método de pago que deseas utilizar para tu pedido.",
    paymentMethods,
    hasOdessaPay,
    hasPayPal = false,
    addCardReturnUrl,
    forceMobile = false,
    paymentUsesMock = false,
    variant = "accordion",
    onSelected,
    ...props
}) {
    const selectedPaymentMethod = useMemo(() => {
        if (data.payment_method === "coupon_balance") {
            return "coupon_balance";
        }
        if (data.payment_method === "paypal") {
            return "paypal";
        }
        if (data.payment_method === "odessa") {
            return "odessa";
        }

        // Asegurar que data.payment_method sea string para comparar
        const paymentMethodId = String(data.payment_method);

        return paymentMethods.find(
            (paymentMethod) => String(paymentMethod.id) === paymentMethodId
        );
    }, [data.payment_method, paymentMethods]);

    const stepHeading = useMemo(() => {
        return data.payment_method
            ? "Método de pago"
            : "Selecciona el método de pago";
    }, [data.payment_method]);

    const isWizard = variant === "wizard";

    if (isWizard) {
        return (
            <CheckoutWizardStep
                title={stepHeading}
                description={description}
                error={props.error}
            >
                {paymentUsesMock && (
                    <div className="mb-3 rounded-lg border border-amber-200/80 bg-amber-50/60 px-3 py-2 text-xs text-amber-900 dark:border-amber-800/50 dark:bg-amber-950/25 dark:text-amber-100">
                        Tarjetas de prueba precargadas (Visa/Mastercard aprueban; terminación 0002 rechaza).
                    </div>
                )}
                <PaymentMethodSelection
                    variant={variant}
                    forceMobile={isWizard || forceMobile}
                    addCardReturnUrl={addCardReturnUrl}
                    setData={setData}
                    paymentMethods={paymentMethods}
                    hasOdessaPay={hasOdessaPay}
                    hasPayPal={hasPayPal}
                    clearErrors={clearErrors}
                    paymentUsesMock={paymentUsesMock}
                    selectedId={data.payment_method}
                    showRadio
                    onSelected={onSelected}
                />
            </CheckoutWizardStep>
        );
    }

    return (
        <CheckoutStep
            {...props}
            IconComponent={CreditCardIcon}
            heading={stepHeading}
            description={description}
            selectedContent={
                selectedPaymentMethod === "coupon_balance" ? (
                    <div>
                        <Text className="font-medium">Saldo a favor (cupón)</Text>
                        <Text className="text-sm text-gray-600 dark:text-gray-400">
                            El total se cubre con tu saldo disponible.
                        </Text>
                    </div>
                ) : selectedPaymentMethod === "paypal" ? (
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="inline-flex items-center rounded-lg bg-[#003087]/10 px-2.5 py-1.5 ring-1 ring-[#003087]/20 dark:bg-[#003087]/30 dark:ring-[#009cde]/30">
                            <PayPalWordmark className="h-6" />
                        </span>
                        <div>
                            <Text className="font-medium text-[#003087] dark:text-[#009cde]">
                                PayPal
                            </Text>
                            <Text className="text-sm text-slate-600 dark:text-slate-400">
                                Pago seguro con tu cuenta PayPal
                            </Text>
                        </div>
                    </div>
                ) : selectedPaymentMethod === "odessa" ? (
                    <div className="flex items-center gap-3">
                        <span className="inline-flex items-center justify-center rounded-lg bg-orange-100 p-2 ring-1 ring-orange-200 dark:bg-orange-950/50 dark:ring-orange-800/60">
                            <img
                                src="/images/odessa.png"
                                alt="Odessa"
                                className="h-7 w-7"
                            />
                        </span>
                        <div>
                            <Text className="font-medium text-orange-800 dark:text-orange-200">
                                Caja de ahorro Odessa
                            </Text>
                            <Text className="text-sm text-orange-700/90 dark:text-orange-300/90">
                                Cobro directo a tu caja de ahorro
                            </Text>
                        </div>
                    </div>
                ) : (
                    <div className="space-y-2">
                        <div className="flex items-center gap-2">
                            <CreditCardBrand
                                brand={selectedPaymentMethod?.card?.brand}
                                className="size-7"
                            />
                            <div>
                                <Text className="font-medium">
                                    **** {selectedPaymentMethod?.card?.last4}
                                </Text>
                                <Text className="text-sm text-gray-600 dark:text-gray-400">
                                    {selectedPaymentMethod?.billing_details?.name}
                                </Text>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <Text className="text-xs">
                                Exp: {selectedPaymentMethod?.card?.exp_month}/{selectedPaymentMethod?.card?.exp_year_short || selectedPaymentMethod?.card?.exp_year}
                            </Text>
                            {selectedPaymentMethod?.alias && (
                                <Badge color="blue" size="sm">
                                    {selectedPaymentMethod.alias}
                                </Badge>
                            )}
                            {selectedPaymentMethod?.metadata?.environment === 'sandbox' && (
                                <Badge color="yellow" size="sm">
                                    Sandbox
                                </Badge>
                            )}
                        </div>
                    </div>
                )
            }
            formContent={
                <>
                    {paymentUsesMock && (
                        <div className="mb-3 rounded-lg border border-amber-200/80 bg-amber-50/60 px-3 py-2 text-xs text-amber-900 dark:border-amber-800/50 dark:bg-amber-950/25 dark:text-amber-100">
                            Tarjetas de prueba precargadas (Visa/Mastercard aprueban; terminación 0002 rechaza).
                        </div>
                    )}
                    <PaymentMethodSelection
                        variant="accordion"
                        forceMobile={forceMobile}
                        addCardReturnUrl={addCardReturnUrl}
                        setData={setData}
                        paymentMethods={paymentMethods}
                        hasOdessaPay={hasOdessaPay}
                        hasPayPal={hasPayPal}
                        clearErrors={clearErrors}
                        paymentUsesMock={paymentUsesMock}
                    />
                </>
            }
            onClickEdit={() => setData("payment_method", null)}
        />
    );
}

function PaymentMethodSelection(props) {
    if (props.variant === "wizard") {
        return <PaymentMethodSelectionInner close={() => {}} {...props} />;
    }
    return <PaymentMethodSelectionAccordion {...props} />;
}

function PaymentMethodSelectionAccordion(props) {
    const close = useClose();
    return <PaymentMethodSelectionInner close={close} {...props} />;
}

function PaymentMethodSelectionInner({
    setData,
    addCardReturnUrl,
    paymentMethods,
    hasOdessaPay,
    hasPayPal = false,
    clearErrors,
    forceMobile = false,
    selectedId,
    showRadio = false,
    onSelected,
    close,
}) {

    const selectPaymentMethod = (paymentMethod) => {
        setData("payment_method", String(paymentMethod.id));
        clearErrors("payment_method");
        close();
        onSelected?.();
    };

    const addCardUrl = useMemo(() => {
        return route("payment-methods.create", {
            return_url: addCardReturnUrl,
        });
    }, [addCardReturnUrl]);

    return (
        <ul
            className={clsx(
                "mt-3 grid gap-3",
                showRadio || forceMobile ? "grid-cols-1" : "sm:grid-cols-2",
            )}
        >
            {hasPayPal && (
                <CheckoutSelectionCard
                    onClick={() => selectPaymentMethod({ id: "paypal" })}
                    selected={selectedId === "paypal"}
                    showRadio={showRadio}
                    className={clsx(
                        showRadio ? "min-h-0" : "relative min-h-[11rem] overflow-hidden",
                        "border-[#003087]/20 bg-gradient-to-br from-[#003087]/8 via-sky-50 to-[#009cde]/10",
                        "ring-1 ring-[#003087]/25",
                        "dark:border-[#009cde]/25 dark:from-[#003087]/25 dark:via-slate-900 dark:to-[#009cde]/15 dark:ring-[#009cde]/30",
                    )}
                >
                    <div
                        className="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full bg-[#009cde]/15"
                        aria-hidden
                    />
                    <div className="relative flex h-full flex-col justify-between">
                        <div className="flex items-start justify-between gap-2">
                            <span className="inline-flex items-center rounded-lg bg-white px-3 py-2 shadow-sm ring-1 ring-[#003087]/15 dark:bg-slate-800 dark:ring-[#009cde]/25">
                                <PayPalWordmark className="h-7" />
                            </span>
                            <Badge color="blue" className="shrink-0 !bg-[#003087] !text-white dark:!bg-[#009cde] dark:!text-slate-900">
                                PayPal
                            </Badge>
                        </div>
                        <div className="mt-4 space-y-1">
                            <Text className="text-sm font-medium text-[#003087] dark:text-[#009cde]">
                                Paga con tu cuenta PayPal
                            </Text>
                            <Text className="text-xs text-slate-600 dark:text-slate-400">
                                Checkout seguro · Sin compartir datos de tarjeta
                            </Text>
                        </div>
                    </div>
                </CheckoutSelectionCard>
            )}

            {hasOdessaPay && (
                <CheckoutSelectionCard
                    onClick={() => selectPaymentMethod({ id: "odessa" })}
                    selected={selectedId === "odessa"}
                    showRadio={showRadio}
                    className={clsx(
                        showRadio ? "min-h-0" : "relative min-h-[11rem] overflow-hidden",
                        "border-orange-200/80 bg-gradient-to-br from-orange-50 via-amber-50/90 to-orange-100/50",
                        "ring-1 ring-orange-200/70",
                        "dark:border-orange-800/50 dark:from-orange-950/40 dark:via-slate-900 dark:to-amber-950/30 dark:ring-orange-800/40",
                    )}
                >
                    <div
                        className="pointer-events-none absolute -right-4 -top-4 h-20 w-20 rounded-bl-full bg-orange-400/20"
                        aria-hidden
                    />
                    <div className="relative flex h-full flex-col justify-between">
                        <div className="flex items-start justify-between gap-2">
                            <span className="inline-flex items-center gap-2 rounded-lg bg-white p-2 shadow-sm ring-1 ring-orange-200/80 dark:bg-slate-800 dark:ring-orange-800/50">
                                <img
                                    src="/images/odessa.png"
                                    alt="Odessa"
                                    className="h-8 w-8"
                                />
                            </span>
                            <Badge color="orange" className="shrink-0">
                                Caja de ahorro
                            </Badge>
                        </div>
                        <div className="mt-4 space-y-1">
                            <Text className="text-sm font-medium text-orange-900 dark:text-orange-100">
                                Cobro a caja de ahorro Odessa
                            </Text>
                            <Text className="text-xs text-orange-700/90 dark:text-orange-300/90">
                                Saldo disponible en tiempo real
                            </Text>
                        </div>
                    </div>
                </CheckoutSelectionCard>
            )}

            {paymentMethods.map((paymentMethod) => {
                const isMock = paymentMethod.metadata?.mock === true;
                const isSandbox =
                    paymentMethod.metadata?.environment === "sandbox" || isMock;

                return (
                    <CheckoutSelectionCard
                        onClick={() => selectPaymentMethod(paymentMethod)}
                        key={paymentMethod.id}
                        selected={String(selectedId) === String(paymentMethod.id)}
                        showRadio={showRadio}
                        className={clsx(
                            showRadio ? "min-h-0" : "min-h-[11rem]",
                            isMock &&
                                "ring-2 ring-amber-300/80 dark:ring-amber-600/50",
                        )}
                    >
                        <div className="flex h-full flex-col justify-between">
                            <div className="flex justify-between items-start">
                                <div className="flex items-center gap-2">
                                    <CreditCardBrand
                                        brand={paymentMethod.card?.brand}
                                        className="size-7"
                                    />
                                    {isMock && (
                                        <Badge color="amber" size="xs">
                                            Prueba
                                        </Badge>
                                    )}
                                    {!isMock && isSandbox && (
                                        <Badge color="yellow" size="xs">
                                            Test
                                        </Badge>
                                    )}
                                </div>
                                {paymentMethod.alias && (
                                    <Badge color="blue" size="sm">
                                        {paymentMethod.alias}
                                    </Badge>
                                )}
                            </div>
                            <div className="mt-3 space-y-1">
                                <Text className="font-medium">
                                    **** **** **** {paymentMethod.card?.last4}
                                </Text>
                                <Text className="text-sm text-gray-600 dark:text-gray-400 truncate">
                                    {paymentMethod.billing_details?.name}
                                </Text>
                                <Text className="text-xs">
                                    Exp: {paymentMethod.card?.exp_month}/{paymentMethod.card?.exp_year_short || paymentMethod.card?.exp_year}
                                </Text>
                                {(isMock || isSandbox) && (
                                    <Text className="text-xs text-gray-500 mt-1">
                                        {paymentMethod.metadata?.description ??
                                            "Tarjeta de prueba — sin cargo real"}
                                    </Text>
                                )}
                            </div>
                        </div>
                    </CheckoutSelectionCard>
                );
            })}

            <CheckoutSelectionCard
                href={addCardUrl}
                heading="Nueva tarjeta"
                IconComponent={showRadio ? null : PlusIcon}
                greenIcon={!showRadio}
                showRadio={showRadio}
                className={clsx(showRadio ? "min-h-0" : "min-h-[11rem]")}
            >
                <div className="space-y-2">
                    <Text className="line-clamp-2">
                        Agrega una nueva tarjeta de crédito o débito
                    </Text>
                    <Text className="text-xs text-gray-600 dark:text-gray-400">
                        Tu información está protegida con cifrado de seguridad
                    </Text>
                </div>
            </CheckoutSelectionCard>
        </ul>
    );
}
