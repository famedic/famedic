import { useState, useEffect, useRef, useCallback } from 'react';

const RECAPTCHA_SCRIPT_URL = 'https://www.google.com/recaptcha/api.js?render=explicit';
const SCRIPT_LOAD_TIMEOUT_MS = 15000;
const READY_TIMEOUT_MS = 10000;
const MAX_INIT_ATTEMPTS = 3;
const RETRY_DELAY_MS = 300;

/** @type {Promise<object> | null} */
let recaptchaScriptPromise = null;

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function devLog(message, error) {
    if (import.meta.env.DEV) {
        if (error) {
            console.error(`[useRecaptcha] ${message}`, error);
        } else {
            console.warn(`[useRecaptcha] ${message}`);
        }
    }
}

/**
 * Espera a que window.grecaptcha exista (p. ej. script en carga).
 * @param {number} timeoutMs
 * @returns {Promise<object>}
 */
function waitForGrecaptchaObject(timeoutMs) {
    return new Promise((resolve, reject) => {
        if (window.grecaptcha?.ready) {
            resolve(window.grecaptcha);
            return;
        }

        const start = Date.now();
        let settled = false;

        const intervalId = setInterval(() => {
            if (settled) {
                return;
            }

            if (window.grecaptcha?.ready) {
                settled = true;
                clearInterval(intervalId);
                clearTimeout(timeoutId);
                resolve(window.grecaptcha);
                return;
            }

            if (Date.now() - start >= timeoutMs) {
                settled = true;
                clearInterval(intervalId);
                clearTimeout(timeoutId);
                reject(new Error('Timeout esperando window.grecaptcha'));
            }
        }, 100);

        const timeoutId = setTimeout(() => {
            if (settled) {
                return;
            }
            settled = true;
            clearInterval(intervalId);
            reject(new Error('Timeout esperando window.grecaptcha'));
        }, timeoutMs);
    });
}

/**
 * Espera a que la API esté lista para render().
 * @param {number} timeoutMs
 * @returns {Promise<object>}
 */
function waitForGrecaptchaReady(timeoutMs) {
    return new Promise((resolve, reject) => {
        if (!window.grecaptcha?.ready) {
            reject(new Error('grecaptcha.ready no disponible'));
            return;
        }

        let settled = false;

        const timeoutId = setTimeout(() => {
            if (!settled) {
                settled = true;
                reject(new Error('Timeout esperando grecaptcha.ready'));
            }
        }, timeoutMs);

        window.grecaptcha.ready(() => {
            if (settled) {
                return;
            }
            settled = true;
            clearTimeout(timeoutId);
            resolve(window.grecaptcha);
        });
    });
}

/**
 * Carga compartida del script de reCAPTCHA v2 (explicit render).
 * Reutilizable entre montajes del hook.
 * @returns {Promise<object>}
 */
function loadRecaptchaScript() {
    if (!recaptchaScriptPromise) {
        recaptchaScriptPromise = (async () => {
            if (window.grecaptcha?.ready) {
                return waitForGrecaptchaReady(READY_TIMEOUT_MS);
            }

            const existingScript = document.querySelector('script[src*="google.com/recaptcha/api"]');

            if (!existingScript) {
                await new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = RECAPTCHA_SCRIPT_URL;
                    script.async = true;
                    script.defer = true;
                    script.dataset.recaptchaLoader = 'useRecaptcha';

                    let settled = false;

                    const timeoutId = setTimeout(() => {
                        if (settled) {
                            return;
                        }
                        settled = true;
                        script.remove();
                        reject(new Error('Timeout cargando script de reCAPTCHA'));
                    }, SCRIPT_LOAD_TIMEOUT_MS);

                    script.onload = () => {
                        if (settled) {
                            return;
                        }
                        settled = true;
                        clearTimeout(timeoutId);
                        resolve();
                    };

                    script.onerror = () => {
                        if (settled) {
                            return;
                        }
                        settled = true;
                        clearTimeout(timeoutId);
                        script.remove();
                        reject(new Error('Error cargando script de reCAPTCHA'));
                    };

                    document.head.appendChild(script);
                });
            } else {
                try {
                    await waitForGrecaptchaObject(SCRIPT_LOAD_TIMEOUT_MS);
                } catch (waitError) {
                    if (!window.grecaptcha?.ready) {
                        existingScript.remove();
                    }
                    throw waitError;
                }
            }

            if (window.grecaptcha?.ready) {
                return waitForGrecaptchaReady(READY_TIMEOUT_MS);
            }

            await waitForGrecaptchaObject(SCRIPT_LOAD_TIMEOUT_MS);
            return waitForGrecaptchaReady(READY_TIMEOUT_MS);
        })().catch((error) => {
            recaptchaScriptPromise = null;
            throw error;
        });
    }

    return recaptchaScriptPromise;
}

/**
 * Hook personalizado para manejar la lógica de Google reCAPTCHA v2
 * @param {string} siteKey - La clave del sitio de reCAPTCHA
 * @param {function} onTokenReceived - Callback cuando se recibe un token
 * @returns {Object} Estado y funciones del hook
 */
