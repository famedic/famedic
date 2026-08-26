const SENSITIVE_DETAIL_KEYS = ["token", "token3ds", "creq", "challenge", "pan", "cvv", "card_number"];

function sanitizeDetails(details = {}) {
    const sanitized = {};

    Object.entries(details).forEach(([key, value]) => {
        const normalized = String(key).toLowerCase();

        if (SENSITIVE_DETAIL_KEYS.some((sensitive) => normalized.includes(sensitive))) {
            return;
        }

        if (typeof value === "boolean" || typeof value === "number") {
            sanitized[key] = value;
            return;
        }

        if (typeof value === "string") {
            sanitized[key] = value.slice(0, 80);
        }
    });

    return sanitized;
}

export function observeThreeDS(event, details = {}) {
    const entry = {
        event,
        at: Date.now(),
        ...sanitizeDetails(details),
    };

    window.__FAMEDIC_3DS_OBSERVATIONS__ = window.__FAMEDIC_3DS_OBSERVATIONS__ || [];
    window.__FAMEDIC_3DS_OBSERVATIONS__.push(entry);

    const root = document.getElementById("three-ds-redirect-root");

    if (root) {
        root.dataset.lastObservation = event;

        if (details.reason) {
            root.dataset.hiddenReason = String(details.reason);
        }
    }

    return entry;
}

export function threeDSObservations() {
    return window.__FAMEDIC_3DS_OBSERVATIONS__ || [];
}
