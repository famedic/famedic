import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import {
    ArrowLeftIcon,
    ArrowPathIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    LockClosedIcon,
    ShieldCheckIcon,
} from "@heroicons/react/24/outline";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import {
    isThreeDSTerminalVisualState,
    pollingResponseSummary,
    shouldNavigateFromThreeDSVisualState,
    shouldShowThreeDSIframe,
    threeDSCopyForVisualState,
    threeDSVisualState,
} from "@/lib/threeDSRedirectState";

const POLLING_DELAY = 5000;
const POLLING_INTERVAL = 5000;

export default function ThreeDSRedirect({
    sessionId,
    url3ds,
    token3ds,
    authenticationAttempt = null,
}) {
    const iframeRef = useRef(null);
    const intervalRef = useRef(null);
    const challengeSubmittedRef = useRef(false);
    const pollingStartedRef = useRef(false);
    const navigationStartedRef = useRef(false);

    const hasChallenge = Boolean(url3ds && token3ds);
    const resultUrl = route("payment-methods.3ds-result", { sessionId });
    const safeFallbackUrl = route("payment-methods.index");
    const initialStatus = authenticationAttempt?.status ?? (hasChallenge ? "pending" : null);
    const initialVisualState = threeDSVisualState(initialStatus, { hasChallenge });

    const [visualState, setVisualState] = useState(initialVisualState);
    const [message, setMessage] = useState(null);
    const [refreshing, setRefreshing] = useState(false);

    const showIframe = hasChallenge && shouldShowThreeDSIframe(visualState);
    const copy = useMemo(
        () => threeDSCopyForVisualState(visualState, message),
        [visualState, message]
    );

    const stopPolling = useCallback(() => {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
            intervalRef.current = null;
        }
    }, []);

    const navigateOnce = useCallback((target, delay = 0) => {
        if (navigationStartedRef.current) return;
        navigationStartedRef.current = true;

        window.setTimeout(() => {
            router.visit(target || safeFallbackUrl, { replace: true });
        }, delay);
    }, [safeFallbackUrl]);

    const applyStatus = useCallback((data) => {
        const summary = pollingResponseSummary(data);
        const nextVisualState = threeDSVisualState(summary.status, {
            hasChallenge,
            final: summary.final,
        });

        setVisualState(nextVisualState);
        setMessage(summary.message);

        if (summary.final || isThreeDSTerminalVisualState(nextVisualState)) {
            stopPolling();
        }

        if (shouldNavigateFromThreeDSVisualState(nextVisualState)) {
            navigateOnce(resultUrl, nextVisualState === "completed" ? 1200 : 800);
        }
    }, [hasChallenge, navigateOnce, resultUrl, stopPolling]);

    const pollStatus = useCallback(async () => {
        const response = await fetch(route("payment-methods.3ds-status", { sessionId }), {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        });

        if (!response.ok) {
            throw new Error("status_request_failed");
        }

        const data = await response.json();
        applyStatus(data);

        return data;
    }, [applyStatus, sessionId]);

    const refreshStatus = useCallback(async () => {
        setRefreshing(true);

        try {
            await pollStatus();
        } catch {
            setMessage("No pudimos actualizar el estado. Intenta de nuevo.");
        } finally {
            setRefreshing(false);
        }
    }, [pollStatus]);

    useEffect(() => {
        if (!showIframe) return;
        if (!iframeRef.current) return;
        if (challengeSubmittedRef.current) return;

        challengeSubmittedRef.current = true;

        const form = document.createElement("form");
        form.method = "POST";
        form.action = url3ds;
        form.target = "threeDSFrame";

        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "creq";
        input.value = token3ds;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }, [showIframe, token3ds, url3ds]);

    useEffect(() => {
        if (pollingStartedRef.current) return undefined;
        if (shouldNavigateFromThreeDSVisualState(visualState)) {
            navigateOnce(resultUrl, visualState === "completed" ? 300 : 0);
            return undefined;
        }

        pollingStartedRef.current = true;

        const delay = window.setTimeout(() => {
            pollStatus().catch(() => {
                setMessage("No pudimos consultar el estado. Te llevaremos al resultado seguro.");
                setVisualState("failed");
                stopPolling();
                navigateOnce(resultUrl, 800);
            });

            intervalRef.current = window.setInterval(() => {
                pollStatus().catch(() => {
                    setMessage("No pudimos consultar el estado. Te llevaremos al resultado seguro.");
                    setVisualState("failed");
                    stopPolling();
                    navigateOnce(resultUrl, 800);
                });
            }, POLLING_INTERVAL);
        }, POLLING_DELAY);

        return () => {
            window.clearTimeout(delay);
            stopPolling();
        };
    }, [navigateOnce, pollStatus, resultUrl, stopPolling, visualState]);

    return (
        <SettingsLayout title="Verificacion de seguridad">
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-1 pt-4 sm:px-0 sm:pt-6">
                <div className="flex items-start gap-4">
                    <Button
                        href={route("payment-methods.index")}
                        outline
                        className="mt-1 size-10 shrink-0 p-0"
                    >
                        <ArrowLeftIcon />
                    </Button>
                    <div className="min-w-0">
                        <GradientHeading noDivider>
                            Verificacion segura 3D Secure
                        </GradientHeading>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                            La respuesta del banco se confirma de forma segura con FAMEDIC.
                        </p>
                    </div>
                </div>

                <div className={showIframe ? "grid gap-8 lg:grid-cols-[minmax(0,0.85fr)_minmax(420px,1.15fr)]" : "flex justify-center"}>
                    <StatusCard
                        visualState={visualState}
                        copy={copy}
                        sessionId={sessionId}
                        showReference={!showIframe}
                        refreshing={refreshing}
                        onRefresh={refreshStatus}
                        onSafeResult={() => navigateOnce(resultUrl)}
                    />

                    {showIframe && (
                        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-700">
                            <iframe
                                name="threeDSFrame"
                                ref={iframeRef}
                                className="h-[620px] w-full bg-white"
                                title="3D Secure Challenge"
                            />
                        </div>
                    )}
                </div>
            </div>
        </SettingsLayout>
    );
}

