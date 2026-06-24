// LaboratoryCheckout.jsx - Versión actualizada y corregida
import { useDeleteLaboratoryCartItem } from "@/Hooks/useDeleteLaboratoryCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { Badge } from "@/Components/Catalyst/badge";
import { PhoneIcon } from "@heroicons/react/16/solid";
import { CheckCircleIcon } from "@heroicons/react/24/solid";
import {
    ErrorMessage,
    Description,
    Label,
} from "@/Components/Catalyst/fieldset";
import { Subheading } from "@/Components/Catalyst/heading";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import CheckoutLayout from "@/Layouts/CheckoutLayout";
import { useForm, usePage } from "@inertiajs/react";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { useState, useMemo, useEffect, useRef, useCallback } from "react";
import ContactStep from "@/Components/Checkout/ContactStep";
import AddressStep from "@/Components/Checkout/AddressStep";
import PaymentMethodStep from "@/Components/Checkout/PaymentMethodStep";
import CheckoutStepper from "@/Components/Checkout/CheckoutStepper";
import ConfirmationStep from "@/Components/Checkout/ConfirmationStep";
import CheckoutWizardStep from "@/Components/Checkout/CheckoutWizardStep";
import LaboratoryAppointmentStep from "@/Components/Checkout/LaboratoryAppointmentStep";
import LaboratoryPayPalButton from "@/Components/Checkout/LaboratoryPayPalButton";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { router } from "@inertiajs/react";
import EnvironmentBadge from "@/Components/EnvironmentBadge";
import { ChevronLeftIcon } from "@heroicons/react/16/solid";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import clsx from "clsx";

const BASE_WIZARD_STEPS = [
    { id: "patient", number: 1, label: "Paciente" },
    { id: "address", number: 2, label: "Dirección" },
    { id: "payment", number: 3, label: "Método de Pago" },
];

function buildWizardSteps(requiresAppointment) {
    if (requiresAppointment) {
        return [
            ...BASE_WIZARD_STEPS,
            { id: "appointment", number: 4, label: "Cita" },
            { id: "confirmation", number: 5, label: "Confirmación" },
        ];
    }

    return [
        ...BASE_WIZARD_STEPS,
        { id: "confirmation", number: 4, label: "Confirmación" },
    ];
}

function checkoutStorageKey(brand) {
    return `laboratory-checkout-wizard:${brand}`;
}

function resolveActiveStepId(
    requiresAppointment,
    laboratoryAppointment,
    savedCheckout = null,
) {
    if (requiresAppointment && laboratoryAppointment?.confirmed_at) {
        return "confirmation";
    }

    const params = new URLSearchParams(window.location.search);
    let stepId = params.get("step");

    if (!stepId && savedCheckout?.checkout_step) {
        stepId = savedCheckout.checkout_step;
    }

    if (
        stepId === "confirmation" &&
        requiresAppointment &&
        !laboratoryAppointment?.confirmed_at
    ) {
        stepId = "appointment";
    }

    return stepId;
}

function resolveInitialStepIndex(
    wizardSteps,
    requiresAppointment,
    laboratoryAppointment,
    brand,
    savedCheckout = null,
) {
    const stepId = resolveActiveStepId(
        requiresAppointment,
        laboratoryAppointment,
        savedCheckout,
    );

    if (stepId) {
        const fromLocation = wizardSteps.findIndex((s) => s.id === stepId);
        if (fromLocation >= 0) {
            return fromLocation;
        }
    }

    try {
        const saved = sessionStorage.getItem(checkoutStorageKey(brand));
        if (saved) {
            const { stepId: savedStepId } = JSON.parse(saved);
            const fromStorage = wizardSteps.findIndex((s) => s.id === savedStepId);
            if (fromStorage >= 0) {
                return fromStorage;
            }
        }
    } catch {
        // ignore invalid session storage
    }

    return 0;
}

function resolveInitialCheckoutData(savedCheckout = null) {
    const params = new URLSearchParams(window.location.search);

    const fromParams = {
        contact: params.get("contact") || null,
        address: params.get("address") || null,
        payment_method: params.get("payment_method") || null,
        coupon_id: params.get("coupon_id")
            ? Number(params.get("coupon_id"))
            : null,
    };

    if (!savedCheckout) {
        return {
            ...fromParams,
            coupon_id: fromParams.coupon_id || null,
        };
    }

    return {
        contact:
            fromParams.contact ??
            savedCheckout.contact_id ??
            null,
        address:
            fromParams.address ??
            savedCheckout.address_id ??
            null,
        payment_method:
            fromParams.payment_method ??
            savedCheckout.payment_method ??
            null,
        coupon_id:
            fromParams.coupon_id ??
            savedCheckout.coupon_id ??
            null,
    };
}

