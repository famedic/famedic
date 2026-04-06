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
import { useForm } from "@inertiajs/react";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { useState, useMemo, useEffect } from "react";
import ContactStep from "@/Components/Checkout/ContactStep";
import AddressStep from "@/Components/Checkout/AddressStep";
import PaymentMethodStep from "@/Components/Checkout/PaymentMethodStep";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { router } from "@inertiajs/react";

export default function LaboratoryCheckout({
    laboratoryAppointment,
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
    contacts,
}) {
    const {
        laboratoryCartItemToDelete,
        setLaboratoryCartItemToDelete,
        destroyLaboratoryCartItem,
        processing,
    } = useDeleteLaboratoryCartItem();

    const initialFormData = {
        contact:
            new URLSearchParams(window.location.search).get("contact") || null,
        address:
            new URLSearchParams(window.location.search).get("address") || null,
        payment_method:
            new URLSearchParams(window.location.search).get("payment_method") ||
            null,
        coupon_id: null,
    };

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

    // DEBUG: Log para ver los datos del formulario
    useEffect(() => {
        console.log('DEBUG - Form data updated:', {
            contact: data.contact,
            address: data.address,
            payment_method: data.payment_method,
            payment_method_type: typeof data.payment_method,
            is_string: typeof data.payment_method === 'string',
            is_number: typeof data.payment_method === 'number',
        });
    }, [data]);

    // Transformar datos antes de enviar
    transform((data) => {
        console.log('DEBUG - Transformando datos antes de enviar:', {
            original_payment_method: data.payment_method,
            original_type: typeof data.payment_method,
        });

        // Asegurar que payment_method sea string
        const transformedData = {
            ...data,
            payment_method: String(data.payment_method), // Convertir explícitamente a string
            laboratory_appointment: laboratoryAppointment?.id,
            total: total,
            coupon_id: data.coupon_id || null,
        };

        console.log('DEBUG - Datos transformados:', {
            transformed_payment_method: transformedData.payment_method,
            transformed_type: typeof transformedData.payment_method,
        });

        return transformedData;
    });

    const submit = (e, isBranchPayment) => {
        e.preventDefault();

        if (checkoutProcessing) return;

        console.log('DEBUG - Enviando formulario:', {
            isBranchPayment,
            formData: data,
            payment_method: data.payment_method,
            payment_method_type: typeof data.payment_method,
            laboratoryBrand: laboratoryBrand.value,
            total: total,
        });

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

            console.log('DEBUG - Generando cotización para pago en sucursal:', {
                items_count: items.length,
                appointment_id: laboratoryAppointment?.id,
                contact_id: data.contact,
                address_id: data.address,
            });

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
                            console.log('DEBUG - Redirigiendo a:', redirectUrl);
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
            // PAGO ONLINE
            console.log('DEBUG - Enviando pago online a:', 
                route("laboratory.checkout.store", { 
                    laboratory_brand: laboratoryBrand.value 
                })
            );

            post(route("laboratory.checkout.store", { 
                laboratory_brand: laboratoryBrand.value 
            }), {
                onSuccess: (page) => {
                    console.log('DEBUG - Pago exitoso, redirigiendo');
                },
                onError: (errors) => {
                    console.error('DEBUG - Errores del backend:', errors);
                    
                    // Manejar errores específicos
                    if (errors.payment_method) {
                        alert(`Error en método de pago: ${errors.payment_method}`);
                    } else if (errors.message) {
                        alert(errors.message);
                    } else {
                        alert('Error al procesar el pago. Por favor intenta de nuevo.');
                    }
                },
                onFinish: () => {
                    console.log('DEBUG - Petición completada');
                }
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

    const contactStepIsComplete = useMemo(() => {
        const isComplete = !!data.contact || laboratoryAppointment;
        console.log('DEBUG - Contact step complete:', { 
            isComplete, 
            contact: data.contact, 
            appointment: laboratoryAppointment 
        });
        return isComplete;
    }, [data.contact, laboratoryAppointment]);

    const addressStepIsComplete = useMemo(() => {
        const isComplete = !!data.address;
        console.log('DEBUG - Address step complete:', { 
            isComplete, 
            address: data.address 
        });
        return isComplete;
    }, [data.address]);

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
        !paymentMethodStepIsComplete;

    const branchPaymentDisabled = checkoutProcessing ||
        !addressStepIsComplete ||
        !contactStepIsComplete;

    console.log('DEBUG - Estado del checkout:', {
        onlinePaymentDisabled,
        branchPaymentDisabled,
        steps: {
            contact: contactStepIsComplete,
            address: addressStepIsComplete,
            payment: paymentMethodStepIsComplete
        },
        paymentMethodsCount: paymentMethods?.length || 0,
        hasOdessaPay,
        laboratoryBrand: laboratoryBrand.value,
    });

    const addCardReturnUrl = useMemo(() => {
        const filteredData = Object.fromEntries(
            Object.entries(data).filter(
                ([_, value]) =>
                    value !== undefined && value !== null && value !== "",
            ),
        );

        return route("laboratory.checkout", {
            laboratory_brand: laboratoryBrand.value,
            ...filteredData,
        });
    }, [data]);

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
                            <GradientHeading noDivider>
                                Completar compra
                            </GradientHeading>
                            <Subheading>
                                <span className="text-xl lg:text-2xl">
                                    Vamos a asegurarnos de que todo sea
                                    correcto.{" "}
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
            >
                <>
                    {laboratoryAppointment ? (
                        <LaboratoryAppointmentInfo
                            data={data}
                            setData={setData}
                            laboratoryAppointment={laboratoryAppointment}
                            errors={errors}
                        />
                    ) : (
                        <ContactStep
                            data={data}
                            setData={setData}
                            errors={errors}
                            error={errors.contact}
                            clearErrors={clearErrors}
                            contacts={contacts}
                            toggleContactForm={toggleContactForm}
                            showContactForm={showContactForm}
                        />
                    )}
                    <AddressStep
                        disabled={!contactStepIsComplete}
                        data={data}
                        setData={setData}
                        errors={errors}
                        error={errors.address}
                        clearErrors={clearErrors}
                        addresses={addresses}
                        toggleAddressForm={toggleAddressForm}
                        showAddressForm={showAddressForm}
                    />
                    {balanceCouponsCents > 0 && (
                        <Card className="bg-emerald-50/80 px-4 py-6 sm:p-6 dark:bg-emerald-950/30">
                            <Subheading>Saldo a favor</Subheading>
                            <Text className="mt-2">
                                Tienes{" "}
                                <Strong>{formattedBalanceCoupons}</Strong>{" "}
                                disponibles.
                            </Text>
                            {noCouponApplicable && (
                                <Text className="mt-2 text-amber-800 dark:text-amber-200">
                                    Tu saldo es mayor al total de la compra, no
                                    puede aplicarse en esta compra.
                                </Text>
                            )}
                            {errors.coupon_id && (
                                <ErrorMessage className="mt-2">
                                    {errors.coupon_id}
                                </ErrorMessage>
                            )}
                            <div className="mt-4 flex flex-wrap gap-3">
                                {!data.coupon_id ? (
                                    <Button
                                        type="button"
                                        color="emerald"
                                        disabled={
                                            noCouponApplicable ||
                                            !addressStepIsComplete ||
                                            !contactStepIsComplete
                                        }
                                        onClick={applyBalanceCoupon}
                                    >
                                        Usar saldo completo
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        plain
                                        onClick={clearBalanceCoupon}
                                    >
                                        Quitar cupón
                                    </Button>
                                )}
                            </div>
                        </Card>
                    )}
                    <PaymentMethodStep
                        disabled={
                            !addressStepIsComplete ||
                            !contactStepIsComplete ||
                            (amountAfterCoupon === 0 && !!data.coupon_id)
                        }
                        data={data}
                        setData={setData}
                        errors={errors}
                        error={errors.payment_method}
                        clearErrors={clearErrors}
                        paymentMethods={paymentMethods}
                        hasOdessaPay={hasOdessaPay}
                        addCardReturnUrl={addCardReturnUrl}
                    />
                </>
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
}) {
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