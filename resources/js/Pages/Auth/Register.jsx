import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { router, useForm } from "@inertiajs/react";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Heading } from "@/Components/Catalyst/heading";
import { Anchor, Text, TextLink } from "@/Components/Catalyst/text";
import OdessaLinkingMessage from "@/Components/Auth/OdessaLinkingMessage";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { useState, useEffect, useRef, useCallback } from "react";
import { EyeIcon, EyeSlashIcon } from "@heroicons/react/20/solid";

export default function Register({
    genders,
    inviter = null,
    odessaToken = null,
    secondsLeft = 0,
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: "",
        paternal_lastname: "",
        maternal_lastname: "",
        birth_date: "",
        gender: "",
        email: "",
        phone: "",
        phone_country: "MX",
        password: "",
        password_confirmation: "",
        referrer_id: inviter?.id || null,
        g_recaptcha_response: "",
    });

    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [recaptchaLoaded, setRecaptchaLoaded] = useState(false);
    const [recaptchaError, setRecaptchaError] = useState(false);
    const recaptchaRef = useRef(null);
    const recaptchaWidgetId = useRef(null);
    const recaptchaInitialized = useRef(false);
    const cleanupRef = useRef(null);
    const retryTimeoutRef = useRef(null);
    const retryCountRef = useRef(0);
    const maxRetries = 3;

    // Clave de reCAPTCHA
    const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY ||
        '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';

    // DEBUG: Log cuando cambia el token
    useEffect(() => {
        console.log('🔍 reCAPTCHA token actualizado:', {
            token: data.g_recaptcha_response,
            length: data.g_recaptcha_response?.length || 0,
            preview: data.g_recaptcha_response ? data.g_recaptcha_response.substring(0, 50) + '...' : 'empty'
        });
    }, [data.g_recaptcha_response]);

    // Función para limpiar reCAPTCHA completamente
    const cleanupRecaptcha = useCallback(() => {
        console.log('🧹 Limpiando reCAPTCHA...');
        
        // Limpiar timeout de reintento
        if (retryTimeoutRef.current) {
            clearTimeout(retryTimeoutRef.current);
            retryTimeoutRef.current = null;
        }

        // Resetear widget si existe
        if (recaptchaWidgetId.current !== null && window.grecaptcha && window.grecaptcha.reset) {
            try {
                console.log('🔄 Reseteando widget:', recaptchaWidgetId.current);
                window.grecaptcha.reset(recaptchaWidgetId.current);
            } catch (e) {
                console.log('⚠️ Error reseteando reCAPTCHA:', e);
            }
        }

        // Limpiar contenedor
        if (recaptchaRef.current) {
            recaptchaRef.current.innerHTML = '';
            console.log('✅ Contenedor limpiado');
        }

        // Resetear estados
        recaptchaWidgetId.current = null;
        recaptchaInitialized.current = false;
        setRecaptchaLoaded(false);
        setRecaptchaError(false);
        setData('g_recaptcha_response', '');
        retryCountRef.current = 0;
    }, [setData]);

    // Cargar reCAPTCHA
    useEffect(() => {
        console.log('🔄 Iniciando carga de reCAPTCHA...');

        // Limpiar cualquier reCAPTCHA anterior
        cleanupRecaptcha();

        const loadRecaptcha = () => {
            // Si ya está cargado, renderizar
            if (window.grecaptcha && window.grecaptcha.render) {
                console.log('✅ reCAPTCHA ya está en window');
                initializeRecaptcha();
                return;
            }

            console.log('📥 Cargando script de reCAPTCHA...');

            // Verificar si ya existe el script
            const existingScript = document.querySelector('script[src*="google.com/recaptcha/api"]');
            if (existingScript) {
                console.log('📜 Script ya existe en el DOM, removiendo...');
                existingScript.remove();
            }

            // Limpiar cualquier callback global existente
            if (window.onRecaptchaLoaded) {
                delete window.onRecaptchaLoaded;
            }

            // Crear y cargar script
            const script = document.createElement('script');
            script.src = `https://www.google.com/recaptcha/api.js?render=explicit`;
            script.async = true;
            script.defer = true;
            
            // Manejar carga exitosa
            script.onload = () => {
                console.log('✅ Script de reCAPTCHA cargado');
                // Pequeño delay para asegurar que grecaptcha esté disponible
                setTimeout(() => {
                    if (window.grecaptcha && window.grecaptcha.render) {
                        initializeRecaptcha();
                    } else {
                        console.error('❌ grecaptcha no disponible después de cargar script');
                        setRecaptchaError(true);
                    }
                }, 300);
            };

            script.onerror = (error) => {
                console.error('❌ Error cargando script de reCAPTCHA:', error);
                setRecaptchaError(true);
                // Reintentar después de un segundo si no hemos excedido los intentos
                if (retryCountRef.current < maxRetries) {
                    retryCountRef.current++;
                    console.log(`🔄 Reintentando carga de script (intento ${retryCountRef.current}/${maxRetries})...`);
                    retryTimeoutRef.current = setTimeout(() => {
                        if (!recaptchaInitialized.current) {
                            loadRecaptcha();
                        }
                    }, 1000);
                }
            };

            document.head.appendChild(script);
        };

        // Pequeño delay antes de cargar para evitar conflictos con navegación SPA
        const timer = setTimeout(loadRecaptcha, 100);

        return () => {
            clearTimeout(timer);
            cleanupRecaptcha();
        };
    }, [cleanupRecaptcha]);

    // Inicializar reCAPTCHA
    const initializeRecaptcha = useCallback(() => {
        console.log('🎯 Inicializando reCAPTCHA...');
        
        if (recaptchaInitialized.current) {
            console.log('⚠️ reCAPTCHA ya fue inicializado');
            return;
        }

        if (!window.grecaptcha || !window.grecaptcha.render) {
            console.error('❌ grecaptcha no disponible para inicializar');
            setRecaptchaError(true);
            
            // Reintentar después de un segundo si no hemos excedido los intentos
            if (retryCountRef.current < maxRetries) {
                retryCountRef.current++;
                console.log(`🔄 Reintentando inicialización (intento ${retryCountRef.current}/${maxRetries})...`);
                retryTimeoutRef.current = setTimeout(() => {
                    if (!recaptchaInitialized.current) {
                        initializeRecaptcha();
                    }
                }, 1000);
            }
            return;
        }

        if (!recaptchaRef.current) {
            console.error('❌ Elemento de referencia no disponible');
            setRecaptchaError(true);
            return;
        }

        try {
            // Marcar como inicializado
            recaptchaInitialized.current = true;
            
            // Limpiar el contenedor
            if (recaptchaRef.current) {
                recaptchaRef.current.innerHTML = '';
                console.log('🧹 Contenedor limpiado para inicialización');
            }

            // Crear un nuevo elemento div para el widget
            const widgetContainer = document.createElement('div');
            widgetContainer.id = 'recaptcha-widget-' + Date.now();
            recaptchaRef.current.appendChild(widgetContainer);

            console.log('🖌️ Renderizando widget de reCAPTCHA en:', widgetContainer.id);
            
            // Pequeño delay para asegurar que el DOM esté listo
            setTimeout(() => {
                try {
                    recaptchaWidgetId.current = window.grecaptcha.render(widgetContainer.id, {
                        sitekey: recaptchaSiteKey,
                        callback: onRecaptchaVerify,
                        'expired-callback': onRecaptchaExpired,
                        'error-callback': onRecaptchaError,
                        size: 'normal',
                        theme: 'light',
                        tabindex: 0,
                    });
                    
                    console.log('✅ Widget renderizado con ID:', recaptchaWidgetId.current);
                    setRecaptchaLoaded(true);
                    setRecaptchaError(false);
                    
                    // Verificar si ya hay un token después de un breve momento
                    setTimeout(() => {
                        if (window.grecaptcha && window.grecaptcha.getResponse && recaptchaWidgetId.current !== null) {
                            try {
                                const existingToken = window.grecaptcha.getResponse(recaptchaWidgetId.current);
                                if (existingToken) {
                                    console.log('🔍 Token existente encontrado:', existingToken.substring(0, 50) + '...');
                                    setData('g_recaptcha_response', existingToken);
                                }
                            } catch (e) {
                                console.log('⚠️ Error obteniendo token existente:', e);
                            }
                        }
                    }, 300);
                    
                } catch (error) {
                    console.error('💥 Error renderizando reCAPTCHA:', error);
                    recaptchaInitialized.current = false;
                    setRecaptchaError(true);
                    
                    // Reintentar después de un segundo si no hemos excedido los intentos
                    if (retryCountRef.current < maxRetries) {
                        retryCountRef.current++;
                        console.log(`🔄 Reintentando renderizado (intento ${retryCountRef.current}/${maxRetries})...`);
                        retryTimeoutRef.current = setTimeout(() => {
                            if (!recaptchaInitialized.current) {
                                initializeRecaptcha();
                            }
                        }, 1000);
                    }
                }
            }, 50);
            
        } catch (error) {
            console.error('💥 Error inicializando reCAPTCHA:', error);
            recaptchaInitialized.current = false;
            setRecaptchaError(true);
            
            // Reintentar después de un segundo si no hemos excedido los intentos
            if (retryCountRef.current < maxRetries) {
                retryCountRef.current++;
                console.log(`🔄 Reintentando inicialización (intento ${retryCountRef.current}/${maxRetries})...`);
                retryTimeoutRef.current = setTimeout(() => {
                    if (!recaptchaInitialized.current) {
                        initializeRecaptcha();
                    }
                }, 1000);
            }
        }
    }, [recaptchaSiteKey, setData]);

    // Función para manejar verificación de reCAPTCHA
    const onRecaptchaVerify = useCallback((token) => {
        console.log('✅ reCAPTCHA verificado! Token recibido:', {
            token: token,
            length: token.length,
            preview: token.substring(0, 50) + '...'
        });
        setData('g_recaptcha_response', token);
        setRecaptchaError(false);
    }, [setData]);

    const onRecaptchaExpired = useCallback(() => {
        console.log('⏰ reCAPTCHA expirado');
        setData('g_recaptcha_response', '');
        setRecaptchaError(true);
    }, [setData]);

    const onRecaptchaError = useCallback(() => {
        console.error('❌ Error en reCAPTCHA');
        setData('g_recaptcha_response', '');
        setRecaptchaError(true);
    }, [setData]);

    // Efecto para manejar visibilidad de la página
    useEffect(() => {
        const handleVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                // Si la página se vuelve visible y reCAPTCHA no está cargado, reintentar
                if (!recaptchaLoaded && !recaptchaInitialized.current) {
                    console.log('👀 Página visible, verificando reCAPTCHA...');
                    setTimeout(() => {
                        if (!recaptchaInitialized.current) {
                            console.log('🔄 Reintentando carga de reCAPTCHA después de visibilidad...');
                            cleanupRecaptcha();
                            retryTimeoutRef.current = setTimeout(() => {
                                initializeRecaptcha();
                            }, 300);
                        }
                    }, 500);
                }
            }
        };

        document.addEventListener('visibilitychange', handleVisibilityChange);

        return () => {
            document.removeEventListener('visibilitychange', handleVisibilityChange);
        };
    }, [recaptchaLoaded, cleanupRecaptcha, initializeRecaptcha]);

    // Función para formatear número de teléfono mexicano
    const formatMexicanPhone = (value) => {
        const numbers = value.replace(/\D/g, '');
        
        if (numbers.length <= 3) {
            return numbers;
        } else if (numbers.length <= 6) {
            return `${numbers.slice(0, 3)} ${numbers.slice(3)}`;
        } else if (numbers.length <= 10) {
            return `${numbers.slice(0, 3)} ${numbers.slice(3, 6)} ${numbers.slice(6, 10)}`;
        } else {
            return `${numbers.slice(0, 3)} ${numbers.slice(3, 6)} ${numbers.slice(6, 10)}`;
        }
    };

    const handlePhoneChange = (e) => {
        const formatted = formatMexicanPhone(e.target.value);
        setData("phone", formatted);
    };

    const submit = (e) => {
        e.preventDefault();

        console.log('🚀 Iniciando envío del formulario...');
        console.log('📦 Datos a enviar:', {
            ...data,
            password: '***',
            password_confirmation: '***',
            g_recaptcha_response_preview: data.g_recaptcha_response ?
                data.g_recaptcha_response.substring(0, 50) + '...' :
                'empty',
            g_recaptcha_response_length: data.g_recaptcha_response?.length || 0
        });

        // Validar reCAPTCHA
        if (!data.g_recaptcha_response) {
            console.error('❌ Error: Token de reCAPTCHA vacío');
            
            // Intentar obtener el token directamente
            if (window.grecaptcha && recaptchaWidgetId.current !== null) {
                try {
                    const directToken = window.grecaptcha.getResponse(recaptchaWidgetId.current);
                    if (directToken) {
                        console.log('🔍 Token obtenido directamente al validar:', directToken.substring(0, 50) + '...');
                        setData('g_recaptcha_response', directToken);
                        
                        // Reintentar envío después de actualizar el token
                        setTimeout(() => {
                            submit(e);
                        }, 100);
                        return;
                    }
                } catch (e) {
                    console.log('⚠️ Error obteniendo token directamente en validación:', e);
                }
            }
            
            alert('Por favor, verifica que no eres un robot completando el reCAPTCHA');
            setRecaptchaError(true);
            return;
        }

        console.log('✅ Token de reCAPTCHA presente, procediendo con envío...');

        if (!processing) {
            if (odessaToken) {
                console.log('🔗 Enviando registro con token Odessa...');
                post(
                    route("odessa-register.store", {
                        odessa_token: odessaToken,
                    }),
                    {
                        onFinish: () => {
                            console.log('✅ Registro Odessa completado');
                            reset("password", "password_confirmation");
                        },
                        onError: (errors) => {
                            console.error('❌ Error en registro Odessa:', errors);
                            if (errors.g_recaptcha_response) {
                                console.error('❌ Error específico de reCAPTCHA:', errors.g_recaptcha_response);
                                cleanupRecaptcha();
                                setTimeout(() => {
                                    initializeRecaptcha();
                                }, 500);
                            }
                        }
                    },
                );
            } else {
                console.log('👤 Enviando registro regular...');
                post(route("register"), {
                    onSuccess: () => {
                        console.log('✅ Registro exitoso');
                    },
                    onFinish: () => {
                        console.log('✅ Proceso de registro completado');
                        reset("password", "password_confirmation");
                        cleanupRecaptcha();
                    },
                    onError: (errors) => {
                        console.error('❌ Error en registro:', errors);
                        if (errors.g_recaptcha_response) {
                            console.error('❌ Error de reCAPTCHA en respuesta:', errors.g_recaptcha_response);
                            cleanupRecaptcha();
                            setTimeout(() => {
                                initializeRecaptcha();
                            }, 500);
                        }
                    }
                });
            }
        }
    };

    // Calcular edad mínima (18 años)
    const getMinBirthDate = () => {
        const today = new Date();
        const minDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
        return minDate.toISOString().split('T')[0];
    };

    // Calcular edad máxima (120 años)
    const getMaxBirthDate = () => {
        const today = new Date();
        const maxDate = new Date(today.getFullYear() - 120, today.getMonth(), today.getDate());
        return maxDate.toISOString().split('T')[0];
    };

    // Función para forzar recarga de reCAPTCHA
    const reloadRecaptcha = () => {
        console.log('🔁 Forzando recarga de reCAPTCHA...');
        cleanupRecaptcha();
        retryCountRef.current = 0;
        setTimeout(() => {
            initializeRecaptcha();
        }, 300);
    };

    // Efecto para manejar errores de reCAPTCHA del servidor
    useEffect(() => {
        if (errors.g_recaptcha_response) {
            console.error('⚠️ Error de reCAPTCHA detectado en errores:', errors.g_recaptcha_response);
            setRecaptchaError(true);
            cleanupRecaptcha();
            setTimeout(() => {
                initializeRecaptcha();
            }, 500);
        }
    }, [errors.g_recaptcha_response, cleanupRecaptcha, initializeRecaptcha]);

    return (
        <>
            <AuthLayout
                showOdessaLogo={!!odessaToken}
                title="Regístrate"
                header={
                    <>
                        <Heading>Regístrate y disfruta de beneficios exclusivos en Famedic</Heading>

                        <Text>
                            ¿Ya tienes una cuenta?{" "}
                            <TextLink href={route("login")} className="font-semibold">
                                Inicia sesión
                            </TextLink>
                        </Text>

                        {odessaToken && (
                            <OdessaLinkingMessage
                                secondsLeft={secondsLeft}
                                onTimerExpired={() => router.get(route("/"))}
                            />
                        )}
                    </>
                }
            >
                {inviter && (
                    <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-900/20">
                        <Text className="text-center">
                            <span className="mr-2 text-lg">🎉</span>
                            {inviter.name && inviter.name !== "Usuario" ? (
                                <>
                                    <strong className="font-semibold">{inviter.name}</strong> te ha invitado a
                                    unirte y disfrutar los beneficios de Famedic!
                                </>
                            ) : (
                                <>
                                    Te han invitado a unirte y disfrutar los
                                    beneficios de Famedic!
                                </>
                            )}
                        </Text>
                    </div>
                )}

                <form className="space-y-6" onSubmit={submit}>
                    {/* DEBUG: Mostrar token actual */}
                    {process.env.NODE_ENV === 'development' && (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-800 dark:bg-amber-900/20">
                            <div className="font-semibold">🔍 DEBUG reCAPTCHA:</div>
                            <div>Estado: {recaptchaLoaded ? '✅ Cargado' : '⏳ Cargando...'}</div>
                            <div>Token: {data.g_recaptcha_response ? '✅ Presente' : '❌ Ausente'}</div>
                            <div>Longitud: {data.g_recaptcha_response?.length || 0} caracteres</div>
                            <div>Site Key: {recaptchaSiteKey.substring(0, 10)}...</div>
                            <div>Error: {recaptchaError ? '❌ Sí' : '✅ No'}</div>
                            {data.g_recaptcha_response && (
                                <div className="mt-1 break-all">Preview: {data.g_recaptcha_response.substring(0, 30)}...</div>
                            )}
                            <button 
                                type="button" 
                                onClick={reloadRecaptcha}
                                className="mt-2 text-blue-600 hover:text-blue-800"
                            >
                                Recargar reCAPTCHA
                            </button>
                        </div>
                    )}

                    {/* Nombre completo - Una línea */}
                    <Field>
                        <Label>
                            Nombre completo <span className="text-red-500">*</span>
                        </Label>
                        <Input
                            dusk="name"
                            required
                            type="text"
                            value={data.name}
                            autoComplete="given-name"
                            onChange={(e) => setData("name", e.target.value)}
                            placeholder="Ej. Juan Carlos"
                            className="w-full"
                        />
                        {errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
                    </Field>

                    {/* Apellidos - Misma línea */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field>
                            <Label>
                                Apellido paterno <span className="text-red-500">*</span>
                            </Label>
                            <Input
                                dusk="paternalLastname"
                                required
                                type="text"
                                value={data.paternal_lastname}
                                autoComplete="family-name"
                                onChange={(e) =>
                                    setData("paternal_lastname", e.target.value)
                                }
                                placeholder="Ej. Pérez"
                                className="w-full"
                            />
                            {errors.paternal_lastname && (
                                <ErrorMessage>{errors.paternal_lastname}</ErrorMessage>
                            )}
                        </Field>

                        <Field>
                            <Label>
                                Apellido materno <span className="text-red-500">*</span>
                            </Label>
                            <Input
                                dusk="maternalLastname"
                                required
                                type="text"
                                value={data.maternal_lastname}
                                autoComplete="family-name"
                                onChange={(e) =>
                                    setData("maternal_lastname", e.target.value)
                                }
                                placeholder="Ej. López"
                                className="w-full"
                            />
                            {errors.maternal_lastname && (
                                <ErrorMessage>{errors.maternal_lastname}</ErrorMessage>
                            )}
                        </Field>
                    </div>

                    {/* Correo electrónico - Una línea */}
                    <Field>
                        <Label>
                            Correo electrónico <span className="text-red-500">*</span>
                        </Label>
                        <Input
                            dusk="email"
                            required
                            type="email"
                            value={data.email}
                            autoComplete="email"
                            onChange={(e) => setData("email", e.target.value)}
                            placeholder="ejemplo@correo.com"
                            className="w-full"
                        />
                        {errors.email && (
                            <ErrorMessage>{errors.email}</ErrorMessage>
                        )}
                    </Field>

                    {/* Teléfono celular - Una línea */}
                    <Field>
                        <Label>
                            Teléfono celular <span className="text-red-500">*</span>
                        </Label>
                        <div className="flex gap-3">
                            <div className="w-40 flex-shrink-0">
                                <select 
                                    name="phone_country"
                                    value="MX"
                                    disabled
                                    className="w-full cursor-not-allowed rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                >
                                    <option value="MX">+52 México</option>
                                </select>
                            </div>
                            
                            <div className="flex-1">
                                <Input
                                    dusk="phone"
                                    name="phone"
                                    required
                                    type="tel"
                                    value={data.phone}
                                    onChange={handlePhoneChange}
                                    autoComplete="tel-national"
                                    placeholder="XXX XXX XXXX"
                                    className="w-full"
                                    maxLength="12"
                                />
                            </div>
                        </div>
                        <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Solo números mexicanos (10 dígitos)
                        </Text>
                        {errors.phone && (
                            <ErrorMessage>{errors.phone}</ErrorMessage>
                        )}
                    </Field>

                    {/* Fecha de nacimiento y Sexo - Misma línea */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field>
                            <Label>
                                Fecha de nacimiento <span className="text-red-500">*</span>
                            </Label>
                            <div>
                                <Input
                                    dusk="birthDate"
                                    required
                                    type="date"
                                    value={data.birth_date}
                                    autoComplete="bday"
                                    onChange={(e) => setData("birth_date", e.target.value)}
                                    max={getMinBirthDate()}
                                    min={getMaxBirthDate()}
                                    className="w-full mt-3"
                                />
                                <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Debes ser mayor de 18 años
                                </Text>
                            </div>
                            {errors.birth_date && (
                                <ErrorMessage>{errors.birth_date}</ErrorMessage>
                            )}
                        </Field>

                        <Field>
                            <Label>
                                Sexo <span className="text-red-500">*</span>
                            </Label>
                            <Select
                                dusk="gender"
                                required
                                value={data.gender}
                                onChange={(e) => setData("gender", e.target.value)}
                                className="w-full"
                            >
                                <option value="" disabled>
                                    Selecciona tu sexo
                                </option>
                                {genders.map(({ label, value }) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                            <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Seleciona una opción
                                </Text>
                            {errors.gender && (
                                <ErrorMessage>{errors.gender}</ErrorMessage>
                            )}
                        </Field>
                    </div>

                    {/* Contraseñas - Misma línea */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field>
                            <Label>
                                Contraseña <span className="text-red-500">*</span>
                            </Label>
                            <div className="relative">
                                <Input
                                    dusk="password"
                                    required
                                    type={showPassword ? "text" : "password"}
                                    value={data.password}
                                    autoComplete="new-password"
                                    onChange={(e) => setData("password", e.target.value)}
                                    placeholder="Mínimo 8 caracteres"
                                    className="w-full pr-10"
                                />
                                <button
                                    type="button"
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                    onClick={() => setShowPassword(!showPassword)}
                                    aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
                                >
                                    {showPassword ? (
                                        <EyeSlashIcon className="h-5 w-5" />
                                    ) : (
                                        <EyeIcon className="h-5 w-5" />
                                    )}
                                </button>
                            </div>
                            <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Mínimo 8 caracteres
                            </Text>
                            {errors.password && (
                                <ErrorMessage>{errors.password}</ErrorMessage>
                            )}
                        </Field>

                        <Field>
                            <Label>
                                Confirmar contraseña <span className="text-red-500">*</span>
                            </Label>
                            <div className="relative">
                                <Input
                                    dusk="passwordConfirmation"
                                    required
                                    type={showConfirmPassword ? "text" : "password"}
                                    value={data.password_confirmation}
                                    autoComplete="new-password"
                                    onChange={(e) =>
                                        setData("password_confirmation", e.target.value)
                                    }
                                    placeholder="Repite tu contraseña"
                                    className="w-full pr-10"
                                />
                                <button
                                    type="button"
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                    onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                    aria-label={showConfirmPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
                                >
                                    {showConfirmPassword ? (
                                        <EyeSlashIcon className="h-5 w-5" />
                                    ) : (
                                        <EyeIcon className="h-5 w-5" />
                                    )}
                                </button>
                            </div>
                            <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Mínimo 8 caracteres
                            </Text>
                            {errors.password_confirmation && (
                                <ErrorMessage>
                                    {errors.password_confirmation}
                                </ErrorMessage>
                            )}
                        </Field>
                    </div>

                    {/* reCAPTCHA */}
                    <Field>
                        <Label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Verificación de seguridad <span className="text-red-500">*</span>
                        </Label>
                        <div className="mt-3">
                            <div 
                                ref={recaptchaRef}
                                className="flex justify-center min-h-[78px]"
                            />
                            {!recaptchaLoaded && !recaptchaError && (
                                <div className="mt-2 text-sm text-amber-600 dark:text-amber-400">
                                    Cargando verificación de seguridad...
                                </div>
                            )}
                            {recaptchaError && (
                                <div className="mt-2 text-sm text-red-600 dark:text-red-400">
                                    .....
                                </div>
                            )}
                            <button 
                                type="button" 
                                onClick={reloadRecaptcha}
                                className="mt-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400"
                            >
                                ¿No ves el reCAPTCHA? <br></br> Haz clic aquí para activarlo
                            </button>
                        </div>
                        {errors.g_recaptcha_response && (
                            <ErrorMessage>{errors.g_recaptcha_response}</ErrorMessage>
                        )}
                    </Field>

                    {/* Términos y condiciones */}
                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
                        <Text className="text-sm">
                            Al hacer clic en el botón "Registrar", aceptas todos los{" "}
                            <Anchor
                                href={route("terms-of-service")}
                                target="_blank"
                                className="font-semibold underline hover:no-underline"
                            >
                                Términos y condiciones de servicio
                            </Anchor>{" "}
                            y la{" "}
                            <Anchor 
                                href={route("privacy-policy")} 
                                target="_blank"
                                className="font-semibold underline hover:no-underline"
                            >
                                Política de privacidad
                            </Anchor>
                            .
                        </Text>
                    </div>                    

                    {/* Botón de registro */}
                    <Button
                        dusk="register"
                        className="w-full py-3 text-base font-semibold"
                        disabled={processing}
                        type="submit"
                        onClick={(e) => {
                            // Debug adicional al hacer clic
                            console.log('🖱️ Botón clickeado - Token actual:', {
                                token: data.g_recaptcha_response,
                                length: data.g_recaptcha_response?.length
                            });
                            
                            // Intentar obtener token directamente si está vacío
                            if (!data.g_recaptcha_response && window.grecaptcha && recaptchaWidgetId.current !== null) {
                                try {
                                    const directToken = window.grecaptcha.getResponse(recaptchaWidgetId.current);
                                    console.log('🔍 Token obtenido directamente:', {
                                        token: directToken,
                                        length: directToken?.length
                                    });
                                    if (directToken) {
                                        setData('g_recaptcha_response', directToken);
                                    }
                                } catch (e) {
                                    console.log('⚠️ Error obteniendo token directamente:', e);
                                }
                            }
                        }}
                    >
                        {processing ? (
                            <>
                                <ArrowPathIcon className="mr-2 h-5 w-5 animate-spin" />
                                Creando cuenta...
                            </>
                        ) : (
                            "Crear mi cuenta"
                        )}
                    </Button>

                    {/* Enlace a inicio de sesión */}
                    <Text className="text-center">
                        ¿Ya tienes una cuenta?{" "}
                        <TextLink 
                            href={route("login")} 
                            className="font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            Inicia sesión aquí
                        </TextLink>
                    </Text>
                </form>
            </AuthLayout>
        </>
    );
}