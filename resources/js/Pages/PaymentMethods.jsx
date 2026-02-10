import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Text, Code } from "@/Components/Catalyst/text";
import { PlusIcon } from "@heroicons/react/16/solid";
import { 
    TrashIcon, 
    CreditCardIcon, 
    ExclamationTriangleIcon, 
    InformationCircleIcon,
    ClockIcon 
} from "@heroicons/react/24/outline";
import PaymentMethodDeleteConfirmation from "@/Pages/PaymentMethods/PaymentMethodDeleteConfirmation";
import { useState } from "react";
import CreditCardBrand from "@/Components/CreditCardBrand";
import SettingsCard from "@/Components/SettingsCard";

// ACTUALIZA ESTA LÍNEA: Agrega pending3dsSessions en las props
export default function PaymentMethods({ 
    paymentMethods, 
    hasOdessaPay, 
    efevooConfig,
    pending3dsSessions = []  // Agrega esta prop con valor por defecto
}) {
    const [paymentMethodToDelete, setPaymentMethodToDelete] = useState(null);

    // Filtrar tarjetas expiradas
    const activeCards = paymentMethods.filter(card => {
        const expYear = parseInt(card.card.exp_year);
        const expMonth = parseInt(card.card.exp_month);
        const now = new Date();
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth() + 1;

        // Verificar si la tarjeta está expirada
        if (expYear < currentYear) return false;
        if (expYear === currentYear && expMonth < currentMonth) return false;

        return true;
    });

    // Mostrar aviso si no hay tarjetas o si hay cambio de procesador
    const showMigrationNotice = activeCards.length === 0;

    return (
        <SettingsLayout title="Mis métodos de pago">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <GradientHeading noDivider>Mis métodos de pago</GradientHeading>

                <div className="flex items-center gap-4">
                    <div className="rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                        {efevooConfig.environment.toUpperCase()}
                    </div>
                    <Button
                        dusk="createPaymentMethod"
                        preserveState
                        preserveScroll
                        href={route("payment-methods.create")}
                    >
                        <PlusIcon />
                        Agregar tarjeta
                    </Button>
                </div>
            </div>

            <Divider className="my-6" />

            {/* Aviso importante sobre cambio de procesador */}
            {showMigrationNotice && (
                <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                    <div className="flex items-start">
                        <InformationCircleIcon className="mr-3 mt-0.5 size-5 flex-shrink-0 text-blue-600 dark:text-blue-400" />
                        <div className="flex-1">
                            <h3 className="text-sm font-medium text-blue-800 dark:text-blue-300">
                                ¡Importante: Nuevo procesador de pagos
                            </h3>
                            <div className="mt-2 text-sm text-blue-700 dark:text-blue-400">
                                <p className="mb-2">
                                    Hemos migrado de <strong>Stripe</strong> a nuestro nuevo procesador
                                    de pagos <strong>EfevooPay</strong> para ofrecerte un mejor servicio.
                                </p>
                                <p>
                                    Por favor, <strong>vuelve a agregar tus métodos de pago</strong> para
                                    continuar realizando transacciones sin interrupciones.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* MOSTRAR SESIONES 3DS PENDIENTES - CORREGIDO */}
            {pending3dsSessions && pending3dsSessions.length > 0 && (
                <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                    <div className="flex items-start">
                        <ClockIcon className="mr-3 mt-0.5 size-5 flex-shrink-0 text-amber-600 dark:text-amber-400" />
                        <div className="flex-1">
                            <h3 className="text-sm font-medium text-amber-800 dark:text-amber-300">
                                Verificaciones de tarjetas pendientes
                            </h3>
                            <div className="mt-2 space-y-2">
                                {pending3dsSessions.map((session) => (
                                    <div key={session.id} className="flex items-center justify-between rounded border border-amber-300 bg-white p-3 dark:border-amber-700 dark:bg-zinc-900">
                                        <div className="flex items-center gap-3">
                                            <CreditCardIcon className="size-5 stroke-amber-500" />
                                            <div>
                                                <p className="text-sm font-medium text-zinc-900 dark:text-white">
                                                    **** **** **** {session.card_last_four}
                                                </p>
                                                <p className="text-xs text-zinc-500 dark:text-zinc-400">
                                                    Cargo de verificación: ${session.amount} MXN • {session.status}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {session.requires_redirect ? (
                                                <Button
                                                    href={route('payment-methods.3ds-redirect', { sessionId: session.id })}
                                                    size="sm"
                                                    className="bg-amber-600 text-white hover:bg-amber-700"
                                                >
                                                    Completar verificación
                                                </Button>
                                            ) : (
                                                <span className="text-xs text-amber-600 dark:text-amber-400">
                                                    En proceso...
                                                </span>
                                            )}
                                            <Button
                                                href={route('payment-methods.3ds-cancel', { sessionId: session.id })}
                                                method="post"
                                                size="sm"
                                                outline
                                                as="button"
                                                className="border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20"
                                            >
                                                Cancelar
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Aviso general sobre el cambio (siempre visible) */}
            <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                <div className="flex items-start">
                    <ExclamationTriangleIcon className="mr-3 mt-0.5 size-5 flex-shrink-0 text-amber-600 dark:text-amber-400" />
                    <div className="flex-1">
                        <h3 className="text-sm font-medium text-amber-800 dark:text-amber-300">
                            Actualización del sistema de pagos
                        </h3>
                        <div className="mt-2 text-sm text-amber-700 dark:text-amber-400">
                            <p>
                                Ahora procesamos tus pagos con <strong>EfevooPay</strong>.
                                Este cambio mejora la seguridad y experiencia de pago.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <PaymentMethodsList
                paymentMethods={activeCards}
                setPaymentMethodToDelete={setPaymentMethodToDelete}
                hasOdessaPay={hasOdessaPay}
                showMigrationNotice={showMigrationNotice}
            />

            {activeCards.length === 0 && pending3dsSessions.length === 0 && (
                <div className="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                    <CreditCardIcon className="mx-auto size-12 stroke-zinc-300 dark:stroke-zinc-600" />
                    <Text className="mt-4 text-zinc-600 dark:text-zinc-400">
                        No tienes tarjetas guardadas. Agrega una para realizar pagos más rápido.
                    </Text>
                    <Button
                        className="mt-4"
                        preserveState
                        preserveScroll
                        href={route("payment-methods.create")}
                    >
                        <PlusIcon />
                        Agregar mi primera tarjeta
                    </Button>
                </div>
            )}

            <PaymentMethodDeleteConfirmation
                isOpen={!!paymentMethodToDelete}
                close={() => setPaymentMethodToDelete(null)}
                paymentMethod={paymentMethodToDelete}
            />
        </SettingsLayout>
    );
}

function PaymentMethodsList({ paymentMethods, setPaymentMethodToDelete, hasOdessaPay, showMigrationNotice }) {
    return (
        <ul className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {hasOdessaPay && <OdessaPaymentMethod />}
            {paymentMethods.map((paymentMethod) => (
                <SettingsCard
                    key={paymentMethod.id}
                    actions={
                        <div className="flex w-full items-end justify-between gap-4">
                            <div className="flex items-center gap-2">
                                <Text className="text-xs">
                                    {paymentMethod.card.exp_month} / {paymentMethod.card.exp_year}
                                </Text>
                                {paymentMethod.metadata?.environment === 'test' && (
                                    <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                                        TEST
                                    </span>
                                )}
                            </div>
                            <Button
                                dusk={`deletePaymentMethod-${paymentMethod.id}`}
                                onClick={() => setPaymentMethodToDelete(paymentMethod)}
                                outline
                                className="hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                            >
                                <TrashIcon className="stroke-red-400" />
                                Eliminar
                            </Button>
                        </div>
                    }
                    className="min-h-[11.5rem]"
                >
                    <div className="flex justify-between">
                        <CreditCardIcon className="size-6 stroke-zinc-500/40" />
                        <CreditCardBrand brand={paymentMethod.card.brand} />
                    </div>
                    <Text className="mt-8">
                        <Code>**** **** **** {paymentMethod.card.last4}</Code>
                    </Text>
                    <Text className="truncate">
                        <span className="truncate text-sm">
                            {paymentMethod.billing_details.name}
                        </span>
                    </Text>
                    {paymentMethod.metadata?.alias && (
                        <Text className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            Alias: {paymentMethod.metadata.alias}
                        </Text>
                    )}
                    {/* Indicador de nueva tarjeta EfevooPay */}
                    <div className="mt-2">
                        <span className="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300">
                            EfevooPay
                        </span>
                        {paymentMethod.metadata?.verified_with_3ds && (
                            <span className="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                                3DS
                            </span>
                        )}
                    </div>
                </SettingsCard>
            ))}
        </ul>
    );
}

function OdessaPaymentMethod() {
    return (
        <SettingsCard className="min-h-[11.5rem]">
            <div className="flex justify-between">
                <img
                    src="/images/odessa.png"
                    alt="odessa"
                    className="h-6 w-6"
                />
                <Text>odessa</Text>
            </div>
            <div className="mt-8">
                <Code>
                    <span className="text-orange-600 dark:text-orange-400">
                        Cobro a caja de ahorro
                    </span>
                </Code>
            </div>
            <div className="mt-2">
                <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                    EfevooPay
                </span>
            </div>
        </SettingsCard>
    );
}