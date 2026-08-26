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
import { scheduleThreeDSChallengeSubmit, THREE_DS_CHALLENGE_IFRAME_NAME } from "@/lib/threeDSChallengeSubmit";
import { observeThreeDS } from "@/lib/threeDSClientObservations";
import {
    elapsedSecondsFromClock,
    formatClockSeconds,
    isThreeDSTerminalVisualState,
    pollingResponseSummary,
    remainingSecondsFromClock,
    shouldNavigateFromThreeDSVisualState,
    shouldShowThreeDSIframe,
    shouldStopThreeDSPolling,
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
    const pollTimeoutRef = useRef(null);
    const pollInFlightRef = useRef(false);
    const pollingActiveRef = useRef(false);
    const navigationStartedRef = useRef(false);
    const clockReceivedAtRef = useRef(Date.now());
    const iframeMountedRef = useRef(false);

    const hasChallenge = Boolean(url3ds && token3ds);
    const resultUrl = route("payment-methods.3ds-result", { sessionId });
    const safeFallbackUrl = route("payment-methods.index");
    const initialStatus = authenticationAttempt?.status ?? (hasChallenge ? "pending" : null);
    const initialVisualState = threeDSVisualState(initialStatus, { hasChallenge });
    const clockServerNow = authenticationAttempt?.server_now ?? null;
    const clockExpiresAt = authenticationAttempt?.expires_at ?? null;
    const clockStartedAt = authenticationAttempt?.started_at ?? null;
    const supportReference = authenticationAttempt?.support_reference ?? sessionId;

    const [visualState, setVisualState] = useState(initialVisualState);
    const [message, setMessage] = useState(null);
    const [refreshing, setRefreshing] = useState(false);
    const [clockNow, setClockNow] = useState(() => Date.now());
    const [locallyExpired, setLocallyExpired] = useState(false);

    const showIframe = hasChallenge && shouldShowThreeDSIframe(visualState) && !locallyExpired;
    const copy = useMemo(
        () => threeDSCopyForVisualState(locallyExpired ? "expired" : visualState, message),
        [locallyExpired, visualState, message]
    );
    const elapsedSeconds = elapsedSecondsFromClock({
        startedAt: clockStartedAt,
        serverNow: clockServerNow,
        now: clockNow,
        receivedAt: clockReceivedAtRef.current,
    });
    const remainingSeconds = remainingSecondsFromClock({
        expiresAt: clockExpiresAt,
        serverNow: clockServerNow,
        now: clockNow,
        receivedAt: clockReceivedAtRef.current,
    });

    const stopPolling = useCallback(() => {
        pollingActiveRef.current = false;

        if (pollTimeoutRef.current) {
            window.clearTimeout(pollTimeoutRef.current);
            pollTimeoutRef.current = null;
            observeThreeDS("polling_stopped");
        }
    }, []);

    const navigateOnce = useCallback((target, delay = 0) => {
        if (navigationStartedRef.current) return;
        navigationStartedRef.current = true;

        window.setTimeout(() => {
            router.visit(target || safeFallbackUrl, { replace: true });
        }, delay);
    }, [safeFallbackUrl]);

    const previousVisualStateRef = useRef(initialVisualState);

    const applyStatus = useCallback((data) => {
        const summary = pollingResponseSummary(data);
        const nextVisualState = threeDSVisualState(summary.status, {
            hasChallenge,
            final: summary.final,
        });

        if (!shouldShowThreeDSIframe(nextVisualState) && shouldShowThreeDSIframe(previousVisualStateRef.current)) {
            observeThreeDS("challenge_ui_hidden", { reason: nextVisualState });
        }

        previousVisualStateRef.current = nextVisualState;
        setVisualState(nextVisualState);
        setMessage(summary.message);

        if (summary.final || shouldStopThreeDSPolling(nextVisualState)) {
            stopPolling();
        }

        if (shouldNavigateFromThreeDSVisualState(nextVisualState)) {
            navigateOnce(resultUrl, nextVisualState === "completed" ? 1200 : 800);
        }
    }, [hasChallenge, navigateOnce, resultUrl, stopPolling]);

    const pollStatus = useCallback(async () => {
        if (pollInFlightRef.current) {
            return null;
        }

        pollInFlightRef.current = true;

        try {
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
        } finally {
            pollInFlightRef.current = false;
        }
    }, [applyStatus, sessionId]);

    const scheduleNextPoll = useCallback(() => {
        if (!pollingActiveRef.current || pollInFlightRef.current) {
            return;
        }

        pollTimeoutRef.current = window.setTimeout(async () => {
            if (!pollingActiveRef.current) {
                return;
            }

            try {
                await pollStatus();
            } catch {
                setMessage("No pudimos consultar el estado. Te llevaremos al resultado seguro.");
                setVisualState("failed");
                stopPolling();
                navigateOnce(resultUrl, 800);
                return;
            }

            if (pollingActiveRef.current) {
                scheduleNextPoll();
            }
        }, POLLING_INTERVAL);
    }, [navigateOnce, pollStatus, resultUrl, stopPolling]);

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
        const timer = window.setInterval(() => {
            setClockNow(Date.now());
        }, 1000);

        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        if (locallyExpired) {
            return undefined;
        }

        if (remainingSeconds !== 0) {
            return undefined;
        }

        if (["confirming", "tokenizing", "completed", "failed", "confirmation_pending"].includes(visualState)) {
            return undefined;
        }

        setLocallyExpired(true);
        stopPolling();
        observeThreeDS("challenge_ui_hidden", { reason: "expired" });
        setVisualState((current) => (isThreeDSTerminalVisualState(current) ? current : "expired"));
        setMessage("El tiempo de esta verificacion termino. No se confirmo un resultado del banco.");

        return undefined;
    }, [locallyExpired, remainingSeconds, stopPolling, visualState]);

    useEffect(() => {
        if (!showIframe) {
            return undefined;
        }

        const cancel = scheduleThreeDSChallengeSubmit(
            {
                url: url3ds,
                token: token3ds,
                iframeName: THREE_DS_CHALLENGE_IFRAME_NAME,
                sessionId,
            },
            (result) => {
                observeThreeDS("challenge_submit_attempted", {
                    submitted: Boolean(result.submitted),
                    iframe_present: Boolean(result.iframe_present),
                    form_target_matches: Boolean(result.form_target_matches),
                    reason: result.reason || "submit",
                });
            }
        );

        return cancel;
    }, [showIframe, sessionId, token3ds, url3ds]);

    useEffect(() => {
        const currentShowIframe = hasChallenge && shouldShowThreeDSIframe(visualState);

        if (iframeMountedRef.current && !currentShowIframe) {
            observeThreeDS("challenge_ui_hidden", { reason: visualState });
        }

        iframeMountedRef.current = currentShowIframe;
    }, [hasChallenge, visualState]);

    useEffect(() => {
        const effectiveState = locallyExpired ? "expired" : visualState;

        if (shouldNavigateFromThreeDSVisualState(effectiveState)) {
            navigateOnce(resultUrl, effectiveState === "completed" ? 300 : 0);
            return undefined;
        }

        if (isThreeDSTerminalVisualState(effectiveState) || shouldStopThreeDSPolling(effectiveState)) {
            stopPolling();
            return undefined;
        }

        observeThreeDS("polling_started");
        pollingActiveRef.current = true;

        pollTimeoutRef.current = window.setTimeout(async () => {
            if (!pollingActiveRef.current) {
                return;
            }

            try {
                await pollStatus();
            } catch {
                setMessage("No pudimos consultar el estado. Te llevaremos al resultado seguro.");
                setVisualState("failed");
                stopPolling();
                navigateOnce(resultUrl, 800);
                return;
            }

            scheduleNextPoll();
        }, POLLING_DELAY);

        return () => {
            stopPolling();
        };
    }, [locallyExpired, navigateOnce, pollStatus, resultUrl, scheduleNextPoll, stopPolling, visualState]);

    const effectiveVisualState = locallyExpired ? "expired" : visualState;

    return (
        <SettingsLayout title="Verificacion de seguridad">
            <div
                id="three-ds-redirect-root"
                className="mx-auto flex w-full max-w-5xl scroll-mt-28 flex-col gap-8 px-1 pt-10 sm:px-0 sm:pt-8 lg:pt-6"
            >
                <div className="flex items-start gap-4">
                    <Button
                        href={route("payment-methods.index")}
                        outline
                        className="mt-1 size-10 shrink-0 p-0"
                    >
                        <ArrowLeftIcon />
                    </Button>
                    <div className="min-w-0">
                        <GradientHeading noDivider className="!text-3xl/[2.4rem] sm:!text-4xl/[3rem] lg:!text-5xl/[3.8rem]">
                            Verificacion segura 3D Secure
                        </GradientHeading>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                            La respuesta del banco se confirma de forma segura con FAMEDIC.
                        </p>
                    </div>
                </div>

                <div className={showIframe ? "grid gap-8 lg:grid-cols-[minmax(0,0.85fr)_minmax(420px,1.15fr)]" : "flex justify-center"}>
                    <StatusCard
                        visualState={effectiveVisualState}
                        copy={copy}
                        supportReference={supportReference}
                        showReference
                        refreshing={refreshing}
                        elapsed={elapsedSeconds}
                        remaining={remainingSeconds}
                        onRefresh={refreshStatus}
                        onSafeResult={() => navigateOnce(resultUrl)}
                    />

                    {hasChallenge && (
                        <div className={showIframe ? "overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-700" : "hidden"}>
                            <iframe
                                name={THREE_DS_CHALLENGE_IFRAME_NAME}
                                ref={iframeRef}
                                className="h-[620px] w-full bg-white"
                                title="3D Secure Challenge"
                                onLoad={() => observeThreeDS("iframe_load_observed")}
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
    supportReference,
    showReference,
    refreshing,
    elapsed,
    remaining,
    onRefresh,
    onSafeResult,
}) {
    const isProcessing = ["preparing", "confirming", "tokenizing"].includes(visualState);
    const isCompleted = visualState === "completed";
    const isFailed = visualState === "failed";
    const isChallenge = visualState === "challenge";
    const isConfirmationPending = visualState === "confirmation_pending";
    const isExpired = visualState === "expired";
    const showSpinner = isProcessing;
    const confirmationStopped = isConfirmationPending || isExpired;

    return (
        <section
            className="w-full max-w-2xl rounded-lg border border-zinc-200 bg-white p-6 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-8"
            aria-live="polite"
        >
            <div className="flex flex-col items-center">
                <div className="relative flex size-20 items-center justify-center">
                    {showSpinner && (
                        <div className="absolute inset-0 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
                    )}

                    {isCompleted ? (
                        <CheckCircleIcon className="size-16 text-green-600" aria-hidden="true" />
                    ) : isFailed || isExpired ? (
                        <ExclamationTriangleIcon className="size-16 text-amber-600" aria-hidden="true" />
                    ) : isChallenge ? (
                        <ShieldCheckIcon className="size-12 text-blue-600" aria-hidden="true" />
                    ) : confirmationStopped ? (
                        <ExclamationTriangleIcon className="size-16 text-amber-600" aria-hidden="true" />
                    ) : (
                        <ArrowPathIcon
                            className={`size-10 text-blue-600 ${showSpinner ? "animate-spin" : ""}`}
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

                <p className="mt-4 text-sm font-medium text-zinc-800 dark:text-zinc-100" aria-live="off">
                    Transcurrido {formatClockSeconds(elapsed)} · restante {formatClockSeconds(remaining)}
                </p>

                {visualState === "tokenizing" && (
                    <>
                        <p className="mt-2 text-sm font-medium text-green-700 dark:text-green-300">
                            Verificación aprobada
                        </p>
                        <p className="mt-2 text-sm font-medium text-blue-700 dark:text-blue-300">
                            Estamos guardando tu tarjeta…
                        </p>
                        {showReference && supportReference && (
                            <p className="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                                Referencia de soporte: {supportReference}
                            </p>
                        )}
                    </>
                )}

                {isCompleted && (
                    <p className="mt-2 text-sm font-medium text-green-700 dark:text-green-300">
                        Redirigiendo al resultado seguro.
                    </p>
                )}

                {isConfirmationPending && (
                    <div className="mt-6 flex w-full flex-col items-center gap-3 sm:flex-row sm:justify-center">
                        <Button
                            outline
                            className="w-full sm:w-auto"
                            onClick={onRefresh}
                            disabled={refreshing}
                            aria-busy={refreshing}
                        >
                            <ArrowPathIcon className={`size-4 ${refreshing ? "animate-spin" : ""}`} />
                            {refreshing ? "Actualizando..." : "Actualizar estado"}
                        </Button>
                        <Button className="w-full sm:w-auto" onClick={onSafeResult}>
                            Ver resultado seguro
                        </Button>
                    </div>
                )}

                {(isFailed || isExpired) && (
                    <Button className="mt-6 w-full sm:w-auto" onClick={onSafeResult}>
                        Ver resultado
                    </Button>
                )}

                <div className="mt-6 rounded-lg bg-blue-50 px-5 py-4 text-sm text-blue-800 dark:bg-blue-900/30 dark:text-blue-200">
                    <div className="flex items-center justify-center gap-2">
                        <LockClosedIcon className="size-4" aria-hidden="true" />
                        <span>
                            {confirmationStopped
                                ? "La confirmacion automatica se detuvo. No se hara otro intento solo."
                                : "No cierres esta ventana durante la verificacion."}
                        </span>
                    </div>
                </div>

                {showReference && (
                    <p className="mt-6 text-xs text-zinc-500 dark:text-zinc-400">
                        Referencia: {supportReference}
                    </p>
                )}
            </div>
        </section>
    );
}