export function useRecaptcha(siteKey, onTokenReceived) {
    const [isLoaded, setIsLoaded] = useState(false);
    const [error, setError] = useState(false);
    const [token, setToken] = useState('');

    const recaptchaRef = useRef(null);
    const widgetId = useRef(null);
    const onTokenReceivedRef = useRef(onTokenReceived);
    const siteKeyRef = useRef(siteKey);
    const initGenerationRef = useRef(0);

    useEffect(() => {
        onTokenReceivedRef.current = onTokenReceived;
    }, [onTokenReceived]);

    useEffect(() => {
        siteKeyRef.current = siteKey;
    }, [siteKey]);

    const notifyToken = useCallback((newToken) => {
        setToken(newToken);
        onTokenReceivedRef.current?.(newToken);
    }, []);

    const clearToken = useCallback(() => {
        setToken('');
        onTokenReceivedRef.current?.('');
    }, []);

    const cleanupWidget = useCallback(() => {
        if (widgetId.current !== null && window.grecaptcha?.reset) {
            try {
                window.grecaptcha.reset(widgetId.current);
            } catch (cleanupError) {
                devLog('Error en reset del widget', cleanupError);
            }
        }

        widgetId.current = null;

        if (recaptchaRef.current) {
            recaptchaRef.current.innerHTML = '';
        }
    }, []);

    const renderWidget = useCallback((grecaptcha, generation) => {
        if (generation !== initGenerationRef.current) {
            return false;
        }

        if (!recaptchaRef.current) {
            return false;
        }

        if (widgetId.current !== null) {
            return true;
        }

        try {
            recaptchaRef.current.innerHTML = '';
            const container = document.createElement('div');
            recaptchaRef.current.appendChild(container);

            const newWidgetId = grecaptcha.render(container, {
                sitekey: siteKeyRef.current,
                callback: (newToken) => {
                    if (generation !== initGenerationRef.current || widgetId.current !== newWidgetId) {
                        return;
                    }
                    setError(false);
                    notifyToken(newToken);
                },
                'expired-callback': () => {
                    if (generation !== initGenerationRef.current || widgetId.current !== newWidgetId) {
                        return;
                    }
                    setError(true);
                    clearToken();
                },
                'error-callback': () => {
                    if (generation !== initGenerationRef.current || widgetId.current !== newWidgetId) {
                        return;
                    }
                    setError(true);
                    clearToken();
                },
                size: 'normal',
                theme: 'light',
                tabindex: 0,
            });

            if (generation !== initGenerationRef.current) {
                try {
                    grecaptcha.reset(newWidgetId);
                } catch (resetError) {
                    devLog('Error limpiando widget obsoleto', resetError);
                }
                widgetId.current = null;
                if (recaptchaRef.current) {
                    recaptchaRef.current.innerHTML = '';
                }
                return false;
            }

            widgetId.current = newWidgetId;
            setIsLoaded(true);
            setError(false);
            return true;
        } catch (renderError) {
            devLog('Error renderizando widget', renderError);
            if (generation === initGenerationRef.current) {
                widgetId.current = null;
                if (recaptchaRef.current) {
                    recaptchaRef.current.innerHTML = '';
                }
            }
            return false;
        }
    }, [notifyToken, clearToken]);

    const initializeWithRetries = useCallback(async (generation) => {
        for (let attempt = 1; attempt <= MAX_INIT_ATTEMPTS; attempt += 1) {
            if (generation !== initGenerationRef.current) {
                return false;
            }

            if (!recaptchaRef.current) {
                if (attempt < MAX_INIT_ATTEMPTS) {
                    await sleep(RETRY_DELAY_MS);
                    continue;
                }
                return false;
            }

            if (widgetId.current !== null) {
                return true;
            }

            try {
                const grecaptcha = await loadRecaptchaScript();

                if (generation !== initGenerationRef.current) {
                    return false;
                }

                const rendered = renderWidget(grecaptcha, generation);
                if (rendered) {
                    return true;
                }
            } catch (loadError) {
                devLog(`Intento ${attempt}/${MAX_INIT_ATTEMPTS} fallido`, loadError);
                if (generation === initGenerationRef.current) {
                    cleanupWidget();
                }
            }

            if (attempt < MAX_INIT_ATTEMPTS) {
                await sleep(RETRY_DELAY_MS);
            }
        }

        return false;
    }, [cleanupWidget, renderWidget]);

    useEffect(() => {
        const generation = initGenerationRef.current + 1;
        initGenerationRef.current = generation;

        setIsLoaded(false);
        setError(false);

        let cancelled = false;

        const run = async () => {
            const success = await initializeWithRetries(generation);

            if (cancelled || generation !== initGenerationRef.current) {
                return;
            }

            if (!success) {
                setIsLoaded(false);
                setError(true);
            }
        };

        run();

        return () => {
            cancelled = true;
            initGenerationRef.current += 1;
            cleanupWidget();
            clearToken();
            setIsLoaded(false);
        };
    }, [siteKey, initializeWithRetries, cleanupWidget, clearToken]);

    const reload = useCallback(async () => {
        const generation = initGenerationRef.current + 1;
        initGenerationRef.current = generation;

        setIsLoaded(false);
        setError(false);
        clearToken();
        cleanupWidget();

        const success = await initializeWithRetries(generation);

        if (generation !== initGenerationRef.current) {
            return;
        }

        if (!success) {
            setIsLoaded(false);
            setError(true);
        }
    }, [initializeWithRetries, cleanupWidget, clearToken]);

    const getToken = useCallback(() => {
        if (window.grecaptcha?.getResponse && widgetId.current !== null) {
            try {
                const directToken = window.grecaptcha.getResponse(widgetId.current);
                if (directToken) {
                    setToken(directToken);
                    return directToken;
                }
            } catch (getTokenError) {
                devLog('Error obteniendo token', getTokenError);
            }
        }
        return token;
    }, [token]);

    return {
        recaptchaRef,
        isLoaded,
        error,
        token,
        reload,
        getToken,
    };
}