export default function LaboratoryCheckout({
    requiresAppointment = false,
    laboratoryAppointment,
    pendingLaboratoryAppointment: pendingLaboratoryAppointmentProp = null,
    callbackPreferenceSavedAtFormatted,
    savedCheckout = null,
    laboratoryCarts,
    laboratoryBrand,
    total,
    formattedTotal,
    formattedSubtotal,
    formattedDiscount,
    balanceCouponsCents = 0,
    formattedBalanceCoupons,
    availableBalanceCoupons = [],
    addresses,
    paymentMethods,
    hasOdessaPay,
    hasPayPal,
    paypalClientId,
    contacts,
    paymentUsesMock = false,
    defaultMockPaymentMethodId = null,
}) {
    const {
        laboratoryCartItemToDelete,
        setLaboratoryCartItemToDelete,
        destroyLaboratoryCartItem,
        processing,
    } = useDeleteLaboratoryCartItem();

    const { url } = usePage();

    const initialFormData = resolveInitialCheckoutData(savedCheckout);

    const {
        data,
        transform,
        setData,
        errors,
        clearErrors,
        post,
        processing: checkoutProcessing,
        setError,
    } = useForm(initialFormData);

    useEffect(() => {
        if (
            paymentUsesMock &&
            defaultMockPaymentMethodId &&
            !data.payment_method
        ) {
            setData("payment_method", String(defaultMockPaymentMethodId));
        }
    }, [paymentUsesMock, defaultMockPaymentMethodId, data.payment_method, setData]);

    transform((data) => ({
        ...data,
        payment_method: String(data.payment_method),
        laboratory_appointment: laboratoryAppointment?.id,
        total: total,
        coupon_id: data.coupon_id || null,
    }));

    const submit = (e, isBranchPayment) => {
        e.preventDefault();

        if (checkoutProcessing) return;

        // Validaciones adicionales
        if (!data.address) {
            setError('address', 'Debes seleccionar una dirección');
            return;
        }

        if (!data.payment_method && !isBranchPayment) {
            setError('payment_method', 'Debes seleccionar un método de pago');
            return;
        }

        if (isBranchPayment) {
            // PAGO EN SUCURSAL
            const items = laboratoryCarts[laboratoryBrand.value].map(item => ({
                test_id: item.laboratory_test.id,
                name: item.laboratory_test.name,
                price: item.laboratory_test.famedic_price_cents,
                quantity: 1,
            }));

            router.post(
                route("api.laboratory.quote.store", laboratoryBrand.value),
                {
                    cart_items: items,
                    appointment_id: laboratoryAppointment?.id,
                    contact_id: data.contact,
                    address_id: data.address,
                },
                {
                    onSuccess: (page) => {
                        const redirectUrl = page.props.redirect_url;
                        if (redirectUrl) {
                            router.visit(redirectUrl);
                        }
                    },
                    onError: (errors) => {
                        console.error("Error GDA:", errors);
                        alert("Error al generar cotización. Intenta de nuevo.");
                    },
                }
            );
        } else {
            post(route("laboratory.checkout.store", {
                laboratory_brand: laboratoryBrand.value
            }), {
                onError: (errors) => {
                    console.error('Errores del backend:', errors);

                    // Manejar errores específicos
                    if (errors.payment_method) {
                        alert(`Error en método de pago: ${errors.payment_method}`);
                    } else if (errors.message) {
                        alert(errors.message);
                    } else {
                        alert('Error al procesar el pago. Por favor intenta de nuevo.');
                    }
                },
            });
        }
    };

    const [showAddressForm, setShowAddressForm] = useState(
        () => addresses.length < 1,
    );
    const [showContactForm, setShowContactForm] = useState(
        () => contacts.length < 1,
    );

    const toggleAddressForm = () => setShowAddressForm((prev) => !prev);
    const toggleContactForm = () =>
    setShowContactForm((prev) => !prev);

    const cartRequiresAppointment = useMemo(() => {
        const items = laboratoryCarts?.[laboratoryBrand.value] ?? [];
        return items.some((item) => item.laboratory_test?.requires_appointment);
    }, [laboratoryCarts, laboratoryBrand.value]);

    const needsAppointment = requiresAppointment || cartRequiresAppointment;

    const wizardSteps = useMemo(
        () => buildWizardSteps(needsAppointment),
        [needsAppointment],
    );

    const [pendingLaboratoryAppointment, setPendingLaboratoryAppointment] =
        useState(pendingLaboratoryAppointmentProp);

    useEffect(() => {
        setPendingLaboratoryAppointment(pendingLaboratoryAppointmentProp);
    }, [pendingLaboratoryAppointmentProp]);

    const wizardLaboratoryAppointment =
        pendingLaboratoryAppointment ?? laboratoryAppointment;

    const contactStepIsComplete = useMemo(() => !!data.contact, [data.contact]);

    const addressStepIsComplete = useMemo(() => !!data.address, [data.address]);

    const selectedCoupon = useMemo(() => {
        if (!data.coupon_id) return null;
        return availableBalanceCoupons.find((c) => c.id === data.coupon_id) ?? null;
    }, [data.coupon_id, availableBalanceCoupons]);

    const couponTooLarge = useMemo(() => {
        if (!selectedCoupon) return false;
        return selectedCoupon.remaining_cents > total;
    }, [selectedCoupon, total]);

    const amountAfterCoupon = useMemo(() => {
        if (!selectedCoupon || couponTooLarge) return total;
        return Math.max(0, total - selectedCoupon.remaining_cents);
    }, [selectedCoupon, couponTooLarge, total]);

    const summaryDetails = useMemo(() => {
        const rows = [
            { value: formattedSubtotal, label: "Subtotal" },
            { value: "-" + formattedDiscount, label: "Descuento" },
        ];
        if (selectedCoupon && !couponTooLarge) {
            rows.push({
                value:
                    "-" +
                    (selectedCoupon.remaining_cents / 100).toLocaleString(
                        "es-MX",
                        { style: "currency", currency: "MXN" },
                    ),
                label: "Cupón saldo",
            });
        }
        rows.push({
            value:
                !selectedCoupon || couponTooLarge
                    ? formattedTotal
                    : (amountAfterCoupon / 100).toLocaleString("es-MX", {
                          style: "currency",
                          currency: "MXN",
                      }),
            label: "Total a pagar",
        });
        return rows;
    }, [
        formattedSubtotal,
        formattedDiscount,
        formattedTotal,
        selectedCoupon,
        couponTooLarge,
        amountAfterCoupon,
    ]);

    const paymentMethodStepIsComplete = useMemo(() => {
        if (!data.coupon_id) {
            return !!data.payment_method;
        }
        if (!selectedCoupon) return false;
        if (couponTooLarge) return false;
        if (amountAfterCoupon === 0) {
            return data.payment_method === "coupon_balance";
        }
        return !!data.payment_method;
    }, [
        data.coupon_id,
        data.payment_method,
        selectedCoupon,
        couponTooLarge,
        amountAfterCoupon,
    ]);

    const applyBalanceCoupon = () => {
        const applicable = availableBalanceCoupons.find(
            (c) => c.remaining_cents <= total,
        );
        if (!applicable) return;
        setData("coupon_id", applicable.id);
        const after = total - applicable.remaining_cents;
        if (after === 0) {
            setData("payment_method", "coupon_balance");
        }
        clearErrors("payment_method");
    };

    const clearBalanceCoupon = () => {
        setData("coupon_id", null);
        if (data.payment_method === "coupon_balance") {
            setData("payment_method", null);
        }
    };

    const noCouponApplicable =
        balanceCouponsCents > 0 &&
        availableBalanceCoupons.length > 0 &&
        !availableBalanceCoupons.some((c) => c.remaining_cents <= total);

    // Condiciones para habilitar/deshabilitar botones
    const onlinePaymentDisabled = checkoutProcessing ||
        !addressStepIsComplete ||
        !contactStepIsComplete ||
        !paymentMethodStepIsComplete ||
        (needsAppointment && !laboratoryAppointment?.confirmed_at);

    const branchPaymentDisabled = checkoutProcessing ||
        !addressStepIsComplete ||
        !contactStepIsComplete ||
        (needsAppointment && !laboratoryAppointment?.confirmed_at);

    const [currentStepIndex, setCurrentStepIndex] = useState(() => {
        const cartNeeds = (laboratoryCarts?.[laboratoryBrand.value] ?? []).some(
            (item) => item.laboratory_test?.requires_appointment,
        );
        const needs = requiresAppointment || cartNeeds;

        return resolveInitialStepIndex(
            buildWizardSteps(needs),
            needs,
            laboratoryAppointment,
            laboratoryBrand.value,
            savedCheckout,
        );
    });
    const [syncingAppointment, setSyncingAppointment] = useState(false);
    const [syncingDraft, setSyncingDraft] = useState(false);
    const [appointmentSyncFailed, setAppointmentSyncFailed] = useState(false);
    const stepContentRef = useRef(null);
    const skipStepScrollRef = useRef(true);
    const appointmentAutoSyncRef = useRef(false);

    const currentStep = wizardSteps[currentStepIndex];

    const persistWizardState = (stepId) => {
        const filteredData = Object.fromEntries(
            Object.entries(data).filter(
                ([_, value]) =>
                    value !== undefined && value !== null && value !== "",
            ),
        );

        try {
            sessionStorage.setItem(
                checkoutStorageKey(laboratoryBrand.value),
                JSON.stringify({ stepId, ...filteredData }),
            );
        } catch {
            // ignore quota errors
        }

        const url = new URL(window.location.href);
        url.searchParams.set("step", stepId);
        Object.entries(filteredData).forEach(([key, value]) => {
            url.searchParams.set(key, String(value));
        });
        window.history.replaceState({}, "", url);
    };

    const syncStepFromLocation = useCallback(() => {
        const stepId = resolveActiveStepId(
            needsAppointment,
            laboratoryAppointment,
            savedCheckout,
        );

        if (!stepId) {
            return;
        }

        const index = wizardSteps.findIndex((s) => s.id === stepId);
        if (index >= 0) {
            setCurrentStepIndex(index);
        }
    }, [
        needsAppointment,
        laboratoryAppointment,
        savedCheckout,
        wizardSteps,
    ]);

    const persistWizardFormDataToUrl = useCallback(() => {
        const filteredData = Object.fromEntries(
            Object.entries(data).filter(
                ([_, value]) =>
                    value !== undefined && value !== null && value !== "",
            ),
        );

        try {
            sessionStorage.setItem(
                checkoutStorageKey(laboratoryBrand.value),
                JSON.stringify({ stepId: currentStep.id, ...filteredData }),
            );
        } catch {
            // ignore quota errors
        }

        const currentUrl = new URL(window.location.href);
        Object.entries(filteredData).forEach(([key, value]) => {
            currentUrl.searchParams.set(key, String(value));
        });
        window.history.replaceState({}, "", currentUrl);
    }, [data, currentStep.id, laboratoryBrand.value]);

    const addCardReturnUrl = useMemo(() => {
        const filteredData = Object.fromEntries(
            Object.entries(data).filter(
                ([_, value]) =>
                    value !== undefined && value !== null && value !== "",
            ),
        );

        return route("laboratory.checkout", {
            laboratory_brand: laboratoryBrand.value,
            step: currentStep?.id ?? "payment",
            ...filteredData,
        });
    }, [data, laboratoryBrand.value, currentStep?.id]);

    const floatingWizardFooter = ["patient", "address", "payment", "appointment"].includes(
        currentStep.id,
    );

    const goToStep = (stepId) => {
        const index = wizardSteps.findIndex((s) => s.id === stepId);
        if (index >= 0) {
            setCurrentStepIndex(index);
            persistWizardState(stepId);
        }
    };

    const canProceedFromStep = useMemo(() => {
        switch (currentStep.id) {
            case "patient":
                return contactStepIsComplete;
            case "address":
                return addressStepIsComplete;
            case "payment":
                return paymentMethodStepIsComplete;
            case "appointment":
                return !!wizardLaboratoryAppointment?.confirmed_at;
            case "confirmation":
                return (
                    contactStepIsComplete &&
                    addressStepIsComplete &&
                    paymentMethodStepIsComplete &&
                    (!needsAppointment || !!laboratoryAppointment?.confirmed_at)
                );
            default:
                return false;
        }
    }, [
        currentStep.id,
        contactStepIsComplete,
        addressStepIsComplete,
        paymentMethodStepIsComplete,
        needsAppointment,
        wizardLaboratoryAppointment?.confirmed_at,
    ]);

    const syncAppointmentFromContact = useCallback(
        (onFinish) => {
            if (!data.contact) {
                setError("contact", "Selecciona un paciente");
                onFinish?.();
                return;
            }

            setSyncingAppointment(true);
            setAppointmentSyncFailed(false);
            router.post(
                route("laboratory.checkout.appointment.sync", {
                    laboratory_brand: laboratoryBrand.value,
                }),
                {
                    contact_id: Number(data.contact),
                    contact: data.contact,
                    address: data.address,
                    payment_method: data.payment_method,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        appointmentAutoSyncRef.current = true;
                        setAppointmentSyncFailed(false);
                    },
                    onError: (syncErrors) => {
                        appointmentAutoSyncRef.current = false;
                        setAppointmentSyncFailed(true);
                        console.error("No se pudo registrar la cita:", syncErrors);
                    },
                    onFinish: () => {
                        setSyncingAppointment(false);
                        onFinish?.();
                    },
                },
            );
        },
        [
            data.contact,
            data.address,
            data.payment_method,
            laboratoryBrand.value,
            setError,
        ],
    );

    const syncCheckoutDraft = useCallback(() => {
            if (!["patient", "address", "payment"].includes(currentStep.id)) {
                return;
            }

            const payload = {
                step: currentStep.id,
                contact_id: data.contact ? Number(data.contact) : null,
            };

            if (currentStep.id === "address" || currentStep.id === "payment") {
                payload.address_id = data.address ? Number(data.address) : null;
            }

            if (currentStep.id === "payment") {
                payload.payment_method = data.payment_method;
                if (data.coupon_id) {
                    payload.coupon_id = Number(data.coupon_id);
                }
            }

            setSyncingDraft(true);
            router.post(
                route("laboratory.checkout.draft.sync", {
                    laboratory_brand: laboratoryBrand.value,
                }),
                payload,
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        syncStepFromLocation();
                    },
                    onError: (syncErrors) => {
                        console.error("No se pudo guardar el checkout:", syncErrors);
                        Object.entries(syncErrors).forEach(([field, message]) => {
                            const mappedField =
                                field === "contact_id"
                                    ? "contact"
                                    : field === "address_id"
                                      ? "address"
                                      : field;
                            setError(mappedField, message);
                        });
                    },
                    onFinish: () => setSyncingDraft(false),
                },
            );
        },
        [
            currentStep.id,
            data.contact,
            data.address,
            data.payment_method,
            data.coupon_id,
            laboratoryBrand.value,
            setError,
            syncStepFromLocation,
        ],
    );

    const handleNextStep = () => {
        if (!canProceedFromStep || syncingAppointment || syncingDraft) return;

        if (["patient", "address", "payment"].includes(currentStep.id)) {
            syncCheckoutDraft();
            return;
        }

        if (currentStepIndex < wizardSteps.length - 1) {
            const nextStep = wizardSteps[currentStepIndex + 1];
            setCurrentStepIndex((prev) => prev + 1);
            persistWizardState(nextStep.id);
        }
    };

    const handlePrevStep = () => {
        if (currentStepIndex > 0) {
            const prevStep = wizardSteps[currentStepIndex - 1];
            setCurrentStepIndex((prev) => prev - 1);
            persistWizardState(prevStep.id);
        }
    };

    useEffect(() => {
        if (currentStep.id !== "appointment") {
            appointmentAutoSyncRef.current = false;
            return;
        }

        if (!needsAppointment) {
            return;
        }

        if (
            !pendingLaboratoryAppointment &&
            !laboratoryAppointment &&
            data.contact &&
            !syncingAppointment &&
            !appointmentAutoSyncRef.current
        ) {
            appointmentAutoSyncRef.current = true;
            syncAppointmentFromContact();
        }

        const intervalId = setInterval(() => {
            router.reload({
                only: [
                    "laboratoryAppointment",
                    "pendingLaboratoryAppointment",
                    "callbackPreferenceSavedAtFormatted",
                ],
            });
        }, 10000);

        return () => clearInterval(intervalId);
    }, [
        currentStep.id,
        needsAppointment,
        pendingLaboratoryAppointment,
        laboratoryAppointment,
        data.contact,
        syncingAppointment,
        syncAppointmentFromContact,
    ]);

    useEffect(() => {
        if (
            needsAppointment &&
            wizardLaboratoryAppointment?.confirmed_at &&
            currentStep.id === "appointment"
        ) {
            goToStep("confirmation");
        }
    }, [
        wizardLaboratoryAppointment?.confirmed_at,
        needsAppointment,
        currentStep.id,
    ]);

    useEffect(() => {
        syncStepFromLocation();
    }, [url, savedCheckout?.checkout_step, syncStepFromLocation]);

    useEffect(() => {
        persistWizardFormDataToUrl();
    }, [
        data.contact,
        data.address,
        data.payment_method,
        data.coupon_id,
        persistWizardFormDataToUrl,
    ]);

    useEffect(() => {
        if (skipStepScrollRef.current) {
            skipStepScrollRef.current = false;
            return;
        }

        const el = stepContentRef.current;
        if (!el) return;

        const scrollToStep = () => {
            const prefersReducedMotion = window.matchMedia(
                "(prefers-reduced-motion: reduce)",
            ).matches;

            el.scrollIntoView({
                behavior: prefersReducedMotion ? "auto" : "smooth",
                block: "start",
            });
        };

        requestAnimationFrame(() => requestAnimationFrame(scrollToStep));
    }, [currentStepIndex]);

    const couponSection = balanceCouponsCents > 0 && (
        <div className="rounded-lg border border-emerald-200/80 bg-emerald-50/50 p-4 dark:border-emerald-800/40 dark:bg-emerald-950/20">
            <Text className="text-sm font-medium">Saldo a favor</Text>
            <Text className="mt-1 text-sm">
                Tienes <Strong>{formattedBalanceCoupons}</Strong> disponibles.
            </Text>
            {noCouponApplicable && (
                <Text className="mt-2 text-xs text-amber-800 dark:text-amber-200">
                    Tu saldo es mayor al total de la compra, no puede aplicarse
                    en esta compra.
                </Text>
            )}
            {errors.coupon_id && (
                <ErrorMessage className="mt-2">{errors.coupon_id}</ErrorMessage>
            )}
            <div className="mt-3">
                {!data.coupon_id ? (
                    <Button
                        type="button"
                        color="emerald"
                        className="w-full text-sm"
                        disabled={noCouponApplicable}
                        onClick={applyBalanceCoupon}
                    >
                        Usar saldo completo
                    </Button>
                ) : (
                    <Button
                        type="button"
                        plain
                        className="w-full text-sm"
                        onClick={clearBalanceCoupon}
                    >
                        Quitar cupón
                    </Button>
                )}
            </div>
        </div>
    );

    const footerActions = (
        <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            {currentStepIndex > 0 && currentStep.id !== "confirmation" ? (
                <Button type="button" plain onClick={handlePrevStep}>
                    <ChevronLeftIcon className="size-4" />
                    Volver
                </Button>
            ) : (
                <div />
            )}

            {currentStep.id !== "confirmation" ? (
                <Button
                    type="button"
                    className="w-full sm:ml-auto sm:w-auto"
                    disabled={
                        !canProceedFromStep || syncingAppointment || syncingDraft
                    }
                    onClick={handleNextStep}
                >
                    {syncingDraft
                        ? "Guardando…"
                        : syncingAppointment
                          ? "Guardando cita…"
                          : currentStep.id === "appointment" &&
                              !wizardLaboratoryAppointment?.confirmed_at
                            ? "Esperando confirmación…"
                            : "Continuar"}
                </Button>
            ) : (
                <div
                    className={clsx(
                        "w-full sm:ml-auto sm:max-w-md",
                        onlinePaymentDisabled &&
                            "pointer-events-none opacity-50",
                    )}
                >
                    {hasPayPal &&
                    paypalClientId &&
                    data.payment_method === "paypal" ? (
                        <LaboratoryPayPalButton
                            paypalClientId={paypalClientId}
                            laboratoryBrand={laboratoryBrand.value}
                            patientId={data.contact}
                            addressId={data.address}
                            totalCents={total}
                            couponId={data.coupon_id}
                            disabled={onlinePaymentDisabled}
                        />
                    ) : (
                        <Button
                            disabled={
                                onlinePaymentDisabled || checkoutProcessing
                            }
                            type="submit"
                            name="online_payment"
                            className="w-full !py-3"
                        >
                            Confirmar compra{" "}
                            {summaryDetails[summaryDetails.length - 1]?.value}
                            {checkoutProcessing && (
                                <ArrowPathIcon className="ml-2 size-5 animate-spin" />
                            )}
                        </Button>
                    )}
                </div>
            )}
        </div>
    );

    const confirmationLegalText =
        currentStep.id === "confirmation" ? (
            <Text className="mt-4 text-sm text-zinc-600 dark:text-slate-400">
                Al confirmar tu compra, aceptas los{" "}
                <a
                    href="/terminos-y-condiciones"
                    target="_blank"
                    className="underline"
                >
                    Términos y condiciones
                </a>{" "}
                y la{" "}
                <a
                    href="/politica-de-privacidad"
                    target="_blank"
                    className="underline"
                >
                    Política de privacidad
                </a>
                .
            </Text>
        ) : null;

    const footerWithLegal = (
        <>
            {footerActions}
            {confirmationLegalText}
        </>
    );

    const renderStepContent = () => {
        switch (currentStep.id) {
            case "patient":
                return (
                    <ContactStep
                        variant="wizard"
                        data={data}
                        setData={setData}
                        errors={errors}
                        error={errors.contact}
                        clearErrors={clearErrors}
                        contacts={contacts}
                        toggleContactForm={toggleContactForm}
                        showContactForm={showContactForm}
                    />
                );
            case "address":
                return (
                    <AddressStep
                        variant="wizard"
                        data={data}
                        setData={setData}
                        errors={errors}
                        error={errors.address}
                        clearErrors={clearErrors}
                        addresses={addresses}
                        toggleAddressForm={toggleAddressForm}
                        showAddressForm={showAddressForm}
                    />
                );
            case "payment":
                if (amountAfterCoupon === 0 && data.coupon_id) {
                    return (
                        <CheckoutWizardStep
                            title="Método de pago"
                            description="Tu saldo a favor cubre el total de la compra."
                        >
                            <div className="flex items-center gap-3 rounded-lg bg-emerald-50 p-4 dark:bg-emerald-950/30">
                                <CheckCircleIcon className="size-6 fill-green-600 dark:fill-famedic-lime" />
                                <div>
                                    <Text className="font-medium">
                                        Pago con saldo a favor
                                    </Text>
                                    <Text className="text-sm text-zinc-600 dark:text-slate-400">
                                        No necesitas seleccionar otro método de
                                        pago.
                                    </Text>
                                </div>
                            </div>
                        </CheckoutWizardStep>
                    );
                }
                return (
                    <PaymentMethodStep
                        variant="wizard"
                        data={data}
                        setData={setData}
                        errors={errors}
                        error={errors.payment_method}
                        clearErrors={clearErrors}
                        paymentMethods={paymentMethods}
                        hasOdessaPay={hasOdessaPay}
                        hasPayPal={hasPayPal}
                        addCardReturnUrl={addCardReturnUrl}
                        paymentUsesMock={paymentUsesMock}
                        disabled={amountAfterCoupon === 0 && !!data.coupon_id}
                    />
                );
            case "appointment":
                if (!pendingLaboratoryAppointment) {
                    const waitingForSync =
                        syncingAppointment ||
                        (!appointmentSyncFailed && !!data.contact);

                    return (
                        <CheckoutWizardStep
                            title="Cita de laboratorio"
                            description="Preparando tu solicitud de cita…"
                        >
                            <Text className="text-sm text-zinc-600 dark:text-slate-400">
                                {waitingForSync
                                    ? "Registrando tu solicitud de cita…"
                                    : "No pudimos cargar la cita. Usa Volver e intenta de nuevo desde el método de pago."}
                            </Text>
                        </CheckoutWizardStep>
                    );
                }
                return (
                    <LaboratoryAppointmentStep
                        laboratoryAppointment={pendingLaboratoryAppointment}
                        callbackPreferenceSavedAtFormatted={
                            callbackPreferenceSavedAtFormatted
                        }
                    />
                );
            case "confirmation":
                return (
                    <ConfirmationStep
                        data={data}
                        contacts={contacts}
                        addresses={addresses}
                        paymentMethods={paymentMethods}
                        hasOdessaPay={hasOdessaPay}
                        hasPayPal={hasPayPal}
                        selectedCoupon={selectedCoupon}
                        laboratoryAppointment={laboratoryAppointment}
                        onEditStep={goToStep}
                    />
                );
            default:
                return null;
        }
    };

    return (
        <>
            <CheckoutLayout
                title="Completar compra"
                header={
                    <div className="flex flex-col gap-8 sm:flex-row sm:items-center">
                        <LaboratoryBrandCard
                            src={`/images/gda/${laboratoryBrand.imageSrc}`}
                            className="w-48 p-4"
                        />

                        <div className="flex flex-col gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <GradientHeading noDivider>
                                    Completar compra
                                </GradientHeading>
                                <EnvironmentBadge />
                            </div>
                            {paymentUsesMock && (
                                <Text className="text-sm text-amber-800/90 dark:text-amber-200/90">
                                    Modo pruebas: las tarjetas precargadas no generan cargos reales en EfevooPay.
                                </Text>
                            )}
                            <Subheading>
                                <span className="text-base lg:text-lg">
                                    Completa tu compra en unos sencillos pasos.
                                </span>
                            </Subheading>
                        </div>
                    </div>
                }
                laboratoryBrand={laboratoryBrand}
                summaryDetails={summaryDetails}
                items={laboratoryCarts[laboratoryBrand.value].map(
                    (laboratoryCartItem) => ({
                        heading: laboratoryCartItem.laboratory_test.name,
                        description:
                            laboratoryCartItem.laboratory_test.description,
                        indications:
                            laboratoryCartItem.laboratory_test.indications,
                        features:
                            laboratoryCartItem.laboratory_test.feature_list,
                        price: laboratoryCartItem.laboratory_test
                            .formatted_famedic_price,
                        discountedPrice:
                            laboratoryCartItem.laboratory_test
                                .formatted_public_price,
                        discountPercentage: Math.round(
                            ((laboratoryCartItem.laboratory_test
                                .public_price_cents -
                                laboratoryCartItem.laboratory_test
                                    .famedic_price_cents) /
                                laboratoryCartItem.laboratory_test
                                    .public_price_cents) *
                                100,
                        ),
                        showDefaultImage: false,
                        ...(laboratoryCartItem.laboratory_test
                            .requires_appointment
                            ? { infoMessage: "Requiere cita" }
                            : {}),
                        onDestroy: () =>
                            setLaboratoryCartItemToDelete(laboratoryCartItem),
                    }),
                )}
                onlinePaymentDisabled={onlinePaymentDisabled}
                branchPaymentDisabled={branchPaymentDisabled}
                paymentProcessing={checkoutProcessing}
                submit={submit}
                showBranchPayment={true}
                data={data}
                stepper={
                    <CheckoutStepper
                        steps={wizardSteps}
                        currentStep={currentStepIndex}
                    />
                }
                footerActions={footerWithLegal}
                couponSection={couponSection}
                hideDefaultSubmit
                stepContentRef={stepContentRef}
                floatingWizardFooter={floatingWizardFooter}
            >
                {renderStepContent()}
            </CheckoutLayout>

            <DeleteConfirmationModal
                isOpen={!!laboratoryCartItemToDelete}
                close={() => setLaboratoryCartItemToDelete(null)}
                title="Quitar del carrito"
                description={`¿Estás seguro de que deseas quitarlo ${laboratoryCartItemToDelete?.laboratory_test.name} del carrito?`}
                processing={processing}
                destroy={destroyLaboratoryCartItem}
            />
        </>
    );
}

