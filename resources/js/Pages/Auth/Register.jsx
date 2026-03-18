import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { useForm } from "@inertiajs/react";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, TextLink } from "@/Components/Catalyst/text";
import OdessaLinkingMessage from "@/Components/Auth/OdessaLinkingMessage";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { useState, useCallback } from "react";
import { EyeIcon, EyeSlashIcon } from "@heroicons/react/20/solid";

// Componentes personalizados
import { useRecaptcha } from "@/Hooks/useRecaptcha";
import StateSelect from "@/Components/StateSelect";
import TermsAgreement from "@/Components/TermsAgreement";
import InvitationBanner from "@/Components/InvitationBanner";
import DebugInfo from "@/Components/DebugInfo";

export default function Register({
    genders = [],
    states = {},
    inviter = null,
    odessaToken = null,
    secondsLeft = 0,
}) {
    // Asegurar valores por defecto
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

    // Clave de reCAPTCHA
    const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY ||
        '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';

    // Hook personalizado para reCAPTCHA
    const handleRecaptchaToken = useCallback((token) => {
        setData('g_recaptcha_response', token);
    }, [setData]);

    const {
        recaptchaRef,
        isLoaded: recaptchaLoaded,
        error: recaptchaError,
        token: recaptchaToken,
        reload: reloadRecaptcha,
        getToken: getRecaptchaToken
    } = useRecaptcha(recaptchaSiteKey, handleRecaptchaToken);

    // Función para formatear número de teléfono mexicano
    const formatMexicanPhone = (value) => {
        const numbers = value.replace(/\D/g, '');

        if (numbers.length <= 3) {
            return numbers;
        } else if (numbers.length <= 6) {
            return `${numbers.slice(0, 3)} ${numbers.slice(3)}`;
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
        let currentToken = data.g_recaptcha_response;
        
        if (!currentToken) {
            // Intentar obtener el token directamente
            const directToken = getRecaptchaToken();
            if (directToken) {
                currentToken = directToken;
                setData('g_recaptcha_response', directToken);
            }
        }

        if (!currentToken) {
            alert('Por favor, verifica que no eres un robot completando el reCAPTCHA');
            return;
        }

        if (!processing) {
            const routeName = odessaToken ? "odessa-register.store" : "register";
            const routeParams = odessaToken ? { odessa_token: odessaToken } : {};
            
            post(route(routeName, routeParams), {
                onFinish: () => reset("password", "password_confirmation"),
                onError: (errors) => {
                    console.error('Error en registro:', errors);
                }
            });
        }
    };

    // Calcular fechas mínima y máxima
    const getMinBirthDate = () => {
        const today = new Date();
        const minDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate() + 1);
        return minDate.toISOString().split('T')[0];
    };

    const getMaxBirthDate = () => {
        const today = new Date();
        const maxDate = new Date(today.getFullYear() - 120, today.getMonth(), today.getDate());
        return maxDate.toISOString().split('T')[0];
    };

    return (
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
            <InvitationBanner inviter={inviter} />

            <form className="space-y-6" onSubmit={submit}>
                {/* Debug info solo en desarrollo */}
                <DebugInfo
                    recaptchaLoaded={recaptchaLoaded}
                    recaptchaToken={recaptchaToken}
                    gendersCount={safeGenders.length}
                    statesCount={Object.keys(safeStates).length}
                    onReloadRecaptcha={reloadRecaptcha}
                />

                {/* Campos del formulario */}
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
                            onChange={(e) => setData("paternal_lastname", e.target.value)}
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
                            onChange={(e) => setData("maternal_lastname", e.target.value)}
                            placeholder="Ej. López"
                            className="w-full"
                        />
                        {errors.maternal_lastname && (
                            <ErrorMessage>{errors.maternal_lastname}</ErrorMessage>
                        )}
                    </Field>
                </div>

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
                    {errors.email && <ErrorMessage>{errors.email}</ErrorMessage>}
                </Field>

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
                    {errors.phone && <ErrorMessage>{errors.phone}</ErrorMessage>}
                </Field>

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
                        {errors.birth_date && <ErrorMessage>{errors.birth_date}</ErrorMessage>}
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
                        {errors.gender && <ErrorMessage>{errors.gender}</ErrorMessage>}
                    </Field>
                </div>

                {/* Componente StateSelect */}
                <StateSelect
                    value={data.state}
                    onChange={(value) => setData("state", value)}
                    error={errors.state}
                    backendStates={safeStates}
                />

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
                                {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                            </button>
                        </div>
                        <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Mínimo 8 caracteres
                        </Text>
                        {errors.password && <ErrorMessage>{errors.password}</ErrorMessage>}
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
                                onChange={(e) => setData("password_confirmation", e.target.value)}
                                placeholder="Repite tu contraseña"
                                className="w-full pr-10"
                            />
                            <button
                                type="button"
                                className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                aria-label={showConfirmPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
                            >
                                {showConfirmPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                            </button>
                        </div>
                        <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Mínimo 8 caracteres
                        </Text>
                        {errors.password_confirmation && (
                            <ErrorMessage>{errors.password_confirmation}</ErrorMessage>
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

                {/* Componente TermsAgreement */}
                <TermsAgreement />

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
    );
}