// resources/js/Pages/PaymentMethods/Create.jsx
import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { useForm } from "@inertiajs/react";
import { ArrowLeftIcon, InformationCircleIcon } from "@heroicons/react/24/outline";
import { useState } from "react";
import CreditCardBrand from "@/Components/CreditCardBrand";
import SimpleField from "@/Components/Form/SimpleField";
import SimpleInput from "@/Components/Form/SimpleInput";

export default function Create({ efevooConfig }) {
    const { data, setData, post, processing, errors } = useForm({
        card_number: "",
        exp_month: "",
        exp_year: "",
        exp_year_short: "",
        cvv: "",
        card_holder: "",
        alias: "",
    });

    const [cardType, setCardType] = useState("");
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [showAliasTip, setShowAliasTip] = useState(false);

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

    return (
        <SettingsLayout title="Agregar tarjeta">
            <div className="flex items-center gap-4">
                <Button
                    href={route("payment-methods.index")}
                    outline
                    className="size-10 p-0"
                >
                    <ArrowLeftIcon />
                </Button>
                <GradientHeading noDivider>Agregar nueva tarjeta</GradientHeading>
            </div>

            <form onSubmit={handleSubmit} className="mt-8 max-w-2xl">
                <div className="space-y-8">
                    {/* Número de tarjeta */}
                    <SimpleField>
                        <div className="flex items-center justify-between mb-1">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Número de tarjeta
                            </label>
                            {cardType && (
                                <div className="flex items-center gap-2">
                                    <CreditCardBrand brand={cardType} />
                                    <Text className="text-sm capitalize text-zinc-500">
                                        {cardType}
                                    </Text>
                                </div>
                            )}
                        </div>
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
                        />
                    </SimpleField>

                    {/* Fecha de expiración y CVV */}
                    <div className="grid grid-cols-1 gap-8 sm:grid-cols-3">
                        <SimpleField>
                            <SimpleInput
                                label="Mes (MM)"
                                name="exp_month"
                                value={data.exp_month}
                                onChange={(e) => {
                                    const value = e.target.value.replace(/\D/g, "");
                                    if (value.length <= 2) {
                                        setData("exp_month", value);
                                    }
                                }}
                                placeholder="12"
                                maxLength={2}
                                required
                                autoComplete="cc-exp-month"
                                error={errors.exp_month}
                                className={isCardExpired(data.exp_month, data.exp_year) ? 'border-red-300' : ''}
                            />
                        </SimpleField>

                        <SimpleField>
                            <SimpleInput
                                label="Año (AA)"
                                name="exp_year"
                                value={data.exp_year}
                                onChange={(e) => {
                                    const value = e.target.value.replace(/\D/g, "");
                                    if (value.length <= 2) {
                                        setData("exp_year", value);
                                        setData("exp_year_short", value.slice(-2));
                                    }
                                }}
                                placeholder="28"
                                maxLength={2}
                                required
                                autoComplete="cc-exp-year"
                                error={errors.exp_year}
                                className={isCardExpired(data.exp_month, data.exp_year) ? 'border-red-300' : ''}
                            />
                            {isCardExpired(data.exp_month, data.exp_year) && !errors.exp_year && (
                                <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                    Esta tarjeta está expirada
                                </p>
                            )}
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

                    {/* Nombre del titular */}
                    <SimpleField>
                        <SimpleInput
                            label="Nombre del titular (como aparece en la tarjeta)"
                            name="card_holder"
                            value={data.card_holder}
                            onChange={(e) => {
                                const value = e.target.value.toUpperCase().replace(/[^A-ZÁÉÍÓÚÑ\s]/g, "");
                                setData("card_holder", value);
                            }}
                            placeholder="JUAN PEREZ LOPEZ"
                            maxLength={100}
                            required
                            autoComplete="cc-name"
                            error={errors.card_holder}
                        />
                    </SimpleField>

                    {/* Campo de Alias */}
                    <SimpleField>
                        <div className="flex items-center justify-between mb-1">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Alias para identificar esta tarjeta
                                <button
                                    type="button"
                                    onClick={() => setShowAliasTip(!showAliasTip)}
                                    className="ml-2 inline-flex items-center text-blue-500 hover:text-blue-700"
                                    title="¿Qué es un alias?"
                                >
                                    <InformationCircleIcon className="size-4" />
                                </button>
                            </label>
                            <button
                                type="button"
                                onClick={generateAlias}
                                className="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                disabled={!data.card_number || data.card_number.length < 4}
                            >
                                Generar automáticamente
                            </button>
                        </div>
                        
                        {showAliasTip && (
                            <div className="mb-3 rounded-lg bg-blue-50 p-3 text-sm text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                <p className="font-medium mb-1">¿Qué es un alias?</p>
                                <p className="text-sm">
                                    Un alias es un nombre personalizado para identificar fácilmente esta tarjeta.
                                    Ejemplos: "Tarjeta Principal", "Compras Online", "Emergencias"
                                </p>
                            </div>
                        )}

                        <SimpleInput
                            name="alias"
                            value={data.alias}
                            onChange={(e) => setData("alias", e.target.value)}
                            placeholder="Ej: Tarjeta principal, Compras online, etc."
                            maxLength={50}
                            autoComplete="off"
                            error={errors.alias}
                        />
                        
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-zinc-500">
                            <span className="text-xs">Ejemplos: </span>
                            <button
                                type="button"
                                onClick={() => setData("alias", "Tarjeta Principal")}
                                className="rounded-full bg-zinc-100 px-3 py-1 text-xs hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                            >
                                Tarjeta Principal
                            </button>
                            <button
                                type="button"
                                onClick={() => setData("alias", "Compras Online")}
                                className="rounded-full bg-zinc-100 px-3 py-1 text-xs hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                            >
                                Compras Online
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    const lastFour = data.card_number ? data.card_number.slice(-4) : '****';
                                    setData("alias", `${cardType || "Tarjeta"}-${lastFour}`);
                                }}
                                className="rounded-full bg-zinc-100 px-3 py-1 text-xs hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                            >
                                {cardType || "Tarjeta"}-{data.card_number ? data.card_number.slice(-4) : "****"}
                            </button>
                        </div>
                    </SimpleField>

                    {/* Información importante */}
                    <div className="rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20">
                        <div className="space-y-2">
                            <Text className="text-sm font-medium text-blue-700 dark:text-blue-300">
                                Información importante:
                            </Text>
                            <ul className="space-y-1 text-sm text-blue-600 dark:text-blue-400">
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>
                                        Se realizará un cargo de verificación de{" "}
                                        <strong>${efevooConfig.tokenization_amount} MXN</strong>
                                    </span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>Este cargo será reembolsado en 24-48 horas hábiles</span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>
                                        Ambiente actual:{" "}
                                        <strong className="uppercase">
                                            {efevooConfig.environment}
                                        </strong>
                                    </span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>Los datos de tu tarjeta se almacenan de forma segura y cifrada</span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <span className="mt-1">•</span>
                                    <span>
                                        El alias te ayudará a identificar esta tarjeta fácilmente en tu lista de métodos de pago
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    {/* Botones */}
                    <div className="flex gap-4">
                        <Button
                            type="submit"
                            disabled={processing || isSubmitting || isCardExpired(data.exp_month, data.exp_year)}
                            className="min-w-32"
                        >
                            {(processing || isSubmitting) ? (
                                <>
                                    <span className="mr-2">⏳</span>
                                    Procesando...
                                </>
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
            </form>
        </SettingsLayout>
    );
}