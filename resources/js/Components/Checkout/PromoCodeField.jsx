import { useState } from "react";
import axios from "axios";
import { Button } from "@/Components/Catalyst/button";
import { ErrorMessage, Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import { CheckCircleIcon, XMarkIcon } from "@heroicons/react/16/solid";

export default function PromoCodeField({
    laboratoryBrand,
    disabled = false,
    appliedPromo = null,
    onApplied,
    onCleared,
    error = null,
}) {
    const [code, setCode] = useState("");
    const [loading, setLoading] = useState(false);
    const [localError, setLocalError] = useState(null);

    const validateCode = async () => {
        const trimmed = code.trim();
        if (!trimmed) {
            setLocalError("Ingresa un código promocional.");
            return;
        }

        setLoading(true);
        setLocalError(null);

        try {
            const csrf =
                document.querySelector('meta[name="csrf-token"]')?.content ||
                "";
            const { data } = await axios.post(
                route("laboratory.checkout.promo-codes.validate", {
                    laboratory_brand: laboratoryBrand,
                }),
                { code: trimmed },
                {
                    headers: {
                        "X-CSRF-TOKEN": csrf,
                        "X-Requested-With": "XMLHttpRequest",
                        Accept: "application/json",
                    },
                },
            );

            onApplied?.({
                validation_token: data.validation_token,
                discount_cents: data.discount_cents,
                benefit_label: data.benefit_label,
                remaining_uses: data.remaining_uses,
                message: data.message,
            });
            setCode("");
        } catch (err) {
            const message =
                err.response?.data?.errors?.code?.[0] ||
                err.response?.data?.message ||
                "No se pudo validar el código.";
            setLocalError(message);
        } finally {
            setLoading(false);
        }
    };

    const clearPromo = async () => {
        if (!appliedPromo?.validation_token) {
            onCleared?.();
            return;
        }

        setLoading(true);
        setLocalError(null);

        try {
            const csrf =
                document.querySelector('meta[name="csrf-token"]')?.content ||
                "";
            await axios.delete(
                route("laboratory.checkout.promo-codes.destroy", {
                    laboratory_brand: laboratoryBrand,
                }),
                {
                    data: { validation_token: appliedPromo.validation_token },
                    headers: {
                        "X-CSRF-TOKEN": csrf,
                        "X-Requested-With": "XMLHttpRequest",
                        Accept: "application/json",
                    },
                },
            );
            onCleared?.();
        } catch {
            onCleared?.();
        } finally {
            setLoading(false);
        }
    };

    const displayError = error || localError;

    if (appliedPromo?.validation_token) {
        return (
            <div className="space-y-2 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-start gap-2">
                        <CheckCircleIcon className="mt-0.5 size-5 fill-emerald-600 dark:fill-famedic-lime" />
                        <div>
                            <Text className="font-medium text-emerald-900 dark:text-emerald-100">
                                {appliedPromo.message ||
                                    "Código promocional aplicado"}
                            </Text>
                            {appliedPromo.benefit_label && (
                                <Text className="text-sm text-emerald-800 dark:text-emerald-200">
                                    {appliedPromo.benefit_label}
                                </Text>
                            )}
                        </div>
                    </div>
                    <Button
                        type="button"
                        plain
                        disabled={disabled || loading}
                        onClick={clearPromo}
                        aria-label="Quitar código promocional"
                    >
                        <XMarkIcon className="size-5" />
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <Field className="space-y-2">
            <Label>Código promocional</Label>
            <div className="flex flex-col gap-2 sm:flex-row">
                <Input
                    value={code}
                    onChange={(e) => setCode(e.target.value.toUpperCase())}
                    placeholder="Ej. EVENTO2026"
                    disabled={disabled || loading}
                    onKeyDown={(e) => {
                        if (e.key === "Enter") {
                            e.preventDefault();
                            validateCode();
                        }
                    }}
                />
                <Button
                    type="button"
                    outline
                    disabled={disabled || loading}
                    onClick={validateCode}
                    className="shrink-0"
                >
                    {loading ? "Validando…" : "Validar código"}
                </Button>
            </div>
            {displayError && <ErrorMessage>{displayError}</ErrorMessage>}
        </Field>
    );
}
