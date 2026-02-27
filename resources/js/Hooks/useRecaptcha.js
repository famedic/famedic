import { useState, useEffect, useRef, useCallback } from 'react';

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
    const isMounted = useRef(true);

    // Limpiar el widget de reCAPTCHA
    const cleanup = useCallback(() => {
        if (widgetId.current !== null && window.grecaptcha?.reset) {
            try {
                window.grecaptcha.reset(widgetId.current);
            } catch (e) {
                // Ignorar errores en cleanup
            }
        }
    }, []);

    // Inicializar reCAPTCHA
    const initialize = useCallback(() => {
        if (!isMounted.current || !recaptchaRef.current || !window.grecaptcha) {
            return false;
        }

        try {
            // Limpiar contenedor
            if (recaptchaRef.current) {
                recaptchaRef.current.innerHTML = '';
            }

            // Crear contenedor único
            const containerId = `recaptcha-${Date.now()}`;
            const container = document.createElement('div');
            container.id = containerId;
            
            if (recaptchaRef.current) {
                recaptchaRef.current.appendChild(container);
            }

            // Renderizar reCAPTCHA
            widgetId.current = window.grecaptcha.render(containerId, {
                sitekey: siteKey,
                callback: (newToken) => {
                    if (isMounted.current) {
                        setToken(newToken);
                        setError(false);
                        if (onTokenReceived) {
                            onTokenReceived(newToken);
                        }
                    }
                },
                'expired-callback': () => {
                    if (isMounted.current) {
                        setToken('');
                        setError(true);
                        if (onTokenReceived) {
                            onTokenReceived('');
                        }
                    }
                },
                'error-callback': () => {
                    if (isMounted.current) {
                        setToken('');
                        setError(true);
                        if (onTokenReceived) {
                            onTokenReceived('');
                        }
                    }
                },
                size: 'normal',
                theme: 'light',
                tabindex: 0,
            });

            setIsLoaded(true);
            setError(false);
            return true;
        } catch (error) {
            console.error('Error inicializando reCAPTCHA:', error);
            if (isMounted.current) {
                setError(true);
            }
            return false;
        }
    }, [siteKey, onTokenReceived]);

    // Cargar script de reCAPTCHA
    const loadScript = useCallback(() => {
        return new Promise((resolve, reject) => {
            // Verificar si ya está cargado
            if (window.grecaptcha) {
                resolve();
                return;
            }

            // Verificar si ya hay un script cargándose
            const existingScript = document.querySelector('script[src*="google.com/recaptcha/api"]');
            if (existingScript) {
                const checkInterval = setInterval(() => {
                    if (window.grecaptcha) {
                        clearInterval(checkInterval);
                        resolve();
                    }
                }, 100);
                return;
            }

            // Crear nuevo script
            const script = document.createElement('script');
            script.src = `https://www.google.com/recaptcha/api.js?render=explicit`;
            script.async = true;
            script.defer = true;

            script.onload = () => {
                setTimeout(resolve, 300); // Pequeño delay para asegurar disponibilidad
            };

            script.onerror = () => {
                reject(new Error('Error cargando script de reCAPTCHA'));
            };

            document.head.appendChild(script);
        });
    }, []);

    // Inicializar
    useEffect(() => {
        isMounted.current = true;
        
        const init = async () => {
            try {
                await loadScript();
                if (isMounted.current) {
                    initialize();
                }
            } catch (err) {
                if (isMounted.current) {
                    setError(true);
                }
            }
        };

        const timer = setTimeout(init, 500);

        return () => {
            isMounted.current = false;
            clearTimeout(timer);
            cleanup();
        };
    }, [loadScript, initialize, cleanup]);

    // Recargar reCAPTCHA
    const reload = useCallback(() => {
        if (window.grecaptcha?.reset && widgetId.current !== null) {
            try {
                window.grecaptcha.reset(widgetId.current);
                setToken('');
                setError(false);
                if (onTokenReceived) {
                    onTokenReceived('');
                }
            } catch (e) {
                console.error('Error reseteando reCAPTCHA:', e);
                setError(true);
            }
        } else {
            // Forzar reinicialización
            setIsLoaded(false);
            setError(false);
            setToken('');
            
            if (recaptchaRef.current) {
                recaptchaRef.current.innerHTML = '';
            }
            
            setTimeout(() => {
                if (isMounted.current) {
                    initialize();
                }
            }, 300);
        }
    }, [initialize, onTokenReceived]);

    // Obtener token directamente (útil para validación antes de envío)
    const getToken = useCallback(() => {
        if (window.grecaptcha?.getResponse && widgetId.current !== null) {
            try {
                const directToken = window.grecaptcha.getResponse(widgetId.current);
                if (directToken) {
                    setToken(directToken);
                    return directToken;
                }
            } catch (error) {
                console.log('Error obteniendo token:', error);
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