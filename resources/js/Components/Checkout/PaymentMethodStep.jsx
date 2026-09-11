// PaymentMethodStep.jsx - Versión actualizada
import { useMemo } from "react";
import clsx from "clsx";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { useClose } from "@headlessui/react";
import { PlusIcon, CreditCardIcon } from "@heroicons/react/24/solid";
import CheckoutStep from "@/Components/Checkout/CheckoutStep";
import CheckoutWizardStep from "@/Components/Checkout/CheckoutWizardStep";
import CheckoutSelectionCard from "@/Components/Checkout/CheckoutSelectionCard";
import CreditCardBrand from "@/Components/CreditCardBrand";
import { getPayPalSelectedOptionLabels } from "@/lib/paypal/paypalSelectorCopy";

const PAYPAL_LOGO_LIGHT =
	"https://cdn.simpleicons.org/paypal/003087";
const PAYPAL_LOGO_DARK =
	"https://cdn.simpleicons.org/paypal/FFFFFF";

function PayPalWordmark({ className = "h-7", decorative = false }) {
	return (
		<>
			<img
				src={PAYPAL_LOGO_LIGHT}
				alt={decorative ? "" : "PayPal"}
				aria-hidden={decorative ? true : undefined}
				className={clsx(className, "w-auto max-w-[5.5rem] object-contain dark:hidden")}
			/>
			<img
				src={PAYPAL_LOGO_DARK}
				alt={decorative ? "" : "PayPal"}
				aria-hidden={decorative ? true : undefined}
				className={clsx(
					className,
					"hidden w-auto max-w-[5.5rem] object-contain dark:block",
				)}
			/>
		</>
	);
}

function PayPalFundingInfoTile({ icon, title, badge, description }) {
    return (
        <div
            role="note"
            aria-label={`Información: ${title}`}
            className="flex h-full flex-col rounded-lg border border-slate-200/90 bg-slate-50/90 p-3 dark:border-slate-700/70 dark:bg-slate-800/45"
        >
            <div className="flex items-start gap-2.5">
                <span
                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-white ring-1 ring-slate-200/90 dark:bg-slate-900 dark:ring-slate-700/80"
                    aria-hidden="true"
                >
                    {icon}
                </span>
                <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <Text className="text-sm font-medium text-slate-800 dark:text-slate-100">
                            {title}
                        </Text>
                        {badge && (
                            <Badge
                                color="zinc"
                                className="!bg-slate-200/90 !text-slate-700 dark:!bg-slate-700 dark:!text-slate-200"
                            >
                                {badge}
                            </Badge>
                        )}
                    </div>
                    <Text className="text-xs text-slate-600 dark:text-slate-400">
                        {description}
                    </Text>
                </div>
            </div>
        </div>
    );
}

function PayPalSelectionCard({
    selected,
    showRadio,
    isCompact,
    paypalFundingEligibility,
    onSelect,
}) {
    const loading = paypalFundingEligibility?.loading ?? true;
    const ready = paypalFundingEligibility?.ready ?? false;
    const error = paypalFundingEligibility?.error;
    const retry = paypalFundingEligibility?.retry;
    const cardEligible = paypalFundingEligibility?.cardEligible ?? false;
    const paypalEligible = paypalFundingEligibility?.paypalEligible ?? false;
    const showInfoTiles =
        ready && !loading && !error && (cardEligible || paypalEligible);
    const bothEligible = cardEligible && paypalEligible;

    return (
        <CheckoutSelectionCard
            onClick={onSelect}
            selected={selected}
            showRadio={showRadio}
            compact={isCompact}
            className={clsx(
                isCompact ? "min-h-0" : "min-h-[11rem]",
                "border-slate-200/90 bg-slate-50/40 ring-1 ring-slate-200/80",
                "dark:border-slate-700/70 dark:bg-slate-900/40 dark:ring-slate-700/60",
            )}
        >
            <div className="space-y-3">
                <div className="space-y-1.5">
                    <div className="flex items-center gap-2.5">
                        <span className="inline-flex shrink-0 items-center rounded-md bg-white px-2 py-1 ring-1 ring-slate-200/90 dark:bg-slate-900 dark:ring-slate-700/80">
                            <PayPalWordmark className="h-6" />
                        </span>
                        <Text className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Paga con PayPal
                        </Text>
                    </div>
                    <Text className="text-xs text-slate-600 dark:text-slate-400">
                        {loading
                            ? "Consultando opciones disponibles…"
                            : "Elige cómo pagar en el siguiente paso."}
                    </Text>
                </div>

                {!loading && error && (
                    <div
                        role="note"
                        className="space-y-2 rounded-lg border border-amber-200/90 bg-amber-50/80 p-3 dark:border-amber-800/50 dark:bg-amber-950/25"
                    >
                        <Text className="text-xs text-amber-900 dark:text-amber-100">
                            No pudimos consultar las opciones de PayPal.
                        </Text>
                        <Button
                            type="button"
                            outline
                            className="text-xs"
                            onClick={(event) => {
                                event.stopPropagation();
                                retry?.();
                            }}
                        >
                            Reintentar
                        </Button>
                    </div>
                )}

                {showInfoTiles && (
                    <div
                        className={clsx(
                            "grid gap-2",
                            bothEligible && !isCompact
                                ? "sm:grid-cols-2"
                                : "grid-cols-1",
                        )}
                        aria-label="Formas de pago disponibles con PayPal"
                    >
                        {cardEligible && (
                            <PayPalFundingInfoTile
                                icon={
                                    <CreditCardIcon className="size-5 fill-slate-500 dark:fill-slate-400" />
                                }
                                title="Tarjeta de crédito o débito"
                                badge="Sin cuenta PayPal"
                                description="Procesada por PayPal."
                            />
                        )}
                        {paypalEligible && (
                            <PayPalFundingInfoTile
                                icon={
                                    <PayPalWordmark
                                        className="h-4"
                                        decorative
                                    />
                                }
                                title="Cuenta PayPal"
                                description="Inicia sesión y utiliza tus métodos guardados."
                            />
                        )}
                    </div>
                )}
            </div>
        </CheckoutSelectionCard>
    );
}

