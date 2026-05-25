import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Divider } from "@/Components/Catalyst/divider";
import Card from "@/Components/Card";
import CreditCardBrand from "@/Components/CreditCardBrand";
import { Badge } from "@/Components/Catalyst/badge";
import {
    UserCircleIcon,
    MapPinIcon,
    CreditCardIcon,
    PencilIcon,
} from "@heroicons/react/24/outline";

const PAYPAL_LOGO_LIGHT = "https://cdn.simpleicons.org/paypal/003087";
const PAYPAL_LOGO_DARK = "https://cdn.simpleicons.org/paypal/FFFFFF";

export default function ConfirmationStep({
    data,
    contacts,
    addresses,
    paymentMethods,
    hasOdessaPay,
    hasPayPal,
    selectedCoupon,
    laboratoryAppointment,
    onEditStep,
}) {
    const selectedContact = laboratoryAppointment
        ? {
              full_name: laboratoryAppointment.patient_full_name,
              formatted_gender: laboratoryAppointment.formatted_patient_gender,
              formatted_birth_date:
                  laboratoryAppointment.formatted_patient_birth_date,
              phone: laboratoryAppointment.patient_phone,
          }
        : contacts.find((c) => c.id == data.contact);

    const selectedAddress = addresses.find((a) => a.id == data.address);

    const paymentLabel = getPaymentLabel(
        data.payment_method,
        paymentMethods,
        hasPayPal,
        hasOdessaPay,
    );

    return (
        <div className="space-y-4">
            <Subheading>Confirma tu compra</Subheading>
            <Text className="text-sm text-zinc-600 dark:text-slate-400">
                Revisa que toda la información sea correcta antes de pagar.
            </Text>

            <ConfirmationSection
                icon={UserCircleIcon}
                title="Paciente"
                onEdit={() => onEditStep("patient")}
                canEdit={!laboratoryAppointment}
            >
                {selectedContact ? (
                    <DetailBlock
                        name={selectedContact.full_name}
                        details={[
                            selectedContact.formatted_gender,
                            selectedContact.formatted_birth_date,
                            selectedContact.phone,
                        ]}
                    />
                ) : (
                    <Text className="text-red-600 dark:text-red-400">
                        No se ha seleccionado un paciente
                    </Text>
                )}
            </ConfirmationSection>

            <ConfirmationSection
                icon={MapPinIcon}
                title="Dirección"
                onEdit={() => onEditStep("address")}
            >
                {selectedAddress ? (
                    <DetailBlock
                        name={`${selectedAddress.street} ${selectedAddress.number}`}
                        details={[
                            `${selectedAddress.neighborhood}, ${selectedAddress.zipcode}`,
                            `${selectedAddress.state}, ${selectedAddress.city}`,
                        ]}
                    />
                ) : (
                    <Text className="text-red-600 dark:text-red-400">
                        No se ha seleccionado una dirección
                    </Text>
                )}
            </ConfirmationSection>

            <ConfirmationSection
                icon={CreditCardIcon}
                title="Método de pago"
                onEdit={() => onEditStep("payment")}
            >
                {paymentLabel ? (
                    <PaymentSummary
                        paymentMethod={data.payment_method}
                        paymentMethods={paymentMethods}
                        selectedCoupon={selectedCoupon}
                    />
                ) : (
                    <Text className="text-red-600 dark:text-red-400">
                        No se ha seleccionado un método de pago
                    </Text>
                )}
            </ConfirmationSection>

            {laboratoryAppointment && (
                <Card className="bg-sky-50/50 p-4 dark:bg-sky-950/20">
                    <Subheading className="text-sm">Cita en laboratorio</Subheading>
                    <Text className="mt-1 text-sm">
                        {laboratoryAppointment.laboratory_store.name}
                    </Text>
                    <Badge color="sky" className="mt-2">
                        {laboratoryAppointment.formatted_appointment_date}
                    </Badge>
                </Card>
            )}
        </div>
    );
}

function ConfirmationSection({
    icon: Icon,
    title,
    onEdit,
    canEdit = true,
    children,
}) {
    return (
        <Card className="p-4 sm:p-5">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Icon className="size-5 text-famedic-dark dark:text-famedic-lime" />
                    <Subheading className="text-base">{title}</Subheading>
                </div>
                {canEdit && (
                    <Button
                        plain
                        onClick={onEdit}
                        type="button"
                        className="text-sm"
                    >
                        <PencilIcon className="size-4" />
                        Cambiar
                    </Button>
                )}
            </div>
            <Divider soft className="my-3" />
            {children}
        </Card>
    );
}

function DetailBlock({ name, details }) {
    return (
        <div>
            <Text className="font-medium">{name}</Text>
            {details.filter(Boolean).map((detail, i) => (
                <Text
                    key={i}
                    className="text-sm text-zinc-600 dark:text-slate-400"
                >
                    {detail}
                </Text>
            ))}
        </div>
    );
}

function PaymentSummary({ paymentMethod, paymentMethods, selectedCoupon }) {
    if (paymentMethod === "paypal") {
        return (
            <div className="flex items-center gap-3">
                <img
                    src={PAYPAL_LOGO_LIGHT}
                    alt="PayPal"
                    className="h-6 w-auto dark:hidden"
                />
                <img
                    src={PAYPAL_LOGO_DARK}
                    alt="PayPal"
                    className="hidden h-6 w-auto dark:block"
                />
                <Text className="font-medium">PayPal</Text>
            </div>
        );
    }

    if (paymentMethod === "odessa") {
        return (
            <DetailBlock
                name="Caja de ahorro Odessa"
                details={["Cobro directo a tu caja de ahorro"]}
            />
        );
    }

    if (paymentMethod === "coupon_balance") {
        return (
            <DetailBlock
                name="Saldo a favor (cupón)"
                details={[
                    selectedCoupon
                        ? `Cupón aplicado por ${(selectedCoupon.remaining_cents / 100).toLocaleString("es-MX", { style: "currency", currency: "MXN" })}`
                        : "El total se cubre con tu saldo disponible",
                ]}
            />
        );
    }

    const card = paymentMethods.find(
        (pm) => String(pm.id) === String(paymentMethod),
    );

    if (card) {
        return (
            <div className="flex items-center gap-3">
                <CreditCardBrand brand={card.card?.brand} className="size-7" />
                <div>
                    <Text className="font-medium">**** {card.card?.last4}</Text>
                    <Text className="text-sm text-zinc-600 dark:text-slate-400">
                        {card.billing_details?.name}
                    </Text>
                </div>
            </div>
        );
    }

    return null;
}

function getPaymentLabel(paymentMethod, paymentMethods, hasPayPal, hasOdessaPay) {
    if (!paymentMethod) return null;
    if (paymentMethod === "paypal" && hasPayPal) return "PayPal";
    if (paymentMethod === "odessa" && hasOdessaPay) return "Caja de ahorro Odessa";
    if (paymentMethod === "coupon_balance") return "Saldo a favor";
    const card = paymentMethods.find(
        (pm) => String(pm.id) === String(paymentMethod),
    );
    if (card) return `Tarjeta **** ${card.card?.last4}`;
    return null;
}