function LaboratoryAppointmentInfo({
    laboratoryAppointment,
    data,
    setData,
    errors,
    variant = "accordion",
}) {
    const isWizard = variant === "wizard";

    const patientContent = (
        <div className="space-y-3">
            {!isWizard && <Subheading>Paciente</Subheading>}
            <div>
                <Text className={isWizard ? "font-medium" : ""}>
                    {laboratoryAppointment.patient_full_name}
                </Text>
                <Text>{laboratoryAppointment.formatted_patient_gender}</Text>
                <Text>{laboratoryAppointment.formatted_patient_birth_date}</Text>
                <Text>{laboratoryAppointment.patient_phone}</Text>
            </div>
            <SwitchField>
                <Label>¿Guardar paciente?</Label>
                <Description>
                    Si decides guardar el paciente, podrás usarlo en futuras
                    compras.
                </Description>
                <Switch
                    checked={data.save_contact}
                    onChange={(value) => setData("save_contact", value)}
                />
                {errors.save_contact && (
                    <ErrorMessage>{errors.save_contact}</ErrorMessage>
                )}
            </SwitchField>
        </div>
    );

    const appointmentContent = (
        <div className="space-y-3">
            {!isWizard && <Subheading>Cita en laboratorio</Subheading>}
            <div>
                <Text>{laboratoryAppointment.laboratory_store.name}</Text>
                <Text className="max-w-sm">
                    <span className="text-xs">
                        {laboratoryAppointment.laboratory_store.address}
                    </span>
                </Text>
                <Badge color="sky" className="mt-2">
                    {laboratoryAppointment.formatted_appointment_date}
                </Badge>
            </div>
        </div>
    );

    if (isWizard) {
        return (
            <div className="space-y-6">
                <div className="flex items-start gap-2">
                    <CheckCircleIcon className="mt-0.5 size-6 flex-shrink-0 rounded-full fill-green-600 dark:fill-famedic-lime" />
                    {patientContent}
                </div>
                <Divider soft />
                <div className="flex items-start gap-2">
                    <CheckCircleIcon className="mt-0.5 size-6 flex-shrink-0 rounded-full fill-green-600 dark:fill-famedic-lime" />
                    {appointmentContent}
                </div>
                <Text className="text-sm text-zinc-600 dark:text-slate-400">
                    Si necesitas modificar tu cita, contáctanos al{" "}
                    <a href="tel:5566515232" className="underline">
                        55 6651 5232
                    </a>
                </Text>
            </div>
        );
    }

    return (
        <>
            <Card className="bg-zinc-50 px-4 py-6 sm:p-6 lg:p-8">
                <div className="flex items-start gap-2">
                    <CheckCircleIcon className="mt-0.5 size-6 flex-shrink-0 rounded-full fill-green-600 dark:fill-famedic-lime" />
                    <div className="space-y-3">
                        <Subheading>Paciente</Subheading>
                        <div>
                            <Text>
                                {laboratoryAppointment.patient_full_name}
                            </Text>
                            <Text>
                                {laboratoryAppointment.formatted_patient_gender}
                            </Text>
                            <Text>
                                {
                                    laboratoryAppointment.formatted_patient_birth_date
                                }
                            </Text>
                            <Text>{laboratoryAppointment.patient_phone}</Text>
                        </div>
                        <SwitchField>
                            <Label>¿Guardar paciente?</Label>
                            <Description>
                                Si decides guardar el paciente, podrás usarlo en
                                futuras compras.
                            </Description>
                            <Switch
                                checked={data.save_contact}
                                onChange={(value) =>
                                    setData("save_contact", value)
                                }
                            />
                            {errors.save_contact && (
                                <ErrorMessage>
                                    {errors.save_contact}
                                </ErrorMessage>
                            )}
                        </SwitchField>
                    </div>
                </div>
            </Card>

            <Card className="bg-zinc-50 px-4 py-6 sm:p-6 lg:p-8">
                <div className="flex items-start gap-2">
                    <CheckCircleIcon className="mt-0.5 size-6 flex-shrink-0 rounded-full fill-green-600 dark:fill-famedic-lime" />
                    <div className="space-y-3">
                        <Subheading>Cita en laboratorio</Subheading>
                        <div>
                            <Text>
                                {laboratoryAppointment.laboratory_store.name}
                            </Text>
                            <Text className="max-w-sm">
                                <span className="text-xs">
                                    {
                                        laboratoryAppointment.laboratory_store
                                            .address
                                    }
                                </span>
                            </Text>
                            <Badge color="sky" className="mt-2">
                                {
                                    laboratoryAppointment.formatted_appointment_date
                                }
                            </Badge>
                        </div>
                    </div>
                </div>
            </Card>

            <div>
                <Divider className="mb-2" />
                <Text>
                    Si necesitas hacer modificaciones a tu cita o información de
                    paciente, favor de contactarnos al{" "}
                    <a href="tel:5566515232" target="_blank">
                        <Button
                            plain
                            className="text-zinc-950 underline decoration-zinc-950/50 data-[hover]:decoration-zinc-950 dark:text-white dark:decoration-white/50 dark:data-[hover]:decoration-white"
                        >
                            <PhoneIcon />
                            55 6651 5232
                        </Button>
                    </a>
                </Text>
                <Divider className="mt-2" />
            </div>
        </>
    );
}