function StatusCard({
    visualState,
    copy,
    sessionId,
    showReference,
    refreshing,
    onRefresh,
    onSafeResult,
}) {
    const isProcessing = ["preparing", "confirming", "tokenizing"].includes(visualState);
    const isCompleted = visualState === "completed";
    const isFailed = visualState === "failed";
    const isChallenge = visualState === "challenge";
    const isConfirmationPending = visualState === "confirmation_pending";

    return (
        <section
            className="w-full max-w-2xl rounded-lg border border-zinc-200 bg-white p-6 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-8"
            aria-live="polite"
        >
            <div className="flex flex-col items-center">
                <div className="relative flex size-20 items-center justify-center">
                    {isProcessing && (
                        <div className="absolute inset-0 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
                    )}

                    {isCompleted ? (
                        <CheckCircleIcon className="size-16 text-green-600" aria-hidden="true" />
                    ) : isFailed ? (
                        <ExclamationTriangleIcon className="size-16 text-amber-600" aria-hidden="true" />
                    ) : isChallenge ? (
                        <ShieldCheckIcon className="size-12 text-blue-600" aria-hidden="true" />
                    ) : (
                        <ArrowPathIcon
                            className={`size-10 text-blue-600 ${isProcessing ? "" : "animate-spin"}`}
                            aria-hidden="true"
                        />
                    )}
                </div>

                <h2 className="mt-6 text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                    {copy.title}
                </h2>

                <p className="mt-3 max-w-lg text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                    {copy.message}
                </p>

                {visualState === "tokenizing" && (
                    <p className="mt-2 text-sm font-medium text-blue-700 dark:text-blue-300">
                        Guardando tu tarjeta...
                    </p>
                )}

                {isCompleted && (
                    <p className="mt-2 text-sm font-medium text-green-700 dark:text-green-300">
                        Redirigiendo al resultado seguro.
                    </p>
                )}

                {isConfirmationPending && (
                    <Button
                        outline
                        className="mt-6 w-full sm:w-auto"
                        onClick={onRefresh}
                        disabled={refreshing}
                        aria-busy={refreshing}
                    >
                        <ArrowPathIcon className={`size-4 ${refreshing ? "animate-spin" : ""}`} />
                        {refreshing ? "Actualizando..." : "Actualizar estado"}
                    </Button>
                )}

                {isFailed && (
                    <Button className="mt-6 w-full sm:w-auto" onClick={onSafeResult}>
                        Ver resultado
                    </Button>
                )}

                <div className="mt-6 rounded-lg bg-blue-50 px-5 py-4 text-sm text-blue-800 dark:bg-blue-900/30 dark:text-blue-200">
                    <div className="flex items-center justify-center gap-2">
                        <LockClosedIcon className="size-4" aria-hidden="true" />
                        <span>No cierres esta ventana durante la verificacion.</span>
                    </div>
                </div>

                {showReference && (
                    <p className="mt-6 text-xs text-zinc-500 dark:text-zinc-400">
                        Referencia de sesion: {sessionId}
                    </p>
                )}
            </div>
        </section>
    );
}
