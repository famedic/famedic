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
    genders = [],
    states = {},
    inviter = null,
    odessaToken = null,
    secondsLeft = 0,
}) {
    // Asegurar valores por defecto en el componente
    const safeGenders = Array.isArray(genders) ? genders : [];
    const safeStates = states && typeof states === 'object' ? states : {};
    
    const { data, setData, post, processing, errors, reset } = useForm({
        name: "",
        paternal_lastname: "",
        maternal_lastname: "",
        birth_date: "",
        gender: "",
        state: "",
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

    // Clave de reCAPTCHA
    const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY ||
        '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';

    // Manejo simplificado de reCAPTCHA
    useEffect(() => {
        let isMounted = true;
        let scriptLoaded = false;

        const initializeRecaptcha = () => {
            if (!isMounted || !recaptchaRef.current || !window.grecaptcha) {
                return;
            }

            try {
                // Limpiar el contenedor
                if (recaptchaRef.current) {
                    recaptchaRef.current.innerHTML = '';
                }
                
                // Crear un contenedor único
                const containerId = `recaptcha-${Date.now()}`;
                const container = document.createElement('div');
                container.id = containerId;
                
                if (recaptchaRef.current) {
                    recaptchaRef.current.appendChild(container);
                }

                // Renderizar reCAPTCHA
                recaptchaWidgetId.current = window.grecaptcha.render(containerId, {
                    sitekey: recaptchaSiteKey,
                    callback: (token) => {
                        if (isMounted) {
                            setData('g_recaptcha_response', token);
                            setRecaptchaError(false);
                        }
                    },
                    'expired-callback': () => {
                        if (isMounted) {
                            setData('g_recaptcha_response', '');
                            setRecaptchaError(true);
                        }
                    },
                    'error-callback': () => {
                        if (isMounted) {
                            setData('g_recaptcha_response', '');
                            setRecaptchaError(true);
                        }
                    },
                    size: 'normal',
                    theme: 'light',
                    tabindex: 0,
                });

                setRecaptchaLoaded(true);
                setRecaptchaError(false);

            } catch (error) {
                console.error('Error inicializando reCAPTCHA:', error);
                if (isMounted) {
                    setRecaptchaError(true);
                }
            }
        };

        const loadRecaptchaScript = () => {
            if (scriptLoaded || !isMounted) return;

            // Verificar si ya está cargado
            if (window.grecaptcha) {
                initializeRecaptcha();
                return;
            }

            // Verificar si ya hay un script cargándose
            const existingScript = document.querySelector('script[src*="google.com/recaptcha/api"]');
            if (existingScript) {
                // Esperar a que se cargue
                const checkInterval = setInterval(() => {
                    if (window.grecaptcha && isMounted) {
                        clearInterval(checkInterval);
                        initializeRecaptcha();
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
                scriptLoaded = true;
                if (isMounted) {
                    // Pequeño delay para asegurar disponibilidad
                    setTimeout(() => {
                        initializeRecaptcha();
                    }, 300);
                }
            };

            script.onerror = () => {
                console.error('Error cargando script de reCAPTCHA');
                if (isMounted) {
                    setRecaptchaError(true);
                }
            };

            document.head.appendChild(script);
        };

        // Cargar después de un pequeño delay
        const timer = setTimeout(() => {
            loadRecaptchaScript();
        }, 500);

        // Cleanup
        return () => {
            isMounted = false;
            clearTimeout(timer);
            
            // Limpieza básica
            if (recaptchaWidgetId.current !== null && window.grecaptcha?.reset) {
                try {
                    window.grecaptcha.reset(recaptchaWidgetId.current);
                } catch (e) {
                    // Ignorar errores en cleanup
                }
            }
        };
    }, [recaptchaSiteKey, setData]);

    // Función para recargar reCAPTCHA
    const reloadRecaptcha = useCallback(() => {
        if (window.grecaptcha?.reset && recaptchaWidgetId.current !== null) {
            try {
                window.grecaptcha.reset(recaptchaWidgetId.current);
                setData('g_recaptcha_response', '');
                setRecaptchaError(false);
            } catch (e) {
                console.error('Error reseteando reCAPTCHA:', e);
                setRecaptchaError(true);
            }
        } else {
            // Forzar recarga del componente
            setRecaptchaLoaded(false);
            setRecaptchaError(false);
            
            // Limpiar contenedor
            if (recaptchaRef.current) {
                recaptchaRef.current.innerHTML = '';
            }
            
            // Reintentar carga después de un breve momento
            setTimeout(() => {
                if (window.grecaptcha && recaptchaRef.current) {
                    try {
                        const containerId = `recaptcha-reload-${Date.now()}`;
                        const container = document.createElement('div');
                        container.id = containerId;
                        recaptchaRef.current.appendChild(container);
                        
                        recaptchaWidgetId.current = window.grecaptcha.render(containerId, {
                            sitekey: recaptchaSiteKey,
                            callback: (token) => {
                                setData('g_recaptcha_response', token);
                                setRecaptchaError(false);
                            },
                            'expired-callback': () => {
                                setData('g_recaptcha_response', '');
                                setRecaptchaError(true);
                            },
                            'error-callback': () => {
                                setData('g_recaptcha_response', '');
                                setRecaptchaError(true);
                            }
                        });
                        
                        setRecaptchaLoaded(true);
                    } catch (error) {
                        console.error('Error recargando reCAPTCHA:', error);
                        setRecaptchaError(true);
                    }
                }
            }, 300);
        }
    }, [recaptchaSiteKey, setData]);

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
        

        // Verificar reCAPTCHA
        if (!data.g_recaptcha_response) {
            // Intentar obtener el token directamente
            if (window.grecaptcha?.getResponse && recaptchaWidgetId.current !== null) {
                try {
                    const directToken = window.grecaptcha.getResponse(recaptchaWidgetId.current);
                    if (directToken) {
                        setData('g_recaptcha_response', directToken);
                        
                        // Reintentar envío
                        setTimeout(() => {
                            submit(e);
                        }, 100);
                        return;
                    }
                } catch (error) {
                    console.log('Error obteniendo token:', error);
                }
            }
            
            alert('Por favor, verifica que no eres un robot completando el reCAPTCHA');
            setRecaptchaError(true);
            return;
        }

        if (!processing) {
            if (odessaToken) {
                post(
                    route("odessa-register.store", { odessa_token: odessaToken }),
                    {
                        onFinish: () => {
                            reset("password", "password_confirmation");
                        },
                        onError: (errors) => {
                            console.error('Error en registro Odessa:', errors);
                            if (errors.g_recaptcha_response) {
                                setRecaptchaError(true);
                            }
                        }
                    },
                );
            } else {
                post(route("register"), {
                    onSuccess: () => {
                        console.log('Registro exitoso');
                    },
                    onFinish: () => {
                        reset("password", "password_confirmation");
                    },
                    onError: (errors) => {
                        console.error('Error en registro:', errors);
                        if (errors.g_recaptcha_response) {
                            setRecaptchaError(true);
                        }
                    }
                });
            }
        }
    };

    // Calcular edad mínima (18 años) - CORREGIDO
    const getMinBirthDate = () => {
        const today = new Date();
        const minDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate() + 1);
        return minDate.toISOString().split('T')[0];
    };

    // Calcular edad máxima (120 años)
    const getMaxBirthDate = () => {
        const today = new Date();
        const maxDate = new Date(today.getFullYear() - 120, today.getMonth(), today.getDate());
        return maxDate.toISOString().split('T')[0];
    };

    // Obtener entradas de estados de manera segura
    const getStatesEntries = useCallback(() => {
        try {
            return Object.entries(safeStates);
        } catch (error) {
            console.error('Error al obtener entradas de estados:', error);
            return [];
        }
    }, [safeStates]);

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
                    {/* DEBUG: Mostrar token actual solo en desarrollo */}
                    {process.env.NODE_ENV === 'development' && (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-800 dark:bg-amber-900/20">
                            <div className="font-semibold">🔍 DEBUG:</div>
                            <div>reCAPTCHA: {recaptchaLoaded ? '✅' : '⏳'}</div>
                            <div>Token: {data.g_recaptcha_response ? '✅' : '❌'}</div>
                            <div>Genders: {safeGenders.length}</div>
                            <div>States: {Object.keys(safeStates).length}</div>
                            <button
                                type="button"
                                onClick={reloadRecaptcha}
                                className="mt-2 text-blue-600 hover:text-blue-800"
                            >
                                Recargar reCAPTCHA
                            </button>
                        </div>
                    )}

                    {/* Nombre completo */}
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

                    {/* Apellidos */}
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

                    {/* Correo electrónico */}
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

                    {/* Teléfono celular */}
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
                                    maxLength="14"
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

                    {/* Fecha de nacimiento y Sexo */}
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
                                {safeGenders.map(({ label, value }) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                            <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Selecciona una opción
                            </Text>
                            {errors.gender && (
                                <ErrorMessage>{errors.gender}</ErrorMessage>
                            )}
                        </Field>
                    </div>

                    {/* Estado de México */}
                    <Field>
                        <Label>
                            Estado de residencia <span className="text-red-500">*</span>
                        </Label>
                        <Select
                            dusk="state"
                            required
                            value={data.state} 
                            onChange={(e) => setData("state", e.target.value)}
                            className="w-full"
                        >
                            <option value="" disabled>
                                Selecciona tu estado
                            </option>
                            {getStatesEntries().map(([clave, nombre]) => (
                                <option key={clave} value={clave}>
                                    {nombre}
                                </option>
                            ))}
                        </Select>
                        <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Selecciona el estado donde resides
                        </Text>
                        {errors.state && (
                            <ErrorMessage>{errors.state}</ErrorMessage>
                        )}
                    </Field>

                    {/* Contraseñas */}
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
                                    Error cargando la verificación de seguridad
                                </div>
                            )}
                            <button
                                type="button"
                                onClick={reloadRecaptcha}
                                className="mt-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400"
                            >
                                ¿No ves el reCAPTCHA? Haz clic aquí para recargar
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