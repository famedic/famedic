import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { useForm, Link } from "@inertiajs/react";
import {
    ArrowLeftIcon,
    InformationCircleIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    LockClosedIcon,
    CreditCardIcon,
    ShieldCheckIcon
} from "@heroicons/react/24/outline";
import { useState } from "react";
import CreditCardBrand from "@/Components/CreditCardBrand";
import SimpleField from "@/Components/Form/SimpleField";
import SimpleInput from "@/Components/Form/SimpleInput";
import EnvironmentBadge from "@/Components/EnvironmentBadge";

export default function Create({
    efevooConfig = {},
    hasPending3ds = false,
    paymentUsesMock = false,
    mockTestCards = [],
    heyBancoEnabled = false,
    defaultPaymentProvider = "efevoopay",
    heyBancoTestCards = [],
    returnUrl = null,
}) {

    const { data, setData, post, processing, errors } = useForm({
        card_number: "",
        exp_month: "",
        exp_year: "",
        cvv: "",
        card_holder: "",
        alias: "",
        terms_accepted: false,
        payment_provider: heyBancoEnabled ? defaultPaymentProvider : "efevoopay",
    });

    const [cardType, setCardType] = useState("");
    const [showSecurityInfo, setShowSecurityInfo] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const currentYear = new Date().getFullYear();
    const months = Array.from({ length: 12 }, (_, i) =>
        String(i + 1).padStart(2, "0")
    );
    const years = Array.from({ length: 11 }, (_, i) => currentYear + i);

    /* ==========================================================
     * DETECT CARD TYPE
     * ========================================================== */

    const applyTestCard = (card) => {
        const raw = String(card.number).replace(/\D/g, "");
        setData({
            ...data,
            card_number: raw,
            exp_month: card.exp_month,
            exp_year: `20${card.exp_year}`,
            cvv: card.cvv,
            card_holder: card.card_holder ?? card.name ?? "Titular Mock",
            alias: card.alias ?? `mock-${raw.slice(-4)}`,
        });
        detectCardType(raw);
    };

    const detectCardType = (number) => {
        const cleaned = number.replace(/\D/g, "");
        let type = "";

        if (/^4/.test(cleaned)) type = "visa";
        else if (/^5[1-5]/.test(cleaned)) type = "mastercard";
        else if (/^3[47]/.test(cleaned)) type = "amex";

        setCardType(type);

        if (cleaned.length >= 4 && !data.alias.trim()) {
            const lastFour = cleaned.slice(-4);
            setData("alias", `${type || "tarjeta"}-${lastFour}`);
        }
    };

    const formatCardNumber = (value) => {
        const cleaned = value.replace(/\D/g, "");
        const groups = cleaned.match(/.{1,4}/g);
        return groups ? groups.join(" ") : "";
    };

    /* ==========================================================
     * SUBMIT
     * ========================================================== */

    const handleSubmit = (e) => {
        e.preventDefault();

        if (!data.terms_accepted) return;

        setSubmitting(true);

        const formattedData = {
            ...data,
            exp_month: String(data.exp_month).padStart(2, "0"),
            exp_year: String(data.exp_year).slice(-2),
            payment_provider: data.payment_provider,
        };

        post(route("payment-methods.store"), {
            ...formattedData,
            return_url: returnUrl,
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
        });
    };

    /* ==========================================================
     * VIEW
     * ========================================================== */

    return (
        <SettingsLayout title="Agregar tarjeta">

            <div className="flex items-center gap-3 mb-6">
                <Button
                    href={returnUrl ?? route("payment-methods.index")}
                    outline
                    className="size-9 p-0"
                >
                    <ArrowLeftIcon className="size-4" />
                </Button>
                <div className="flex flex-wrap items-center gap-2">
                    <GradientHeading noDivider>
                        Agregar nueva tarjeta
                    </GradientHeading>
                    <EnvironmentBadge />
                </div>
            </div>

            {heyBancoEnabled && (
                <div className="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <Text className="text-sm font-medium mb-3">
                        Procesador de pago
                    </Text>
                    <div className="flex flex-wrap gap-3">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="payment_provider"
                                value="efevoopay"
                                checked={data.payment_provider === "efevoopay"}
                                onChange={() => setData("payment_provider", "efevoopay")}
                            />
                            EfevooPay
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="payment_provider"
                                value="hey_banco"
                                checked={data.payment_provider === "hey_banco"}
                                onChange={() => setData("payment_provider", "hey_banco")}
                            />
                            Hey Banco / Banregio Colecto
                        </label>
                    </div>
                    {data.payment_provider === "hey_banco" && (
                        <Text className="mt-2 text-xs text-zinc-500">
                            Tokenización directa sin 3D Secure (fase actual).
                        </Text>
                    )}
                </div>
            )}

            {heyBancoEnabled && data.payment_provider === "hey_banco" && heyBancoTestCards.length > 0 && (
                <div className="mb-6 rounded-lg border border-emerald-200/80 bg-emerald-50/70 p-4 dark:border-emerald-800/50 dark:bg-emerald-950/30">
                    <Text className="text-sm font-medium text-emerald-900 dark:text-emerald-100">
                        Tarjetas de prueba Hey Banco (sandbox)
                    </Text>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {heyBancoTestCards.map((card) => (
                            <Button
                                key={card.number}
                                type="button"
                                outline
                                onClick={() => applyTestCard({
                                    ...card,
                                    card_holder: "Titular Sandbox",
                                    alias: `${card.brand}-${String(card.number).slice(-4)}`,
                                })}
                                className="text-xs"
                            >
                                {card.brand}
                            </Button>
                        ))}
                    </div>
                </div>
            )}

            {paymentUsesMock && mockTestCards.length > 0 && (
                <div className="mb-6 rounded-lg border border-amber-200/80 bg-amber-50/70 p-4 dark:border-amber-800/50 dark:bg-amber-950/30">
                    <Text className="text-sm font-medium text-amber-900 dark:text-amber-100">
                        Tarjetas de prueba (sin cargo real)
                    </Text>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {mockTestCards.map((card) => (
                            <Button
                                key={card.number}
                                type="button"
                                outline
                                onClick={() => applyTestCard(card)}
                                className="text-xs"
                            >
                                {card.name}
                            </Button>
                        ))}
                    </div>
                </div>
            )}

            {data.payment_provider === "efevoopay" && efevooConfig?.requires_3ds && (
                <div className="mb-6 rounded-lg bg-blue-50 p-4 text-sm text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                    <div className="flex items-center gap-2">
                        <ShieldCheckIcon className="size-4" />
                        <span>
                            Tu banco puede solicitar verificación adicional (3D Secure)
                        </span>
                    </div>
                </div>
            )}

            {errors.error && (
                <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-200">
                    <p className="font-medium">No se pudo iniciar la verificación</p>
                    <p className="mt-1">{errors.error}</p>
                </div>
            )}

            <form onSubmit={handleSubmit} className="max-w-2xl relative">

                {/* Overlay mientras envía */}
                {submitting && (
                    <div className="absolute inset-0 z-50 flex flex-col items-center justify-center bg-white/80 dark:bg-black/60 backdrop-blur-sm rounded-lg">
                        <div className="h-12 w-12 animate-spin rounded-full border-4 border-blue-600 border-t-transparent"></div>
                        <p className="mt-4 font-medium">
                            Preparando verificación segura...
                        </p>
                        <p className="text-sm text-gray-500 mt-1">
                            No cierres esta ventana
                        </p>
                    </div>
                )}

                <div className="space-y-5">

                    <SimpleField>
                        <SimpleInput
                            label="Número de tarjeta"
                            value={formatCardNumber(data.card_number)}
                            onChange={(e) => {
                                const raw = e.target.value.replace(/\D/g, "");
                                if (raw.length <= 16) {
                                    setData("card_number", raw);
                                    detectCardType(raw);
                                }
                            }}
                            required
                            error={errors.card_number}
                        />
                    </SimpleField>

                    <div className="grid grid-cols-3 gap-3">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Mes
                            </label>
                            <select
                                value={data.exp_month}
                                onChange={(e) => setData("exp_month", e.target.value)}
                                required
                                className={`block w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 dark:bg-gray-800 dark:text-gray-100 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 ${
                                    errors.exp_month
                                        ? "border-red-300 focus:ring-red-500/25 dark:border-red-700"
                                        : "border-gray-300 focus:ring-blue-500/25 dark:border-gray-600"
                                }`}
                            >
                                <option value="">Selecciona</option>
                                {months.map((m) => (
                                    <option key={m} value={m}>
                                        {m}
                                    </option>
                                ))}
                            </select>
                            {errors.exp_month && (
                                <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {errors.exp_month}
                                </p>
                            )}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Año
                            </label>
                            <select
                                value={data.exp_year}
                                onChange={(e) => setData("exp_year", e.target.value)}
                                required
                                className={`block w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 dark:bg-gray-800 dark:text-gray-100 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 ${
                                    errors.exp_year
                                        ? "border-red-300 focus:ring-red-500/25 dark:border-red-700"
                                        : "border-gray-300 focus:ring-blue-500/25 dark:border-gray-600"
                                }`}
                            >
                                <option value="">Selecciona</option>
                                {years.map((y) => (
                                    <option key={y} value={y}>
                                        {y}
                                    </option>
                                ))}
                            </select>
                            {errors.exp_year && (
                                <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {errors.exp_year}
                                </p>
                            )}
                        </div>
                        <SimpleInput
                            label="CVV"
                            type="password"
                            value={data.cvv}
                            onChange={(e) => setData("cvv", e.target.value.replace(/\D/g, ""))}
                            maxLength={4}
                            required
                        />
                    </div>

                    <SimpleInput
                        label="Nombre del titular"
                        value={data.card_holder}
                        onChange={(e) => setData("card_holder", e.target.value.toUpperCase())}
                        required
                    />

                    <SimpleInput
                        label="Alias"
                        value={data.alias}
                        onChange={(e) => setData("alias", e.target.value)}
                        required
                    />

                    <div className="flex items-start gap-3">
                        <input
                            type="checkbox"
                            checked={data.terms_accepted}
                            onChange={(e) => setData("terms_accepted", e.target.checked)}
                            required
                        />
                        <span className="text-sm text-white/80 dark:text-white/70">
                            Acepto los{" "}
                            <Link
                                href={route("terms-of-service")}
                                target="_blank"
                                className="text-blue-600 underline"
                            >
                                términos y condiciones
                            </Link>
                        </span>
                    </div>

                    <div className="flex gap-3 pt-4">
                        <Button
                            type="submit"
                            disabled={processing || submitting}
                            className="flex-1"
                        >
                            Guardar tarjeta
                        </Button>

                        <Button
                            href={route("payment-methods.index")}
                            outline
                        >
                            Cancelar
                        </Button>
                    </div>

                </div>
            </form>
        </SettingsLayout>
    );
}