import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { useForm, Link } from "@inertiajs/react";
import {
    ArrowLeftIcon,
    ShieldCheckIcon,
    InformationCircleIcon,
} from "@heroicons/react/24/outline";
import { useEffect, useState } from "react";
import SimpleField from "@/Components/Form/SimpleField";
import SimpleInput from "@/Components/Form/SimpleInput";
import EnvironmentBadge from "@/Components/EnvironmentBadge";
import { loadAttemptUuid } from "@/lib/paymentAuthAttemptIdentity";

function clearSensitiveFormState(setData) {
    setData({
        card_number: "",
        exp_month: "",
        exp_year: "",
        cvv: "",
        card_holder: "",
    });
}

export default function Create({
    efevooConfig = {},
    hasPending3ds = false,
    paymentUsesMock = false,
    mockTestCards = [],
    returnUrl = null,
    recoveryContext = null,
    recoveryForm = null,
    isRecoveryForm = false,
    paymentAuthStorageKey = null,
}) {
    const [attemptUuid] = useState(() =>
        loadAttemptUuid(paymentAuthStorageKey, {
            isRecoveryForm,
            recoverySubmissionIdentity: recoveryForm?.recovery_submission_identity ?? null,
        })
    );
    const safeReturnHref =
        recoveryContext?.return_action?.href || returnUrl || route("payment-methods.index");

    const { data, setData, post, processing, errors, transform } = useForm({
        card_number: "",
        exp_month: "",
        exp_year: "",
        cvv: "",
        card_holder: "",
        alias: "",
        terms_accepted: false,
        attempt_uuid: attemptUuid,
        recovery_context_uuid: recoveryContext?.context_uuid || "",
    });

    const [cardType, setCardType] = useState("");
    const [showSecurityInfo, setShowSecurityInfo] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [conflictAttempt, setConflictAttempt] = useState(null);

    const currentYear = new Date().getFullYear();
    const months = Array.from({ length: 12 }, (_, i) =>
        String(i + 1).padStart(2, "0")
    );
    const years = Array.from({ length: 11 }, (_, i) => currentYear + i);

    useEffect(() => {
        return () => {
            clearSensitiveFormState(setData);
        };
    }, [setData]);

    useEffect(() => {
        const payload = window.history?.state?.props?.payment_authentication_attempt;

        if (payload) {
            setConflictAttempt(payload);
        }
    }, []);

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

    const handleSubmit = (e) => {
        e.preventDefault();

        if (!data.terms_accepted) return;

        setSubmitting(true);
        setConflictAttempt(null);

        const formattedData = {
            ...data,
            attempt_uuid: attemptUuid,
            recovery_context_uuid: recoveryContext?.context_uuid || data.recovery_context_uuid || "",
            exp_month: String(data.exp_month).padStart(2, "0"),
            exp_year: String(data.exp_year).slice(-2),
        };

        transform(() => formattedData);

        post(route("payment-methods.store"), {
            preserveScroll: true,
            onError: (formErrors) => {
                if (formErrors.error?.includes("verificacion") || formErrors.error?.includes("verificación")) {
                    const active = window.history?.state?.props?.payment_authentication_attempt;
                    if (active) setConflictAttempt(active);
                }
            },
            onFinish: () => setSubmitting(false),
        });
    };

    const heading = isRecoveryForm ? "Verificar tarjeta nuevamente" : "Agregar nueva tarjeta";

    return (
        <SettingsLayout title={heading}>
            <div className="mb-6 flex scroll-mt-28 items-center gap-3 pt-6 sm:pt-2">
                <Button href={safeReturnHref} outline className="size-9 p-0">
                    <ArrowLeftIcon className="size-4" />
                </Button>
                <div className="flex flex-wrap items-center gap-2">
                    <GradientHeading noDivider>{heading}</GradientHeading>
                    <EnvironmentBadge />
                </div>
            </div>

            {isRecoveryForm && recoveryForm?.context_message && (
                <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-900/40 dark:bg-blue-950/30 dark:text-blue-100">
                    <div className="flex items-start gap-2">
                        <InformationCircleIcon className="mt-0.5 size-5 shrink-0" />
                        <p>{recoveryForm.context_message}</p>
                    </div>
                </div>
            )}

            {paymentUsesMock && mockTestCards.length > 0 && !isRecoveryForm && (
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

            {efevooConfig?.requires_3ds && (
                <div className="mb-6 space-y-3">
                    <div className="rounded-lg bg-blue-50 p-4 text-sm text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                        <div className="flex items-center gap-2">
                            <ShieldCheckIcon className="size-4" />
                            <span>Tu banco puede solicitar verificación adicional (3D Secure)</span>
                        </div>
                    </div>
                    <p className="text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                        EfevooPay puede generar dos verificaciones temporales de $1.50 MXN (GetLink y TokenCard),
                        hasta $3.00 MXN en total. Si permanecen reflejadas, comunícate con soporte.
                    </p>
                </div>
            )}

            {(errors.error || conflictAttempt) && (
                <div
                    className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-200"
                    role="alert"
                    aria-live="assertive"
                >
                    <p className="font-medium">No se pudo iniciar la verificación</p>
                    <p className="mt-1">{errors.error || "Ya tienes una verificación en proceso."}</p>
                    {conflictAttempt?.redirect_url && (
                        <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                            <Button href={conflictAttempt.redirect_url}>Continuar verificación activa</Button>
                            {conflictAttempt.result_url && (
                                <Button outline href={conflictAttempt.result_url}>
                                    Consultar intento activo
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            )}

            <form onSubmit={handleSubmit} className="max-w-2xl relative" autoComplete="off">
                {submitting && (
                    <div
                        className="absolute inset-0 z-50 flex flex-col items-center justify-center rounded-lg bg-white/80 backdrop-blur-sm dark:bg-black/60"
                        aria-live="polite"
                        aria-busy="true"
                    >
                        <div className="h-12 w-12 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
                        <p className="mt-4 font-medium">Preparando verificación segura...</p>
                        <p className="mt-1 text-sm text-gray-500">No cierres esta ventana</p>
                    </div>
                )}

                <div className="space-y-5">
                    <SimpleField>
                        <SimpleInput
                            label="Número de tarjeta"
                            value={formatCardNumber(data.card_number)}
                            autoComplete="off"
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

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Mes
                            </label>
                            <select
                                value={data.exp_month}
                                onChange={(e) => setData("exp_month", e.target.value)}
                                required
                                autoComplete="off"
                                className={`block w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 dark:bg-gray-800 dark:text-gray-100 ${
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
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Año
                            </label>
                            <select
                                value={data.exp_year}
                                onChange={(e) => setData("exp_year", e.target.value)}
                                required
                                autoComplete="off"
                                className={`block w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 dark:bg-gray-800 dark:text-gray-100 ${
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
                        </div>
                        <SimpleInput
                            label="CVV"
                            type="password"
                            value={data.cvv}
                            autoComplete="off"
                            onChange={(e) => setData("cvv", e.target.value.replace(/\D/g, ""))}
                            maxLength={4}
                            required
                        />
                    </div>

                    <SimpleInput
                        label="Nombre del titular"
                        value={data.card_holder}
                        autoComplete="off"
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
                        <span className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
                            Acepto los{" "}
                            <Link href={route("terms-of-service")} target="_blank" className="text-blue-600 underline">
                                términos y condiciones
                            </Link>
                        </span>
                    </div>

                    <div className="flex flex-col gap-3 pt-4 sm:flex-row">
                        <Button
                            type="submit"
                            disabled={processing || submitting}
                            className="w-full sm:flex-1"
                            aria-busy={processing || submitting}
                        >
                            {isRecoveryForm ? "Iniciar verificación" : "Guardar tarjeta"}
                        </Button>

                        <Button href={safeReturnHref} outline className="w-full sm:w-auto">
                            Cancelar
                        </Button>
                    </div>
                </div>
            </form>
        </SettingsLayout>
    );
}
