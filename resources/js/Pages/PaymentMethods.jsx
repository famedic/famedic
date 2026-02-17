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
    ClockIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    ShieldCheckIcon,
    SparklesIcon,
    CheckCircleIcon
} from "@heroicons/react/24/outline";
import PaymentMethodDeleteConfirmation from "@/Pages/PaymentMethods/PaymentMethodDeleteConfirmation";
import { useState } from "react";
import CreditCardBrand from "@/Components/CreditCardBrand";
import SettingsCard from "@/Components/SettingsCard";

export default function PaymentMethods({
    paymentMethods = [],
    hasOdessaPay,
    efevooConfig = {},
    pending3dsSessions = []
}) {
    const [paymentMethodToDelete, setPaymentMethodToDelete] = useState(null);
    const [showPendingInfo, setShowPendingInfo] = useState(true);
    const [showMigrationInfo, setShowMigrationInfo] = useState(true);

    // Filtrar tarjetas expiradas
    const activeCards = paymentMethods.filter(card => {
        if (!card.card_expiration) return true;

        const expMonth = parseInt(card.card_expiration.substring(0, 2));
        const expYear = parseInt("20" + card.card_expiration.substring(2, 4));

        const now = new Date();
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth() + 1;

        if (expYear < currentYear) return false;
        if (expYear === currentYear && expMonth < currentMonth) return false;

        return true;
    });

    // Agrupar tarjetas por alias para mejor organización
    const sortedCards = [...activeCards].sort((a, b) => {
        // Priorizar tarjetas con alias
        if (a.alias && !b.alias) return -1;
        if (!a.alias && b.alias) return 1;
        return 0;
    });

    const showMigrationNotice = activeCards.length === 0;

    return (
        <SettingsLayout title="Mis métodos de pago">
            {/* Header mejorado */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <GradientHeading noDivider className="text-xl sm:text-2xl">
                        Mis métodos de pago
                    </GradientHeading>
                    <div className="flex items-center gap-2">
                        <span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                            {efevooConfig?.environment?.toUpperCase() ?? ''}
                        </span>
                        {sortedCards.length > 0 && (
                            <span className="rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                {sortedCards.length} {sortedCards.length === 1 ? 'tarjeta' : 'tarjetas'}
                            </span>
                        )}
                    </div>
                </div>

                <Button
                    dusk="createPaymentMethod"
                    preserveState
                    preserveScroll
                    href={route("payment-methods.create")}
                    className="shadow-sm"
                >
                    <PlusIcon className="size-4" />
                    Agregar tarjeta
                </Button>
            </div>

            <Divider className="my-6" />

            {/* SECCIÓN DE NOTIFICACIONES COMPACTAS */}
            <div className="mb-6 space-y-2">
                {/* Aviso de migración - Compacto y colapsable */}
                {showMigrationNotice && showMigrationInfo && (
                    <div className="rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20">
                        <div className="flex items-center justify-between p-3">
                            <div className="flex items-center gap-2">
                                <div className="rounded-full bg-blue-100 p-1 dark:bg-blue-800">
                                    <InformationCircleIcon className="size-4 text-blue-600 dark:text-blue-400" />
                                </div>
                                <span className="text-sm font-medium text-blue-800 dark:text-blue-300">
                                    Nuevo procesador: EfevooPay
                                </span>
                            </div>
                            <button
                                onClick={() => setShowMigrationInfo(false)}
                                className="rounded-full p-1 hover:bg-blue-200 dark:hover:bg-blue-800"
                            >
                                <ChevronUpIcon className="size-4 text-blue-600 dark:text-blue-400" />
                            </button>
                        </div>
                        <div className="border-t border-blue-200 px-3 pb-3 pt-2 text-xs text-blue-700 dark:border-blue-800 dark:text-blue-400">
                            <p>Por favor, vuelve a agregar tus métodos de pago para continuar sin interrupciones.</p>
                        </div>
                    </div>
                )}

                {/* Verificaciones pendientes - Compacto */}
                {pending3dsSessions?.length > 0 && showPendingInfo && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20">
                        <div className="flex items-center justify-between p-3">
                            <div className="flex items-center gap-2">
                                <div className="rounded-full bg-amber-100 p-1 dark:bg-amber-800">
                                    <ClockIcon className="size-4 text-amber-600 dark:text-amber-400" />
                                </div>
                                <span className="text-sm font-medium text-amber-800 dark:text-amber-300">
                                    {pending3dsSessions.length} verificación{pending3dsSessions.length !== 1 && 'es'} pendiente{pending3dsSessions.length !== 1 && 's'}
                                </span>
                            </div>
                            <button
                                onClick={() => setShowPendingInfo(false)}
                                className="rounded-full p-1 hover:bg-amber-200 dark:hover:bg-amber-800"
                            >
                                <ChevronUpIcon className="size-4 text-amber-600 dark:text-amber-400" />
                            </button>
                        </div>
                        <div className="border-t border-amber-200 px-3 pb-3 pt-2 dark:border-amber-800">
                            {pending3dsSessions.map((session) => (
                                <div key={session.id} className="flex items-center justify-between py-1.5 text-xs">
                                    <div className="flex items-center gap-2">
                                        {/* CORREGIDO: Verificar que session.card_brand existe antes de pasarlo */}
                                        {session.card_brand ? (
                                            <CreditCardBrand 
                                                brand={session.card_brand} 
                                                className="h-4 w-6"
                                            />
                                        ) : (
                                            <CreditCardIcon className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                                        )}
                                        <span className="font-mono text-amber-800 dark:text-amber-300">
                                            •••• {session.card_last_four}
                                        </span>
                                        <span className="text-amber-600 dark:text-amber-400">
                                            ${session.amount} MXN
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {session.requires_redirect ? (
                                            <Button
                                                href={route('payment-methods.3ds-redirect', { sessionId: session.id })}
                                                size="sm"
                                                className="!py-1 text-xs"
                                            >
                                                Verificar
                                            </Button>
                                        ) : (
                                            <span className="text-amber-600 dark:text-amber-400">
                                                En proceso...
                                            </span>
                                        )}
                                        <Button
                                            href={route('payment-methods.3ds-cancel', { sessionId: session.id })}
                                            method="post"
                                            size="sm"
                                            outline
                                            as="button"
                                            className="!py-1 text-xs border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400"
                                        >
                                            Cancelar
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Badge de actualización permanente (más discreto) */}
                <div className="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-800/50 dark:text-gray-400">
                    <ShieldCheckIcon className="size-3.5 text-green-500" />
                    <span>Procesamos tus pagos con <strong>EfevooPay</strong> para mayor seguridad</span>
                </div>
            </div>

            {/* LISTA DE TARJETAS MEJORADA */}
            {sortedCards.length > 0 || pending3dsSessions.length > 0 ? (
                <div className="space-y-6">
                    {/* Tarjetas activas */}
                    {sortedCards.length > 0 && (
                        <div>
                            <h3 className="mb-3 text-sm font-medium text-gray-500 dark:text-gray-400">
                                Tarjetas activas
                            </h3>
                            <PaymentMethodsList
                                paymentMethods={sortedCards}
                                setPaymentMethodToDelete={setPaymentMethodToDelete}
                                hasOdessaPay={hasOdessaPay}
                            />
                        </div>
                    )}

                    {/* OdessaPay si existe */}
                    {hasOdessaPay && (
                        <div>
                            <h3 className="mb-3 text-sm font-medium text-gray-500 dark:text-gray-400">
                                Otros métodos
                            </h3>
                            <OdessaPaymentMethod />
                        </div>
                    )}
                </div>
            ) : (
                /* Estado vacío mejorado */
                <div className="rounded-xl border-2 border-dashed border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-900/50">
                    <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/20">
                        <CreditCardIcon className="size-8 stroke-blue-500" />
                    </div>
                    <Text className="text-lg font-medium text-zinc-900 dark:text-white">
                        No tienes tarjetas guardadas
                    </Text>
                    <Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Agrega una tarjeta para realizar pagos más rápido y seguro
                    </Text>
                    <Button
                        className="mt-6"
                        preserveState
                        preserveScroll
                        href={route("payment-methods.create")}
                    >
                        <PlusIcon className="size-4" />
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

// COMPONENTE DE LISTA DE TARJETAS MEJORADO
function PaymentMethodsList({ paymentMethods, setPaymentMethodToDelete }) {
    return (
        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {paymentMethods.map((paymentMethod) => (
                <SettingsCard
                    key={paymentMethod.id}
                    className="group relative overflow-hidden transition-all hover:shadow-md"
                >
                    {/* Decoración de marca de agua con tipo de tarjeta - CORREGIDO: Verificar que card_brand existe */}
                    {paymentMethod.card_brand && (
                        <div className="absolute -right-4 -top-4 opacity-5">
                            <CreditCardBrand 
                                brand={paymentMethod.card_brand} 
                                className="h-20 w-20"
                            />
                        </div>
                    )}

                    {/* Header de la tarjeta */}
                    <div className="flex items-start justify-between">
                        <div className="flex items-center gap-2">
                            {/* Logo de la marca - CORREGIDO: Verificar que card_brand existe */}
                            <div className="rounded-lg bg-gray-100 p-1.5 dark:bg-gray-800">
                                {paymentMethod.card_brand ? (
                                    <CreditCardBrand 
                                        brand={paymentMethod.card_brand} 
                                        className="h-5 w-7"
                                    />
                                ) : (
                                    <CreditCardIcon className="h-5 w-5 text-gray-500" />
                                )}
                            </div>
                            {/* Alias si existe */}
                            {paymentMethod.alias && (
                                <div className="flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                    <SparklesIcon className="size-3" />
                                    <span className="max-w-[100px] truncate">{paymentMethod.alias}</span>
                                </div>
                            )}
                        </div>
                        
                        {/* Badge de ambiente */}
                        {paymentMethod.environment === 'test' && (
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                TEST
                            </span>
                        )}
                    </div>

                    {/* Número de tarjeta enmascarado */}
                    <div className="mt-4 flex items-center gap-2">
                        <Text className="font-mono text-lg tracking-wider">
                            •••• •••• •••• {paymentMethod.card_last_four}
                        </Text>
                    </div>

                    {/* Titular y expiración */}
                    <div className="mt-3 flex items-center justify-between">
                        <Text className="truncate text-sm text-gray-600 dark:text-gray-400">
                            {paymentMethod.card_holder || 'Sin titular'}
                        </Text>
                        {paymentMethod.card_expiration && (
                            <Text className="text-xs text-gray-500 dark:text-gray-500">
                                Exp. {paymentMethod.card_expiration.substring(0, 2)}/20
                                {paymentMethod.card_expiration.substring(2, 4)}
                            </Text>
                        )}
                    </div>

                    {/* Footer con acciones */}
                    <div className="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 dark:border-gray-800">
                        <div className="flex items-center gap-1.5">
                            <span className="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                <CheckCircleIcon className="mr-1 size-3" />
                                EfevooPay
                            </span>
                        </div>
                        
                        <Button
                            onClick={() => setPaymentMethodToDelete(paymentMethod)}
                            outline
                            className="border-red-200 text-red-600 hover:bg-red-50 hover:text-red-700 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20"
                        >
                            <TrashIcon className="size-4" />
                            Eliminar
                        </Button>
                    </div>
                </SettingsCard>
            ))}
        </ul>
    );
}

// COMPONENTE ODESSA MEJORADO
function OdessaPaymentMethod() {
    return (
        <SettingsCard className="relative overflow-hidden bg-gradient-to-br from-orange-50 to-amber-50 dark:from-orange-950/20 dark:to-amber-950/20">
            <div className="absolute right-0 top-0 h-20 w-20 opacity-10">
                <div className="h-full w-full rounded-bl-full bg-orange-500" />
            </div>
            
            <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                    <div className="rounded-xl bg-white p-2 shadow-sm dark:bg-gray-800">
                        <img
                            src="/images/odessa.png"
                            alt="odessa"
                            className="h-6 w-6"
                        />
                    </div>
                    <div>
                        <Text className="font-medium">Caja de Ahorro Odessa</Text>
                        <Text className="text-xs text-gray-500 dark:text-gray-400">
                            Cobro a caja de ahorro
                        </Text>
                    </div>
                </div>
                <Text className="text-orange-600 dark:text-orange-400">odessa</Text>
            </div>

            <div className="mt-4 flex items-center gap-2">
                <Code className="text-sm">
                    <span className="text-orange-600 dark:text-orange-400">
                        **** **** **** 0000
                    </span>
                </Code>
            </div>

            <div className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                <p>Método de pago interno</p>
            </div>

            <div className="mt-4 flex items-center justify-between border-t border-orange-200 pt-3 dark:border-orange-800">
                <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                    <CheckCircleIcon className="mr-1 size-3" />
                    EfevooPay
                </span>
                <Button outline disabled className="border-gray-200 text-gray-400">
                    No editable
                </Button>
            </div>
        </SettingsCard>
    );
}