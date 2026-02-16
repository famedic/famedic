import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { useForm } from "@inertiajs/react";
import { ArrowLeftIcon, InformationCircleIcon, ExclamationTriangleIcon, ChevronDownIcon, ChevronUpIcon, LockClosedIcon, CreditCardIcon, ShieldCheckIcon } from "@heroicons/react/24/outline";
import { useState } from "react";
import CreditCardBrand from "@/Components/CreditCardBrand";
import SimpleField from "@/Components/Form/SimpleField";
import SimpleInput from "@/Components/Form/SimpleInput";
import { Link } from "@inertiajs/react"; // Asegúrate de importar Link

export default function Create({ efevooConfig = {}, hasPending3ds = false }) {
    const { data, setData, post, processing, errors } = useForm({
        card_number: "",
        exp_month: "",
        exp_year: "",
        exp_year_short: "",
        cvv: "",
        card_holder: "",
        alias: "",
        terms_accepted: false, // NUEVO: Campo para términos y condiciones
    });

    const [cardType, setCardType] = useState("");
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [showAliasTip, setShowAliasTip] = useState(false);
    const [showSecurityInfo, setShowSecurityInfo] = useState(false);

    // Detectar tipo de tarjeta y generar alias automático
    const detectCardType = (number) => {
        const cleaned = number.replace(/\D/g, "");
        let type = "";

        if (/^4/.test(cleaned)) type = "visa";
        else if (/^5[1-5]/.test(cleaned)) type = "mastercard";
        else if (/^3[47]/.test(cleaned)) type = "amex";
        else if (/^3(?:0[0-5]|[68])/.test(cleaned)) type = "diners";
        else if (/^6(?:011|5)/.test(cleaned)) type = "discover";
        else if (/^(?:2131|1800|35)/.test(cleaned)) type = "jcb";

        setCardType(type);

        // Generar alias automático cuando hay número de tarjeta y no hay alias personalizado
        if (cleaned.length >= 4 && !data.alias.trim()) {
            const lastFour = cleaned.slice(-4);
            const brand = type || "tarjeta";
            const generatedAlias = `${brand}-${lastFour}`;
            setData("alias", generatedAlias);
        }

        return type;
    };

    // Formatear número de tarjeta
    const formatCardNumber = (value) => {
        const cleaned = value.replace(/\D/g, "");
        const groups = cleaned.match(/.{1,4}/g);
        return groups ? groups.join(" ") : "";
    };

    // Generar alias basado en los datos de la tarjeta
    const generateAlias = () => {
        if (!data.card_number || data.card_number.length < 4) return;

        const lastFour = data.card_number.slice(-4);
        const brand = cardType || "tarjeta";
        const newAlias = `${brand}-${lastFour}`;

        setData("alias", newAlias);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        
        // Validación manual del checkbox (por si acaso)
        if (!data.terms_accepted) {
            // Podrías mostrar un error personalizado aquí si lo deseas
            return;
        }
        
        setIsSubmitting(true);

        // Asegurar que exp_year_short esté presente
        const formData = { ...data };
        if (!formData.exp_year_short && formData.exp_year) {
            formData.exp_year_short = formData.exp_year.slice(-2);
        }

        // Asegurar que exp_month tenga 2 dígitos
        if (formData.exp_month && formData.exp_month.length === 1) {
            formData.exp_month = `0${formData.exp_month}`;
        }

        // Si no hay alias, generar uno automáticamente
        if (!formData.alias.trim()) {
            const lastFour = formData.card_number ? formData.card_number.slice(-4) : '****';
            const brand = cardType || 'tarjeta';
            formData.alias = `${brand}-${lastFour}`;
        }

        post(route("payment-methods.store"), {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
        });
    };

    // Función para validar si la tarjeta está expirada
    const isCardExpired = (month, year) => {
        if (!month || !year || month.length !== 2 || year.length !== 2) {
            return false;
        }

        const currentDate = new Date();
        const currentYear = currentDate.getFullYear() % 100;
        const currentMonth = currentDate.getMonth() + 1;

        const expYear = parseInt(year, 10);
        const expMonth = parseInt(month, 10);

        return expYear < currentYear || (expYear === currentYear && expMonth < currentMonth);
    };

    // Si hay verificación pendiente, mostrar vista simplificada
    if (hasPending3ds) {
        return (
            <SettingsLayout title="Agregar tarjeta">
                <div className="flex items-center gap-4 mb-6">
                    <Button
                        href={route("payment-methods.index")}
                        outline
                        className="size-10 p-0"
                    >
                        <ArrowLeftIcon />
                    </Button>
                    <GradientHeading noDivider>Agregar nueva tarjeta</GradientHeading>
                </div>

                <div className="max-w-2xl">
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-900/20">
                        <div className="flex items-start gap-4">
                            <div className="rounded-full bg-amber-100 p-2 dark:bg-amber-800">
                                <ExclamationTriangleIcon className="size-6 text-amber-600 dark:text-amber-400" />
                            </div>
                            <div className="flex-1">
                                <h3 className="text-lg font-semibold text-amber-800 dark:text-amber-300">
                                    Verificación pendiente
                                </h3>
                                <p className="mt-1 text-amber-700 dark:text-amber-400">
                                    Tienes una verificación de tarjeta en proceso. Completa esa verificación antes de agregar una nueva tarjeta.
                                </p>
                                <Button
                                    href={route('payment-methods.index')}
                                    className="mt-4"
                                >
                                    Ver verificaciones pendientes
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        );
    }

    return (
        <SettingsLayout title="Agregar tarjeta">
            {/* Header con título compacto */}
            <div className="flex items-center gap-3 mb-6">
                <Button
                    href={route("payment-methods.index")}
                    outline
                    className="size-9 p-0"
                >
                    <ArrowLeftIcon className="size-4" />
                </Button>
                <GradientHeading noDivider className="text-xl">
                    Agregar nueva tarjeta
                </GradientHeading>
            </div>

            {/* Badge de seguridad (compacto) */}
            {efevooConfig?.requires_3ds && (
                <div className="mb-6 inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1.5 text-sm text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                    <ShieldCheckIcon className="size-4" />
                    <span>3D Secure activado</span>
                    <button
                        onClick={() => setShowSecurityInfo(!showSecurityInfo)}
                        className="ml-1 rounded-full p-0.5 hover:bg-blue-100 dark:hover:bg-blue-800"
                    >
                        {showSecurityInfo ? <ChevronUpIcon className="size-3.5" /> : <ChevronDownIcon className="size-3.5" />}
                    </button>
                </div>
            )}

            {/* Info de seguridad expandible */}
            {showSecurityInfo && efevooConfig?.requires_3ds && (
                <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50/50 p-4 text-sm dark:border-blue-800 dark:bg-blue-900/10">
                    <ul className="grid grid-cols-2 gap-2 text-blue-700 dark:text-blue-300">
                        <li className="flex items-center gap-2">
                            <span className="text-blue-500">✓</span> Código por SMS
                        </li>
                        <li className="flex items-center gap-2">
                            <span className="text-blue-500">✓</span> App bancaria
                        </li>
                        <li className="flex items-center gap-2">
                            <span className="text-blue-500">✓</span> Proceso rápido
                        </li>
                        <li className="flex items-center gap-2">
                            <span className="text-blue-500">✓</span> Una sola vez
                        </li>
                    </ul>
                </div>
            )}

            <form onSubmit={handleSubmit} className="max-w-2xl">
                <div className="space-y-5">
                    {/* Tarjeta preview (opcional - mejora visual) */}
                    {data.card_number && data.card_number.length >= 4 && (
                        <div className="relative overflow-hidden rounded-xl bg-gradient-to-r from-blue-600 to-blue-800 p-4 text-white shadow-lg">
                            <CreditCardIcon className="absolute right-4 top-4 size-8 opacity-20" />
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <span className="text-xs opacity-80">Tarjeta</span>
                                    {cardType && <CreditCardBrand brand={cardType} className="h-6 invert" />}
                                </div>
                                <p className="font-mono text-lg tracking-wider">
                                    •••• •••• •••• {data.card_number.slice(-4)}
                                </p>
                                <div className="flex gap-4 text-xs">
                                    <div>
                                        <span className="opacity-80">Titular</span>
                                        <p className="font-medium">{data.card_holder || "NOMBRE COMPLETO"}</p>
                                    </div>
                                    <div>
                                        <span className="opacity-80">Expira</span>
                                        <p className="font-medium">{data.exp_month || "MM"}/{data.exp_year || "AA"}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Número de tarjeta con indicador visual */}
                    <SimpleField>
                        <div className="flex items-center justify-between">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Número de tarjeta
                            </label>
                            {cardType && (
                                <div className="flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 dark:bg-gray-800">
                                    <CreditCardBrand brand={cardType} className="h-4" />
                                    <span className="text-xs capitalize text-gray-600 dark:text-gray-400">
                                        {cardType}
                                    </span>
                                </div>
                            )}
                        </div>
                        <div className="relative mt-1">
                            <SimpleInput
                                name="card_number"
                                value={formatCardNumber(data.card_number)}
                                onChange={(e) => {
                                    const raw = e.target.value.replace(/\D/g, "");
                                    if (raw.length <= 16) {
                                        setData("card_number", raw);
                                        detectCardType(raw);
                                    }
                                }}
                                placeholder="1234 5678 9012 3456"
                                maxLength={19}
                                required
                                autoComplete="cc-number"
                                error={errors.card_number}
                                className="pl-10"
                            />
                            <CreditCardIcon className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                        </div>
                    </SimpleField>

                    {/* Fila de fecha y CVV - más compacta */}
                    <div className="grid grid-cols-3 gap-3">
                        <SimpleField>
                            <SimpleInput
                                label="Mes"
                                name="exp_month"
                                value={data.exp_month}
                                onChange={(e) => {
                                    const value = e.target.value.replace(/\D/g, "");
                                    if (value.length <= 2) {
                                        setData("exp_month", value);
                                    }
                                }}
                                placeholder="MM"
                                maxLength={2}
                                required
                                autoComplete="cc-exp-month"
                                error={errors.exp_month}
                                className={isCardExpired(data.exp_month, data.exp_year) ? 'border-red-300' : ''}
                            />
                        </SimpleField>

                        <SimpleField>
                            <SimpleInput
                                label="Año"
                                name="exp_year"
                                value={data.exp_year}
                                onChange={(e) => {
                                    const value = e.target.value.replace(/\D/g, "");
                                    if (value.length <= 2) {
                                        setData("exp_year", value);
                                        setData("exp_year_short", value.slice(-2));
                                    }
                                }}
                                placeholder="AA"
                                maxLength={2}
                                required
                                autoComplete="cc-exp-year"
                                error={errors.exp_year}
                                className={isCardExpired(data.exp_month, data.exp_year) ? 'border-red-300' : ''}
                            />
                        </SimpleField>

                        <SimpleField>
                            <SimpleInput
                                label="CVV"
                                name="cvv"
                                type="password"
                                value={data.cvv}
                                onChange={(e) => {
                                    const value = e.target.value.replace(/\D/g, "");
                                    if (value.length <= 4) {
                                        setData("cvv", value);
                                    }
                                }}
                                placeholder="123"
                                maxLength={4}
                                required
                                autoComplete="cc-csc"
                                error={errors.cvv}
                            />
                        </SimpleField>
                    </div>

                    {/* Alerta de tarjeta expirada */}
                    {isCardExpired(data.exp_month, data.exp_year) && (
                        <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-600 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                            ⚠️ Esta tarjeta está expirada
                        </div>
                    )}

                    {/* Nombre del titular */}
                    <SimpleField>
                        <SimpleInput
                            label="Nombre del titular"
                            name="card_holder"
                            value={data.card_holder}
                            onChange={(e) => {
                                const value = e.target.value.toUpperCase().replace(/[^A-ZÁÉÍÓÚÑ\s]/g, "");
                                setData("card_holder", value);
                            }}
                            placeholder="Como aparece en la tarjeta"
                            maxLength={100}
                            required
                            autoComplete="cc-name"
                            error={errors.card_holder}
                        />
                    </SimpleField>

                    {/* Alias - versión compacta */}
                    <SimpleField>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Alias
                                </label>
                                <button
                                    type="button"
                                    onClick={() => setShowAliasTip(!showAliasTip)}
                                    className="text-blue-500 hover:text-blue-700"
                                >
                                    <InformationCircleIcon className="size-4" />
                                </button>
                            </div>
                            <button
                                type="button"
                                onClick={generateAlias}
                                className="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400"
                                disabled={!data.card_number || data.card_number.length < 4}
                            >
                                Generar alias
                            </button>
                        </div>

                        {showAliasTip && (
                            <div className="mb-2 rounded-lg bg-blue-50 p-2 text-xs text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                Nombre para identificar fácilmente esta tarjeta
                            </div>
                        )}

                        <SimpleInput
                            name="alias"
                            value={data.alias}
                            onChange={(e) => setData("alias", e.target.value)}
                            placeholder="Ej: Principal, Online, Emergencias"
                            maxLength={50}
                            autoComplete="off"
                            error={errors.alias}
                        />

                        {/* Sugerencias rápidas en chips */}
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {["Principal", "Online", "Emergencias", "Ahorros"].map((sug) => (
                                <button
                                    key={sug}
                                    type="button"
                                    onClick={() => setData("alias", `Tarjeta ${sug}`)}
                                    className="rounded-full bg-gray-100 px-2.5 py-1 text-xs hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700"
                                >
                                    {sug}
                                </button>
                            ))}
                        </div>
                    </SimpleField>

                    {/* Info importante en cards compactas */}
                    <div className="grid grid-cols-2 gap-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                        <div className="flex items-start gap-2">
                            <div className="rounded-full bg-blue-100 p-1 dark:bg-blue-900">
                                <LockClosedIcon className="size-3 text-blue-600 dark:text-blue-400" />
                            </div>
                            <div className="text-xs">
                                <p className="font-medium text-gray-700 dark:text-gray-300">Cargo de verificación</p>
                                <p className="text-gray-500 dark:text-gray-400">${efevooConfig.tokenization_amount} MXN</p>
                            </div>
                        </div>
                        <div className="flex items-start gap-2">
                            <div className="rounded-full bg-green-100 p-1 dark:bg-green-900">
                                <InformationCircleIcon className="size-3 text-green-600 dark:text-green-400" />
                            </div>
                            <div className="text-xs">
                                <p className="font-medium text-gray-700 dark:text-gray-300">Reembolso</p>
                                <p className="text-gray-500 dark:text-gray-400">24-48 horas</p>
                            </div>
                        </div>
                    </div>

                    {/* NUEVO: Checkbox de términos y condiciones */}
                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                        <div className="flex items-start gap-3">
                            <div className="flex h-5 items-center">
                                <input
                                    id="terms"
                                    name="terms_accepted"
                                    type="checkbox"
                                    checked={data.terms_accepted}
                                    onChange={(e) => setData("terms_accepted", e.target.checked)}
                                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:ring-offset-gray-800"
                                    required
                                />
                            </div>
                            <div className="flex-1 text-sm">
                                <label htmlFor="terms" className="font-medium text-gray-700 dark:text-gray-300">
                                    Acepto los términos y condiciones
                                </label>
                                <p className="mt-1 text-gray-500 dark:text-gray-400">
                                    He leído y acepto los{" "}
                                    <Link
                                        href={route("terms-of-service")}
                                        target="_blank"
                                        className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400"
                                    >
                                        Términos y Condiciones
                                    </Link>{" "}
                                    para el uso de esta tarjeta como método de pago.
                                </p>
                            </div>
                        </div>
                        {/* Mostrar error si existe */}
                        {errors.terms_accepted && (
                            <p className="mt-2 text-sm text-red-600 dark:text-red-400">
                                {errors.terms_accepted}
                            </p>
                        )}
                    </div>

                    {/* Botones fijos en la parte inferior */}
                    <div className="sticky bottom-0 -mx-6 bg-white px-6 py-4 dark:bg-gray-900">
                        <div className="flex gap-3">
                            <Button
                                type="submit"
                                disabled={processing || isSubmitting || isCardExpired(data.exp_month, data.exp_year)}
                                className="flex-1"
                            >
                                {(processing || isSubmitting) ? (
                                    <span className="flex items-center justify-center gap-2">
                                        <span className="size-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                                        Procesando...
                                    </span>
                                ) : (
                                    "Guardar tarjeta"
                                )}
                            </Button>
                            <Button
                                href={route("payment-methods.index")}
                                outline
                                disabled={processing || isSubmitting}
                            >
                                Cancelar
                            </Button>
                        </div>
                    </div>
                </div>
            </form>
        </SettingsLayout>
    );
}