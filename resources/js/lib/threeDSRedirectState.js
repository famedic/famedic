const CHALLENGE_STATUSES = new Set([
    "created",
    "initiating",
    "challenge_required",
    "pending",
    "redirect_required",
    "unknown",
]);

const CONFIRMING_STATUSES = new Set(["authenticated", "approved"]);

const TOKENIZING_STATUSES = new Set(["tokenizing"]);

const COMPLETED_STATUSES = new Set(["completed", "card_verified"]);

const FAILED_STATUSES = new Set([
    "declined",
    "rejected",
    "cancelled",
    "failed",
    "error",
    "technical_error",
    "tokenization_failed",
]);

const STOP_POLLING_VISUAL_STATES = new Set([
    "tokenizing",
    "completed",
    "failed",
    "confirmation_pending",
    "expired",
]);

const EXPIRED_STATUSES = new Set(["expired"]);

const CONFIRMATION_PENDING_STATUSES = new Set([
    "provider_confirmation_pending",
    "tokenization_confirmation_pending",
]);

export function normalizeThreeDSStatus(status) {
    return String(status || "").trim().toLowerCase();
}

export function threeDSVisualState(status, { hasChallenge = false, final = false } = {}) {
    const normalized = normalizeThreeDSStatus(status);

    if (!normalized) {
        return hasChallenge ? "challenge" : "preparing";
    }

    if (COMPLETED_STATUSES.has(normalized)) {
        return "completed";
    }

    if (EXPIRED_STATUSES.has(normalized)) {
        return "expired";
    }

    if (FAILED_STATUSES.has(normalized)) {
        return "failed";
    }

    if (CONFIRMATION_PENDING_STATUSES.has(normalized)) {
        return "confirmation_pending";
    }

    if (TOKENIZING_STATUSES.has(normalized)) {
        return "tokenizing";
    }

    if (CONFIRMING_STATUSES.has(normalized)) {
        return "confirming";
    }

    if (CHALLENGE_STATUSES.has(normalized)) {
        return hasChallenge ? "challenge" : "preparing";
    }

    if (final) {
        return "failed";
    }

    return hasChallenge ? "challenge" : "preparing";
}

export function shouldShowThreeDSIframe(visualState) {
    return visualState === "challenge";
}

export function isThreeDSTerminalVisualState(visualState) {
    return ["completed", "failed", "confirmation_pending", "expired"].includes(visualState);
}

export function shouldStopThreeDSPolling(visualState) {
    return STOP_POLLING_VISUAL_STATES.has(visualState) || isThreeDSTerminalVisualState(visualState);
}

export function shouldNavigateFromThreeDSVisualState(visualState) {
    return ["completed", "failed"].includes(visualState);
}

export function threeDSCopyForVisualState(visualState, message = null) {
    const copies = {
        preparing: {
            title: "Preparando verificacion segura...",
            message: "Estamos preparando la conexion segura.",
        },
        challenge: {
            title: "Completa la verificacion con tu banco.",
            message: "Sigue las instrucciones de tu banco para continuar.",
        },
        confirming: {
            title: "Verificacion aprobada",
            message: "Estamos confirmando el resultado...",
        },
        tokenizing: {
            title: "Verificación aprobada",
            message: "Estamos guardando tu tarjeta…",
        },
        completed: {
            title: "Tarjeta verificada correctamente",
            message: "Te llevaremos al resultado seguro.",
        },
        failed: {
            title: "No pudimos completar la verificacion",
            message: "Te llevaremos a la pantalla de resultado para continuar.",
        },
        expired: {
            title: "La verificacion ya no puede continuar",
            message: "El tiempo de esta verificacion termino. No se invento un resultado del banco. Puedes consultar el estado o salir de forma segura.",
        },
        confirmation_pending: {
            title: "No pudimos confirmar el resultado automaticamente",
            message: "No se realizara otro intento sin tu autorizacion. Conservamos el estado ambiguo hasta que consultes el resultado seguro.",
        },
    };

    return {
        ...copies[visualState],
        ...(message ? { message } : {}),
    };
}

export function pollingResponseSummary(data = {}) {
    const status = normalizeThreeDSStatus(data.status);
    const final = Boolean(data.final);

    return {
        status,
        final,
        visualState: threeDSVisualState(status, { final }),
        message: typeof data.message === "string" ? data.message : null,
        expiresAt: typeof data.expires_at === "string" ? data.expires_at : null,
        startedAt: typeof data.started_at === "string" ? data.started_at : null,
        serverNow: typeof data.server_now === "string" ? data.server_now : null,
        supportReference: typeof data.support_reference === "string" ? data.support_reference : null,
    };
}

export function remainingSecondsFromClock({ expiresAt, serverNow, now = Date.now(), receivedAt = null }) {
    if (!expiresAt || !serverNow) {
        return null;
    }

    const expiresMs = Date.parse(expiresAt);
    const serverMs = Date.parse(serverNow);

    if (Number.isNaN(expiresMs) || Number.isNaN(serverMs)) {
        return null;
    }

    const offset = (receivedAt ?? now) - serverMs;

    return Math.max(0, Math.floor((expiresMs - (now - offset)) / 1000));
}

export function elapsedSecondsFromClock({ startedAt, serverNow, now = Date.now(), receivedAt = null }) {
    if (!startedAt || !serverNow) {
        return null;
    }

    const startedMs = Date.parse(startedAt);
    const serverMs = Date.parse(serverNow);

    if (Number.isNaN(startedMs) || Number.isNaN(serverMs)) {
        return null;
    }

    const offset = (receivedAt ?? now) - serverMs;

    return Math.max(0, Math.floor(((now - offset) - startedMs) / 1000));
}

export function formatClockSeconds(totalSeconds) {
    if (totalSeconds === null || totalSeconds === undefined) {
        return "--:--";
    }

    const seconds = Math.max(0, Number(totalSeconds) || 0);
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;

    return `${String(minutes).padStart(2, "0")}:${String(rest).padStart(2, "0")}`;
}