export default function PaymentMethodStep({
    data,
    setData,
    errors,
    clearErrors,
    description = "Selecciona el método de pago que deseas utilizar para tu pedido.",
    paymentMethods = [],
    hasOdessaPay,
    hasPayPal = false,
    paypalFundingEligibility = null,
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
                    paypalFundingEligibility={paypalFundingEligibility}
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
                    <PayPalSelectedSummary
                        paypalFundingEligibility={paypalFundingEligibility}
                    />
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
                        paypalFundingEligibility={paypalFundingEligibility}
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

function PayPalSelectedSummary({ paypalFundingEligibility }) {
    const loading = paypalFundingEligibility?.loading ?? true;
    const cardEligible = paypalFundingEligibility?.cardEligible ?? false;
    const paypalEligible = paypalFundingEligibility?.paypalEligible ?? false;
    const optionLabels = getPayPalSelectedOptionLabels({
        loading,
        cardEligible,
        paypalEligible,
    });

    return (
        <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2.5">
                <span className="inline-flex items-center rounded-md bg-white px-2 py-1 ring-1 ring-slate-200/90 dark:bg-slate-900 dark:ring-slate-700/80">
                    <PayPalWordmark className="h-5" />
                </span>
                <Text className="font-medium text-slate-900 dark:text-slate-100">
                    Paga con PayPal
                </Text>
            </div>
            <Text className="text-sm text-slate-600 dark:text-slate-400">
                {loading
                    ? "Consultando opciones disponibles…"
                    : "Elige cómo pagar en el siguiente paso."}
            </Text>
            {optionLabels.length > 0 && (
                <Text className="text-xs text-slate-500 dark:text-slate-500">
                    {optionLabels.join(" · ")}
                </Text>
            )}
        </div>
    );
}

function PaymentMethodSelectionInner({
    setData,
    addCardReturnUrl,
    paymentMethods,
    hasOdessaPay,
    hasPayPal = false,
    paypalFundingEligibility = null,
    clearErrors,
    forceMobile = false,
    selectedId,
    showRadio = false,
    onSelected,
    close,
}) {

    const isCompact = showRadio || forceMobile;

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
                <PayPalSelectionCard
                    selected={selectedId === "paypal"}
                    showRadio={showRadio}
                    isCompact={isCompact}
                    paypalFundingEligibility={paypalFundingEligibility}
                    onSelect={() => selectPaymentMethod({ id: "paypal" })}
                />
            )}

            {hasOdessaPay && (
                <CheckoutSelectionCard
                    onClick={() => selectPaymentMethod({ id: "odessa" })}
                    selected={selectedId === "odessa"}
                    showRadio={showRadio}
                    compact={isCompact}
                    className={clsx(
                        isCompact ? "min-h-0" : "relative min-h-[11rem] overflow-hidden",
                        "border-orange-200/80 bg-gradient-to-br from-orange-50 via-amber-50/90 to-orange-100/50",
                        "ring-1 ring-orange-200/70",
                        "dark:border-orange-800/50 dark:from-orange-950/40 dark:via-slate-900 dark:to-amber-950/30 dark:ring-orange-800/40",
                    )}
                >
                    <div
                        className="pointer-events-none absolute -right-4 -top-4 h-20 w-20 rounded-bl-full bg-orange-400/20"
                        aria-hidden
                    />
                    <div className={clsx("relative flex flex-col", isCompact ? "gap-2" : "h-full justify-between")}>
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
                        <div className={clsx("space-y-1", !isCompact && "mt-4")}>
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
                        compact={isCompact}
                        className={clsx(
                            isCompact ? "min-h-0" : "min-h-[11rem]",
                            isMock &&
                                "ring-2 ring-amber-300/80 dark:ring-amber-600/50",
                        )}
                    >
                        <div className={clsx("flex flex-col", isCompact ? "gap-2" : "h-full justify-between")}>
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
                            <div className={clsx("space-y-1", !isCompact && "mt-3")}>
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
                compact={isCompact}
                className={clsx(isCompact ? "min-h-0" : "min-h-[11rem]")}
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
