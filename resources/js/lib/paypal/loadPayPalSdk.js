const SCRIPT_SELECTOR =
    'script[data-famedic-paypal-sdk], script[src*="paypal.com/sdk/js"]';

/** @type {Map<string, Promise<object>>} */
const loadPromises = new Map();

export const DEFAULT_PAYPAL_SDK_CONFIG = {
    currency: "MXN",
    intent: "capture",
    locale: "es_MX",
};

/**
 * @param {{ clientId: string, currency?: string, intent?: string, locale?: string }} config
 */
export function buildPayPalSdkUrl(config) {
    const params = new URLSearchParams();
    params.set("client-id", config.clientId);
    params.set("currency", config.currency ?? DEFAULT_PAYPAL_SDK_CONFIG.currency);
    params.set("intent", config.intent ?? DEFAULT_PAYPAL_SDK_CONFIG.intent);

    const locale = config.locale ?? DEFAULT_PAYPAL_SDK_CONFIG.locale;
    if (locale) {
        params.set("locale", locale);
    }

    return `https://www.paypal.com/sdk/js?${params.toString()}`;
}

/**
 * URL pública sin client-id para auditoría.
 *
 * @param {{ clientId: string, currency?: string, intent?: string, locale?: string }} config
 */
export function buildPayPalSdkUrlForAudit(config) {
    const normalized = normalizeConfig(config);
    const params = new URLSearchParams();
    params.set("client-id", "[REDACTED]");
    params.set("currency", normalized.currency);
    params.set("intent", normalized.intent);
    if (normalized.locale) {
        params.set("locale", normalized.locale);
    }
    return `https://www.paypal.com/sdk/js?${params.toString()}`;
}

/**
 * @param {string} src
 * @returns {{ clientId: string, currency: string, intent: string, locale: string } | null}
 */
function parseSdkUrl(src) {
    try {
        const url = new URL(src);
        return {
            clientId: url.searchParams.get("client-id") ?? "",
            currency:
                url.searchParams.get("currency") ??
                DEFAULT_PAYPAL_SDK_CONFIG.currency,
            intent:
                url.searchParams.get("intent") ??
                DEFAULT_PAYPAL_SDK_CONFIG.intent,
            locale: url.searchParams.get("locale") ?? "",
        };
    } catch {
        return null;
    }
}

function normalizeConfig(config) {
    return {
        clientId: String(config.clientId ?? "").trim(),
        currency: config.currency ?? DEFAULT_PAYPAL_SDK_CONFIG.currency,
        intent: config.intent ?? DEFAULT_PAYPAL_SDK_CONFIG.intent,
        locale: config.locale ?? DEFAULT_PAYPAL_SDK_CONFIG.locale,
    };
}

function configsMatch(a, b) {
    return (
        a.clientId === b.clientId &&
        a.currency === b.currency &&
        a.intent === b.intent &&
        a.locale === b.locale
    );
}

function scriptLoadFailed(script) {
    return script.dataset.famedicPaypalLoadFailed === "true";
}

function markScriptFailed(script) {
    script.dataset.famedicPaypalLoadFailed = "true";
}

/**
 * Elimina únicamente scripts fallidos creados por este loader.
 * No toca scripts válidos en uso (p. ej. MedicalAttention).
 *
 * @param {HTMLScriptElement} script
 */
function removeFailedLoaderScript(script) {
    if (
        script.dataset.famedicPaypalSdk === "true" &&
        scriptLoadFailed(script)
    ) {
        script.remove();
    }
}

function waitForScriptLoad(script) {
    if (window.paypal) {
        return Promise.resolve(window.paypal);
    }

    if (scriptLoadFailed(script)) {
        return Promise.reject(new Error("No se pudo cargar el SDK de PayPal"));
    }

    return new Promise((resolve, reject) => {
        const onLoad = () => {
            cleanup();
            if (window.paypal) {
                resolve(window.paypal);
                return;
            }
            markScriptFailed(script);
            reject(
                new Error("PayPal SDK no disponible tras cargar el script"),
            );
        };
        const onError = () => {
            cleanup();
            markScriptFailed(script);
            reject(new Error("No se pudo cargar el SDK de PayPal"));
        };
        const cleanup = () => {
            script.removeEventListener("load", onLoad);
            script.removeEventListener("error", onError);
        };

        script.addEventListener("load", onLoad);
        script.addEventListener("error", onError);
    });
}

function findExistingScript() {
    return document.querySelector(SCRIPT_SELECTOR);
}

function insertScript(url) {
    return new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = url;
        script.async = true;
        script.dataset.famedicPaypalSdk = "true";
        script.onload = () => {
            if (!window.paypal) {
                markScriptFailed(script);
                reject(
                    new Error("PayPal SDK no disponible tras cargar el script"),
                );
                return;
            }
            resolve(window.paypal);
        };
        script.onerror = () => {
            markScriptFailed(script);
            reject(new Error("No se pudo cargar el SDK de PayPal"));
        };
        document.body.appendChild(script);
    });
}

/**
 * Carga el JavaScript SDK de PayPal una sola vez por configuración.
 *
 * @param {{ clientId: string, currency?: string, intent?: string, locale?: string }} config
 * @returns {Promise<object>}
 */
export function loadPayPalSdk(config) {
    const normalized = normalizeConfig(config);

    if (!normalized.clientId) {
        return Promise.reject(new Error("PayPal client ID no configurado"));
    }

    const url = buildPayPalSdkUrl(normalized);

    const cached = loadPromises.get(url);
    if (cached) {
        return cached;
    }

    const promise = (async () => {
        const existingScript = findExistingScript();

        if (existingScript) {
            const parsed = parseSdkUrl(existingScript.src);

            if (parsed && !configsMatch(parsed, normalized)) {
                throw new Error(
                    "PayPal SDK ya cargado con configuración incompatible",
                );
            }

            if (window.paypal) {
                return window.paypal;
            }

            if (scriptLoadFailed(existingScript)) {
                removeFailedLoaderScript(existingScript);
            } else {
                return waitForScriptLoad(existingScript);
            }
        }

        if (window.paypal) {
            return window.paypal;
        }

        return insertScript(url);
    })();

    loadPromises.set(url, promise);
    promise.catch(() => {
        loadPromises.delete(url);
    });

    return promise;
}
