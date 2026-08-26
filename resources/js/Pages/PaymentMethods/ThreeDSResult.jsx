import SettingsLayout from "@/Layouts/SettingsLayout";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    ArrowPathIcon,
    CreditCardIcon,
    ClipboardDocumentIcon,
    ShieldCheckIcon,
} from "@heroicons/react/24/outline";
import { useEffect, useMemo, useState } from "react";
import { router } from "@inertiajs/react";
import {
    recoveryActionIsRenderable,
    returnActionIsRenderable,
    safeReturnLabel,
} from "@/lib/threeDSResultRecovery";
import { clearAttemptStorage, clearRecoveryAttemptIdentities } from "@/lib/paymentAuthAttemptIdentity";

function clearResultAttemptStorage(storageKey, recovery = false) {
    clearAttemptStorage(storageKey, { includeRecovery: recovery });
}

function formatCooldown(seconds) {
    if (seconds <= 0) return null;
    if (seconds < 60) return `${seconds} s`;

    return `${Math.ceil(seconds / 60)} min`;
}

export default function ThreeDSResult({
    sessionId,
    result,
    paymentAuthStorageKey = null,
    // Legacy fallbacks
    success = false,
    recoveryContext = null,
    returnUrl = null,
}) {
    const payload = result ?? buildLegacyResult({
        success,
        recoveryContext,
        returnUrl,
        sessionId,
    });

    const [liveResult, setLiveResult] = useState(payload);
    const [countdown, setCountdown] = useState(5);
    const [copyState, setCopyState] = useState("idle");
    const [recoveryLoading, setRecoveryLoading] = useState(null);
    const [refreshLoading, setRefreshLoading] = useState(false);
    const [statusMessage, setStatusMessage] = useState("");

    const presentation = liveResult.presentation;
    const copy = liveResult.copy ?? {};
    const recovery = liveResult.recovery;
    const isSuccess = liveResult.success || presentation === "completed";
    const redirectTarget =
        recovery?.return_action?.href ||
        returnUrl ||
        route("payment-methods.index");

    const showRecoveryActions =
        !isSuccess &&
        !["unknown", "authenticated", "tokenizing", "context_unavailable"].includes(presentation);

    const showRefresh =
        recovery?.actions?.refresh_status ||
        ["unknown", "authenticated", "tokenizing"].includes(presentation);

    const prioritizeDifferentCard = recovery?.prioritize_different_card ?? false;
    const cooldownLabel = formatCooldown(liveResult.cooldown_remaining_seconds ?? 0);
    const attemptsRemaining = Number(recovery?.attempts_remaining ?? liveResult.attempts_remaining ?? 0);
    const maximumAttempts = Number(recovery?.maximum_attempts ?? liveResult.maximum_attempts ?? 0);
    const reachedRecoveryLimit =
        recovery?.block_reason === "recovery_limit_reached" && attemptsRemaining <= 0;

    useEffect(() => {
        if (isSuccess) {
            clearResultAttemptStorage(paymentAuthStorageKey, true);
            return;
        }

        if (["declined", "cancelled", "expired", "technical_error", "tokenization_failed", "context_unavailable"].includes(presentation)) {
            clearRecoveryAttemptIdentities(paymentAuthStorageKey);
            clearResultAttemptStorage(paymentAuthStorageKey);
        }
    }, [isSuccess, presentation, paymentAuthStorageKey]);

    useEffect(() => {
        if (!isSuccess) return undefined;

        const interval = setInterval(() => {
            setCountdown((prev) => Math.max(0, prev - 1));
        }, 1000);

        const redirectTimer = setTimeout(() => {
            router.visit(redirectTarget);
        }, 5000);

        return () => {
            clearInterval(interval);
            clearTimeout(redirectTimer);
        };
    }, [isSuccess, redirectTarget]);

    const statusSummary = useMemo(() => {
        const labels = {
            declined: "No completada",
            cancelled: "Interrumpida",
            expired: "Expirada",
            technical_error: "Error técnico",
            tokenization_failed: "Guardado de tarjeta fallido",
            unknown: "En confirmación",
            provider_confirmation_pending: "Confirmación pendiente",
            authenticated: "Autenticada",
            tokenizing: "Guardando tarjeta",
            completed: "Completada",
            context_unavailable: "Contexto no disponible",
            processing: "En proceso",
        };

        return labels[presentation] ?? presentation;
    }, [presentation]);

    const startRecovery = (recoveryAction) => {
        if (!recovery?.recovery_start_url || !recovery?.context_uuid) {
            router.visit(route("payment-methods.create"));
            return;
        }

        clearRecoveryAttemptIdentities(paymentAuthStorageKey);
        clearResultAttemptStorage(paymentAuthStorageKey);

        setRecoveryLoading(recoveryAction);
        setStatusMessage("");

        router.post(
            recovery.recovery_start_url,
            {
                session_id: sessionId,
                recovery_context_uuid: recovery.context_uuid,
                recovery_action: recoveryAction,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setStatusMessage(errors.error || "No fue posible iniciar la recuperación.");
                },
                onFinish: () => setRecoveryLoading(null),
            }
        );
    };

    const startPayPalRecovery = () => {
        if (!recovery?.recovery_paypal_start_url || !recovery?.context_uuid) {
            return;
        }

        setRecoveryLoading("paypal");
        setStatusMessage("");

        router.post(
            recovery.recovery_paypal_start_url,
            {
                session_id: sessionId,
                recovery_context_uuid: recovery.context_uuid,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setStatusMessage(errors.error || "No fue posible preparar PayPal.");
                },
                onFinish: () => setRecoveryLoading(null),
            }
        );
    };

    const refreshStatus = async () => {
        const syncUrl = liveResult.status_sync_url || liveResult.status_refresh_url;
        if (!syncUrl) return;

        setRefreshLoading(true);
        setStatusMessage("");

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
            const isSync = Boolean(liveResult.status_sync_url);
            const response = await fetch(syncUrl, {
                method: isSync ? "POST" : "GET",
                headers: {
                    Accept: "application/json",
                    ...(isSync
                        ? {
                              "Content-Type": "application/json",
                              "X-CSRF-TOKEN": csrf,
                              "X-Requested-With": "XMLHttpRequest",
                          }
                        : {}),
                },
                ...(isSync ? { body: JSON.stringify({}) } : {}),
            });
            const data = await response.json();

            if (data.result) {
                setLiveResult(data.result);

                if (data.result.success) {
                    clearResultAttemptStorage(paymentAuthStorageKey, true);
                    router.visit(redirectTarget);
                }
            }
        } catch {
            setStatusMessage("No pudimos actualizar el estado. Intenta de nuevo.");
        } finally {
            setRefreshLoading(false);
        }
    };

    const copyReference = async () => {
        const reference = liveResult.support?.reference;

        if (!reference) return;

        try {
            await navigator.clipboard.writeText(reference);
            setCopyState("copied");
            setTimeout(() => setCopyState("idle"), 2000);
        } catch {
            setCopyState("failed");
        }
    };

    const iconTone = isSuccess
        ? "text-green-600"
        : ["unknown", "provider_confirmation_pending", "authenticated", "tokenizing"].includes(presentation)
          ? "text-amber-500"
          : "text-amber-600";

    return (
        <SettingsLayout title="Resultado de verificación">
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-1 sm:px-0">
                <div
                    className="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-8"
                    aria-live="polite"
                >
                    <div className="flex flex-col items-center text-center">
                        <div className={iconTone}>
                            {isSuccess ? (
                                <CheckCircleIcon className="size-16 sm:size-20" aria-hidden="true" />
                            ) : (
                                <ExclamationTriangleIcon className="size-16 sm:size-20" aria-hidden="true" />
                            )}
                        </div>

                        <h1 className="mt-5 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                            {copy.title}
                        </h1>

                        <p className="mt-3 max-w-lg text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                            {copy.message}
                        </p>

                        {copy.hint && (
                            <p className="mt-2 max-w-lg text-sm text-zinc-500 dark:text-zinc-400">
                                {copy.hint}
                            </p>
                        )}

                        <div className="mt-6 w-full rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-left dark:border-zinc-700 dark:bg-zinc-950/40">
                            <Text className="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                Resumen
                            </Text>
                            <div className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                                <p>
                                    <span className="text-zinc-500">Estado:</span>{" "}
                                    <span className="font-medium text-zinc-900 dark:text-zinc-100">{statusSummary}</span>
                                </p>
                                {liveResult.attempt_number && (
                                    <p>
                                        <span className="text-zinc-500">Intento:</span>{" "}
                                        <span className="font-medium">{liveResult.attempt_number} de {liveResult.maximum_attempts}</span>
                                    </p>
                                )}
                                {liveResult.card_last_four && (
                                    <p className="inline-flex items-center gap-2 sm:col-span-2">
                                        <CreditCardIcon className="size-4 text-zinc-400" aria-hidden="true" />
                                        <span>Tarjeta terminada en {liveResult.card_last_four}</span>
                                    </p>
                                )}
                            </div>
                        </div>

                        {!isSuccess && liveResult.verification_charge?.message && (
                            <p className="mt-4 max-w-lg text-sm text-zinc-600 dark:text-zinc-300">
                                {liveResult.verification_charge.message}
                            </p>
                        )}

                        {statusMessage && (
                            <p className="mt-4 text-sm text-red-600 dark:text-red-400" role="alert">
                                {statusMessage}
                            </p>
                        )}

                        {liveResult.active_attempt?.result_url && recovery?.block_reason === "active_attempt_exists" && (
                            <div className="mt-4 w-full rounded-xl border border-amber-200 bg-amber-50 p-4 text-left text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                                <p className="font-medium">Ya tienes una verificación en proceso</p>
                                <p className="mt-1">Puedes continuar con el intento activo o consultar su estado.</p>
                                <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                                    {liveResult.active_attempt.redirect_url && (
                                        <Button href={liveResult.active_attempt.redirect_url} className="w-full sm:w-auto">
                                            Continuar verificación
                                        </Button>
                                    )}
                                    <Button outline href={liveResult.active_attempt.result_url} className="w-full sm:w-auto">
                                        Ver intento activo
                                    </Button>
                                </div>
                            </div>
                        )}

                        {isSuccess && (
                            <div className="mt-8 w-full">
                                <div className="h-2 w-full overflow-hidden rounded-full bg-green-100 dark:bg-green-900/40">
                                    <div
                                        className="h-full bg-green-500 transition-all duration-1000"
                                        style={{ width: `${(5 - countdown) * 20}%` }}
                                        aria-hidden="true"
                                    />
                                </div>
                                <Text className="mt-3 text-sm text-green-700 dark:text-green-300">
                                    Redirigiendo en {countdown} segundos...
                                </Text>
                                <Button onClick={() => router.visit(redirectTarget)} className="mt-4 w-full sm:w-auto">
                                    Ir ahora
                                </Button>
                            </div>
                        )}

                        {showRefresh && (
                            <Button
                                outline
                                className="mt-6 w-full sm:w-auto"
                                onClick={refreshStatus}
                                disabled={refreshLoading}
                                aria-busy={refreshLoading}
                            >
                                <ArrowPathIcon className={`size-4 ${refreshLoading ? "animate-spin" : ""}`} />
                                {refreshLoading ? "Actualizando..." : "Actualizar estado"}
                            </Button>
                        )}

                        {showRecoveryActions && (
                            <div className="mt-6 flex w-full flex-col gap-3 sm:flex-row sm:flex-wrap sm:justify-center">
                                {recoveryActionIsRenderable("retry", recovery) && (
                                    <Button
                                        className={`w-full sm:w-auto ${prioritizeDifferentCard ? "order-2 sm:order-2" : "order-1 sm:order-1"}`}
                                        onClick={() => startRecovery("retry")}
                                        disabled={recoveryLoading !== null || (cooldownLabel && ["technical_error", "tokenization_failed"].includes(presentation))}
                                        aria-busy={recoveryLoading === "retry"}
                                    >
                                        {recoveryLoading === "retry" ? "Preparando..." : "Volver a intentar"}
                                    </Button>
                                )}

                                {recoveryActionIsRenderable("different_card", recovery) && (
                                    <Button
                                        className={`w-full sm:w-auto ${prioritizeDifferentCard ? "order-1 sm:order-1" : "order-2 sm:order-2"}`}
                                        outline={!prioritizeDifferentCard}
                                        onClick={() => startRecovery("different_card")}
                                        disabled={recoveryLoading !== null}
                                        aria-busy={recoveryLoading === "different_card"}
                                    >
                                        {recoveryLoading === "different_card" ? "Preparando..." : "Usar otra tarjeta"}
                                    </Button>
                                )}

                                {recoveryActionIsRenderable("paypal", recovery) && (
                                    <Button
                                        className={`w-full sm:w-auto ${prioritizeDifferentCard ? "order-2 sm:order-3" : "order-3 sm:order-3"}`}
                                        outline
                                        onClick={startPayPalRecovery}
                                        disabled={recoveryLoading !== null}
                                        aria-busy={recoveryLoading === "paypal"}
                                    >
                                        {recoveryLoading === "paypal" ? "Preparando PayPal..." : "Pagar con PayPal"}
                                    </Button>
                                )}

                                {returnActionIsRenderable(recovery) && (
                                    <Button outline href={recovery.return_action.href} className="order-4 w-full sm:w-auto">
                                        {safeReturnLabel(recovery.context_type)}
                                    </Button>
                                )}
                            </div>
                        )}

                        {recovery?.actions?.paypal && recoveryLoading === "paypal" && (
                            <p className="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
                                Continuarás mediante PayPal. FAMEDIC conservará el contexto de tu carrito.
                            </p>
                        )}

                        {cooldownLabel && presentation === "technical_error" && (
                            <p className="mt-3 text-sm text-zinc-500">
                                Podrás volver a intentar en {cooldownLabel}.
                            </p>
                        )}

                        {recovery?.block_reason === "cooldown_active" && cooldownLabel && (
                            <p className="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
                                Este intento está en enfriamiento temporal. No es un bloqueo por máximo de intentos.
                            </p>
                        )}

                        {reachedRecoveryLimit && (
                            <p className="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
                                Alcanzaste el máximo de {maximumAttempts || "los"} intentos permitidos. Comunícate con soporte o regresa más tarde.
                            </p>
                        )}

                        {presentation === "context_unavailable" && (
                            <Button href={route("payment-methods.index")} className="mt-6 w-full sm:w-auto">
                                Regresar
                            </Button>
                        )}
                    </div>
                </div>

                {liveResult.support?.reference && (
                    <div className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <Text className="font-medium">Referencia de soporte</Text>
                                <p className="mt-1 font-mono text-sm text-zinc-800 dark:text-zinc-200">
                                    {liveResult.support.reference}
                                </p>
                                <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    Comparte esta referencia para que podamos revisar tu intento.
                                </p>
                            </div>
                            <Button outline onClick={copyReference} className="w-full sm:w-auto">
                                <ClipboardDocumentIcon className="size-4" />
                                {copyState === "copied" ? "Copiado" : "Copiar referencia"}
                            </Button>
                        </div>
                        <p className="mt-4 text-xs text-zinc-500">
                            No compartas el número completo de tu tarjeta ni tu código de seguridad.
                        </p>
                        <p className="mt-2 text-sm">
                            Escríbenos a{" "}
                            <a
                                href={`mailto:${liveResult.support.email}?subject=Soporte%203DS%20${encodeURIComponent(liveResult.support.reference)}`}
                                className="font-medium text-blue-600 underline dark:text-blue-400"
                            >
                                {liveResult.support.channel_label}
                            </a>
                        </p>
                    </div>
                )}

                <div className="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div className="flex items-start gap-3">
                        <ShieldCheckIcon className="mt-0.5 size-5 text-zinc-500" aria-hidden="true" />
                        <div>
                            <Text className="font-medium">Seguridad 3D Secure</Text>
                            <Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                Es una verificación adicional para confirmar que eres el titular legítimo de la tarjeta.
                            </Text>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    );
}

function buildLegacyResult({ success, recoveryContext, returnUrl, sessionId }) {
    return {
        success,
        presentation: success ? "completed" : "declined",
        copy: {
            title: success ? "Tarjeta verificada correctamente" : "Verificación no completada",
            message: success
                ? "Tu tarjeta fue verificada correctamente."
                : "No se completó la verificación de tu tarjeta.",
            hint: null,
        },
        recovery: recoveryContext,
        support: {
            reference: recoveryContext?.support_reference ?? null,
            email: "soporte@famedic.com",
            channel_label: "soporte@famedic.com",
        },
        status_refresh_url: route("payment-methods.3ds-result-status", { sessionId }),
        status_sync_url: route("payment-methods.3ds-result-sync", { sessionId }),
        verification_charge: {
            message: "Puede aparecer una verificación temporal de seguridad. Si permanece reflejada, comunícate con soporte.",
        },
        redirectTarget: recoveryContext?.return_action?.href || returnUrl || route("payment-methods.index"),
    };
}
