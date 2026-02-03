// PaymentMethodStep.jsx - Versión actualizada
import { useMemo } from "react";
import { Text, Code } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { useClose } from "@headlessui/react";
import { PlusIcon, CreditCardIcon } from "@heroicons/react/24/solid";
import CheckoutStep from "@/Components/Checkout/CheckoutStep";
import CheckoutSelectionCard from "@/Components/Checkout/CheckoutSelectionCard";
import CreditCardBrand from "@/Components/CreditCardBrand";

export default function PaymentMethodStep({
    data,
    setData,
    errors,
    clearErrors,
    description = "Selecciona el método de pago que deseas utilizar para tu pedido.",
    paymentMethods,
    hasOdessaPay,
    addCardReturnUrl,
    forceMobile = false,
    ...props
}) {
    const selectedPaymentMethod = useMemo(() => {
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

    return (
        <CheckoutStep
            {...props}
            IconComponent={CreditCardIcon}
            heading={stepHeading}
            description={description}
            selectedContent={
                selectedPaymentMethod === "odessa" ? (
                    <div>
                        <div className="flex gap-1 items-center">
                            <img
                                src="/images/odessa.png"
                                alt="odessa"
                                className="h-6 w-6"
                            />
                            <Text className="font-medium">odessa</Text>
                        </div>
                        <div>
                            <Text>
                                <Code>
                                    <span className="text-orange-600 dark:text-orange-400">
                                        Cobro a caja de ahorro
                                    </span>
                                </Code>
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
                <PaymentMethodSelection
                    forceMobile={forceMobile}
                    addCardReturnUrl={addCardReturnUrl}
                    setData={setData}
                    paymentMethods={paymentMethods}
                    hasOdessaPay={hasOdessaPay}
                    clearErrors={clearErrors}
                />
            }
            onClickEdit={() => setData("payment_method", null)}
        />
    );
}

function PaymentMethodSelection({
    setData,
    addCardReturnUrl,
    paymentMethods,
    hasOdessaPay,
    clearErrors,
    forceMobile = false,
}) {
    const close = useClose();

    const selectPaymentMethod = (paymentMethod) => {
        console.log('DEBUG - Seleccionando método de pago:', {
            id: paymentMethod.id,
            type: typeof paymentMethod.id,
            will_be_set_as: String(paymentMethod.id),
        });
        
        // Siempre guardar como string
        setData("payment_method", String(paymentMethod.id));
        clearErrors("payment_method");
        close();
    };

    const addCardUrl = useMemo(() => {
        return route("payment-methods.create", {
            return_url: addCardReturnUrl,
        });
    }, [addCardReturnUrl]);

    return (
        <ul
            className={`mt-3 grid gap-4 ${!forceMobile ? "sm:grid-cols-2" : ""}`}
        >
            {hasOdessaPay && (
                <CheckoutSelectionCard
                    onClick={() => selectPaymentMethod({ id: "odessa" })}
                    className="min-h-[11rem]"
                >
                    <div className="flex h-full flex-col justify-between">
                        <div className="flex justify-between items-center">
                            <img
                                src="/images/odessa.png"
                                alt="odessa"
                                className="h-7 w-7"
                            />
                            <Text className="font-medium">odessa</Text>
                        </div>
                        <div className="mt-4">
                            <Text className="text-sm text-gray-600 dark:text-gray-400">
                                Cobro directo a tu caja de ahorro
                            </Text>
                            <Text className="mt-2 text-xs text-orange-600 dark:text-orange-400">
                                Saldo disponible en tiempo real
                            </Text>
                        </div>
                    </div>
                </CheckoutSelectionCard>
            )}
            
            {paymentMethods.map((paymentMethod) => {
                const isSandbox = paymentMethod.metadata?.environment === 'sandbox';
                
                return (
                    <CheckoutSelectionCard
                        onClick={() => selectPaymentMethod(paymentMethod)}
                        key={paymentMethod.id}
                        className="min-h-[11rem]"
                    >
                        <div className="flex h-full flex-col justify-between">
                            <div className="flex justify-between items-start">
                                <div className="flex items-center gap-2">
                                    <CreditCardBrand 
                                        brand={paymentMethod.card?.brand}
                                        className="size-7"
                                    />
                                    {isSandbox && (
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
                                {isSandbox && (
                                    <Text className="text-xs text-gray-500 mt-1">
                                        Tarjeta de prueba - No se realizarán cargos reales
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
                IconComponent={PlusIcon}
                greenIcon
                className="min-h-[11rem]"
            >
                <div className="space-y-2">
                    <Text className="line-clamp-2">
                        Agrega una nueva tarjeta de crédito o débito
                    </Text>
                    <Text className="text-xs text-gray-600 dark:text-gray-400">
                        Tu información está protegida con cifrado de seguridad
                    </Text>
                    <Text className="text-xs text-blue-600 dark:text-blue-400 mt-2">
                        * Se realizará un pequeño cargo de verificación que será reembolsado
                    </Text>
                </div>
            </CheckoutSelectionCard>
        </ul>
    );
}