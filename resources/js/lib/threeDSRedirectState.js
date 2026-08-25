const CHALLENGE_STATUSES = new Set([
    "created",
    "initiating",
    "challenge_required",
    "pending",
    "redirect_required",
]);

const CONFIRMING_STATUSES = new Set(["authenticated", "approved"]);

const TOKENIZING_STATUSES = new Set(["tokenizing"]);

const COMPLETED_STATUSES = new Set(["completed", "card_verified"]);

const FAILED_STATUSES = new Set([
    "declined",
    "cancelled",
    "expired",
    "failed",
    "error",
    "technical_error",
    "tokenization_failed",
]);

const CONFIRMATION_PENDING_STATUSES = new Set([
    "unknown",
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
    return ["completed", "failed", "confirmation_pending"].includes(visualState);
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
            title: "Estamos guardando tu tarjeta de forma segura...",
            message: "La verificacion fue aprobada y estamos protegiendo los datos de tu tarjeta.",
        },
        completed: {
            title: "Tarjeta verificada correctamente",
            message: "Te llevaremos al resultado seguro.",
        },
        failed: {
            title: "No pudimos completar la verificacion",
            message: "Te llevaremos a la pantalla de resultado para continuar.",
        },
        confirmation_pending: {
            title: "Estamos confirmando el resultado de tu verificacion.",
            message: "Puedes actualizar el estado de forma segura.",
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
    };
}
