import FamedicLayout from "@/Layouts/FamedicLayout";
import { useForm, Link } from "@inertiajs/react";
import { useState, useEffect, useRef } from "react";

export default function RightsARCO({ name, flash = {} }) {
    const [fechaActual] = useState(() => {
        const now = new Date();
        return now.toISOString().split('T')[0];
    });

    const [showSuccess, setShowSuccess] = useState(null);
    const [folioGenerado, setFolioGenerado] = useState(null);
    const formRef = useRef(null);

    const { data, setData, post, processing, errors, reset, recentlySuccessful, clearErrors } = useForm({
        es_usuario: "",
        fecha_llenado: fechaActual,
        nombre_completo: "",
        fecha_nacimiento: "",
        rfc: "",
        calle: "",
        numero_exterior: "",
        numero_interior: "",
        colonia: "",
        municipio_estado: "",
        codigo_postal: "",
        telefono_fijo: "",
        telefono_celular: "",
        derechos_arco: [],
        razon_solicitud: "",
        solicitado_por: "titular",
    });

    // Manejar mensajes flash del backend
    useEffect(() => {
        if (flash.success) {
            setShowSuccess(flash.success);
            setFolioGenerado(flash.success.folio);
            reset();

            // Desplazarse al inicio para ver el mensaje
            window.scrollTo({ top: 0, behavior: 'smooth' });

            // Ocultar después de 15 segundos
            const timer = setTimeout(() => {
                setShowSuccess(null);
            }, 15000);

            return () => clearTimeout(timer);
        }

        if (flash.error) {
            // Manejar errores flash si es necesario
            console.error('Error flash:', flash.error);
        }
    }, [flash, reset]);

    // Manejar éxito reciente del formulario
    useEffect(() => {
        if (recentlySuccessful) {
            // Generar un folio temporal mientras esperamos la respuesta del backend
            const tempFolio = `ARCO-TEMP-${Date.now().toString().slice(-6)}`;
            setFolioGenerado(tempFolio);

            setShowSuccess({
                title: '¡Solicitud enviada exitosamente!',
                message: 'Tu solicitud está siendo procesada',
                folio: tempFolio
            });

            reset();
            clearErrors();

            // Desplazarse al inicio para ver el mensaje
            window.scrollTo({ top: 0, behavior: 'smooth' });

            // Ocultar después de 10 segundos
            const timer = setTimeout(() => {
                setShowSuccess(null);
            }, 10000);

            return () => clearTimeout(timer);
        }
    }, [recentlySuccessful, reset, clearErrors]);

    const handleSubmit = (e) => {
        e.preventDefault();

        // Validación frontend adicional
        if (data.derechos_arco.length === 0) {
            alert('Por favor, selecciona al menos un derecho ARCO.');
            return;
        }

        if (data.razon_solicitud.length < 20) {
            alert('La razón de la solicitud debe tener al menos 20 caracteres.');
            return;
        }

        if (!data.es_usuario) {
            alert('Por favor, indica si eres usuario FAMEDIC.');
            return;
        }

        // Enviar formulario
        post(route('store-arco'), {
            preserveScroll: true,
            onSuccess: () => {
                // Éxito manejado por useEffect con recentlySuccessful
            },
            onError: (errors) => {
                // Desplazarse al primer error
                if (Object.keys(errors).length > 0) {
                    const firstErrorField = Object.keys(errors)[0];
                    const errorElement = document.querySelector(`[name="${firstErrorField}"]`);
                    if (errorElement) {
                        errorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        errorElement.focus();
                    }
                }
            },
        });
    };

    const handleDerechoChange = (derecho) => {
        const newDerechos = data.derechos_arco.includes(derecho)
            ? data.derechos_arco.filter(d => d !== derecho)
            : [...data.derechos_arco, derecho];

        setData('derechos_arco', newDerechos);
    };

    const handleTelefonoChange = (field, value) => {
        const numericValue = value.replace(/\D/g, '').slice(0, 10);
        setData(field, numericValue);
    };

    const handleCodigoPostalChange = (value) => {
        const numericValue = value.replace(/\D/g, '').slice(0, 5);
        setData('codigo_postal', numericValue);
    };

    const handleRFCChange = (value) => {
        const cleanedValue = value.toUpperCase().replace(/\s/g, '');
        setData('rfc', cleanedValue.slice(0, 13));
    };

    const handleClearForm = () => {
        if (window.confirm('¿Estás seguro de que quieres limpiar todo el formulario?')) {
            reset();
            clearErrors();
        }
    };

    // Verificar si al menos un derecho está seleccionado
    const tieneDerechosSeleccionados = data.derechos_arco.length > 0;

    // Calcular longitud de la razón
    const razonLength = data.razon_solicitud.length;
    const razonIsValid = razonLength >= 20 && razonLength <= 2000;

    return (
        <FamedicLayout title={name}>
            <div className="min-h-screen bg-gray-50 py-8 md:py-12">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    {/* Mensaje de éxito */}
                    {showSuccess && (
                        <div className="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 shadow-sm animate-fade-in">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <svg className="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <div className="ml-3 flex-1">
                                    <div className="flex justify-between items-start">
                                        <h3 className="text-sm font-semibold text-green-800">
                                            {showSuccess.title}
                                        </h3>
                                        <button
                                            onClick={() => setShowSuccess(null)}
                                            className="text-green-600 hover:text-green-800 transition-colors"
                                            aria-label="Cerrar mensaje"
                                        >
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                    <div className="mt-1 text-sm text-green-700">
                                        <p>{showSuccess.message}</p>
                                        {showSuccess.folio && (
                                            <div className="mt-2 p-2 bg-green-100 rounded border border-green-200">
                                                <p className="font-mono font-bold text-green-900">
                                                    Folio: <span className="text-green-700">{showSuccess.folio}</span>
                                                </p>
                                                <p className="text-xs text-green-600 mt-1">
                                                    Guarda este número para futuras referencias
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <Link
                                            href={route('home')}
                                            className="inline-flex items-center text-sm font-medium text-green-800 hover:text-green-900 transition-colors"
                                        >
                                            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                            </svg>
                                            Volver al inicio
                                        </Link>
                                        <button
                                            onClick={() => setShowSuccess(null)}
                                            className="inline-flex items-center text-sm font-medium text-green-800 hover:text-green-900 transition-colors"
                                        >
                                            Continuar con otra solicitud
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Mensaje de error general */}
                    {errors.error && (
                        <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <div className="ml-3">
                                    <h3 className="text-sm font-semibold text-red-800">
                                        Error al procesar la solicitud
                                    </h3>
                                    <div className="mt-1 text-sm text-red-700">
                                        <p>{errors.error}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Encabezado */}
                    <div className="text-center mb-8">
                        <h1 className="font-poppins text-2xl md:text-3xl font-bold tracking-tight text-famedic-darker">
                            {name}
                        </h1>
                        <p className="mt-2 text-base md:text-lg text-gray-600">
                            Ejercicio de derechos de Acceso, Rectificación, Cancelación y Oposición
                        </p>
                        <div className="mt-4 text-sm text-gray-500">
                            <p>Todos los campos marcados con * son obligatorios</p>
                        </div>
                    </div>

                    <div className="mb-8">
                        <div className="bg-famedic-light rounded-t-lg p-4 md:p-6">
                            <h2 className="text-lg md:text-xl font-semibold text-white mb-3">
                                Información importante
                            </h2>
                            <ul className="list-disc pl-5 space-y-2 text-white/90 text-sm md:text-base">
                                <li>Complete todos los campos obligatorios (*)</li>
                                <li>Seleccione al menos un derecho ARCO a ejercer</li>
                                <li>La respuesta será enviada dentro de los próximos 20 días hábiles</li>
                                <li>Para consultas adicionales, contacte al Departamento de Datos Personales</li>
                            </ul>
                        </div>

                        {/* Formulario */}
                        <form
                            ref={formRef}
                            onSubmit={handleSubmit}
                            className="bg-white shadow-lg rounded-b-lg p-4 md:p-6 space-y-6"
                        >
                            {/* 1. Identificación del solicitante */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold text-famedic-darker border-b pb-2">
                                    <span className="bg-famedic-light text-white rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2">1</span>
                                    Identificación del solicitante
                                </h3>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        ¿Es usuario FAMEDIC? *
                                    </label>
                                    <div className="flex space-x-6">
                                        {['si', 'no'].map((opcion) => (
                                            <label key={opcion} className="inline-flex items-center">
                                                <input
                                                    type="radio"
                                                    className="h-4 w-4 text-famedic-light focus:ring-famedic-light"
                                                    name="es_usuario"
                                                    value={opcion}
                                                    checked={data.es_usuario === opcion}
                                                    onChange={(e) => setData('es_usuario', e.target.value)}
                                                    required
                                                />
                                                <span className="ml-2 text-gray-700">
                                                    {opcion.toUpperCase()}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                    {errors.es_usuario && (
                                        <p className="mt-1 text-sm text-red-600">{errors.es_usuario}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        Nombre completo *
                                    </label>
                                    <input
                                        type="text"
                                        name="nombre_completo"
                                        value={data.nombre_completo}
                                        onChange={(e) => setData('nombre_completo', e.target.value)}
                                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light ${errors.nombre_completo ? 'border-red-300' : 'border-gray-300'
                                            }`}
                                        placeholder="Ingrese su nombre completo"
                                        required
                                    />
                                    {errors.nombre_completo && (
                                        <p className="mt-1 text-sm text-red-600">{errors.nombre_completo}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Fecha de nacimiento
                                        </label>
                                        <input
                                            type="date"
                                            name="fecha_nacimiento"
                                            value={data.fecha_nacimiento}
                                            onChange={(e) => setData('fecha_nacimiento', e.target.value)}
                                            max={fechaActual}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light"
                                        />
                                        {errors.fecha_nacimiento && (
                                            <p className="mt-1 text-sm text-red-600">{errors.fecha_nacimiento}</p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            RFC
                                        </label>
                                        <input
                                            type="text"
                                            name="rfc"
                                            value={data.rfc}
                                            onChange={(e) => handleRFCChange(e.target.value)}
                                            className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light ${errors.rfc ? 'border-red-300' : 'border-gray-300'
                                                }`}
                                            placeholder="Ej: XAXX010101000"
                                        />
                                        <p className="mt-1 text-xs text-gray-500">
                                            Formato: 4 letras, 6 números, 3 caracteres
                                        </p>
                                        {errors.rfc && (
                                            <p className="mt-1 text-sm text-red-600">{errors.rfc}</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* 2. Domicilio */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold text-famedic-darker border-b pb-2">
                                    <span className="bg-famedic-light text-white rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2">2</span>
                                    Domicilio
                                </h3>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Calle
                                        </label>
                                        <input
                                            type="text"
                                            name="calle"
                                            value={data.calle}
                                            onChange={(e) => setData('calle', e.target.value)}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Número exterior
                                        </label>
                                        <input
                                            type="text"
                                            name="numero_exterior"
                                            value={data.numero_exterior}
                                            onChange={(e) => setData('numero_exterior', e.target.value)}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Número interior
                                        </label>
                                        <input
                                            type="text"
                                            name="numero_interior"
                                            value={data.numero_interior}
                                            onChange={(e) => setData('numero_interior', e.target.value)}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light"
                                        />
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Colonia
                                        </label>
                                        <input
                                            type="text"
                                            name="colonia"
                                            value={data.colonia}
                                            onChange={(e) => setData('colonia', e.target.value)}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Municipio y Estado
                                        </label>
                                        <input
                                            type="text"
                                            name="municipio_estado"
                                            value={data.municipio_estado}
                                            onChange={(e) => setData('municipio_estado', e.target.value)}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Código postal *
                                        </label>
                                        <input
                                            type="text"
                                            name="codigo_postal"
                                            value={data.codigo_postal}
                                            onChange={(e) => handleCodigoPostalChange(e.target.value)}
                                            className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light ${errors.codigo_postal ? 'border-red-300' : 'border-gray-300'
                                                }`}
                                            placeholder="00000"
                                            maxLength="5"
                                            required
                                        />
                                        {errors.codigo_postal && (
                                            <p className="mt-1 text-sm text-red-600">{errors.codigo_postal}</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* 3. Contacto */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold text-famedic-darker border-b pb-2">
                                    <span className="bg-famedic-light text-white rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2">3</span>
                                    Información de contacto
                                </h3>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Teléfono fijo
                                        </label>
                                        <input
                                            type="tel"
                                            name="telefono_fijo"
                                            value={data.telefono_fijo}
                                            onChange={(e) => handleTelefonoChange('telefono_fijo', e.target.value)}
                                            className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light ${errors.telefono_fijo ? 'border-red-300' : 'border-gray-300'
                                                }`}
                                            placeholder="(00) 0000 0000"
                                        />
                                        {errors.telefono_fijo && (
                                            <p className="mt-1 text-sm text-red-600">{errors.telefono_fijo}</p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Teléfono celular *
                                        </label>
                                        <input
                                            type="tel"
                                            name="telefono_celular"
                                            value={data.telefono_celular}
                                            onChange={(e) => handleTelefonoChange('telefono_celular', e.target.value)}
                                            className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light ${errors.telefono_celular ? 'border-red-300' : 'border-gray-300'
                                                }`}
                                            placeholder="(00) 0000 0000"
                                            required
                                        />
                                        <p className="mt-1 text-xs text-gray-500">
                                            10 dígitos sin espacios
                                        </p>
                                        {errors.telefono_celular && (
                                            <p className="mt-1 text-sm text-red-600">{errors.telefono_celular}</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* 4. Derechos ARCO */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold text-famedic-darker border-b pb-2">
                                    <span className="bg-famedic-light text-white rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2">4</span>
                                    Derechos ARCO a ejercer *
                                </h3>

                                <div className="space-y-3">
                                    {[
                                        { id: 'acceso', label: 'Acceso', description: 'Conocer los datos personales que tenemos de usted.' },
                                        { id: 'rectificacion', label: 'Rectificación', description: 'Solicitar la corrección de datos inexactos o incompletos.' },
                                        { id: 'cancelacion', label: 'Cancelación', description: 'Solicitar la eliminación de sus datos personales.' },
                                        { id: 'oposicion', label: 'Oposición', description: 'Oponerse al tratamiento de sus datos para fines específicos.' },
                                        { id: 'revocacion', label: 'Revocación', description: 'Revocar el consentimiento para el tratamiento de sus datos.' },
                                    ].map((derecho) => (
                                        <label
                                            key={derecho.id}
                                            className={`flex items-start p-3 border rounded-lg cursor-pointer transition-all duration-200 ${data.derechos_arco.includes(derecho.id)
                                                    ? 'border-famedic-light bg-famedic-light/10 shadow-sm'
                                                    : 'border-gray-200 hover:bg-gray-50 hover:border-gray-300'
                                                }`}
                                        >
                                            <input
                                                type="checkbox"
                                                name={`derecho_${derecho.id}`}
                                                checked={data.derechos_arco.includes(derecho.id)}
                                                onChange={() => handleDerechoChange(derecho.id)}
                                                className="h-5 w-5 text-famedic-light rounded border-gray-300 focus:ring-famedic-light mt-0.5 flex-shrink-0"
                                            />
                                            <div className="ml-3 flex-1">
                                                <span className="text-sm font-medium text-gray-900">
                                                    {derecho.label}
                                                </span>
                                                <p className="text-sm text-gray-500 mt-0.5">
                                                    {derecho.description}
                                                </p>
                                            </div>
                                        </label>
                                    ))}
                                </div>

                                {errors.derechos_arco && (
                                    <div className="rounded-md bg-red-50 p-3 border border-red-200">
                                        <p className="text-sm text-red-600 font-medium">{errors.derechos_arco}</p>
                                    </div>
                                )}

                                {!tieneDerechosSeleccionados && (
                                    <div className="rounded-md bg-yellow-50 p-3 border border-yellow-200">
                                        <div className="flex">
                                            <div className="flex-shrink-0">
                                                <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                                </svg>
                                            </div>
                                            <div className="ml-3">
                                                <p className="text-sm text-yellow-700">
                                                    <span className="font-medium">Atención:</span> Debe seleccionar al menos un derecho ARCO para continuar.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* 5. Descripción de la solicitud */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold text-famedic-darker border-b pb-2">
                                    <span className="bg-famedic-light text-white rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2">5</span>
                                    Descripción de la solicitud *
                                </h3>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        Breve razón de su solicitud
                                    </label>
                                    <textarea
                                        name="razon_solicitud"
                                        value={data.razon_solicitud}
                                        onChange={(e) => setData('razon_solicitud', e.target.value)}
                                        rows={5}
                                        className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-famedic-light resize-y ${errors.razon_solicitud ? 'border-red-300' : 'border-gray-300'
                                            } ${!razonIsValid && razonLength > 0 ? 'border-yellow-300' : ''}`}
                                        placeholder="Describa detalladamente el motivo de su solicitud, incluyendo información relevante que nos permita atender su petición de manera eficiente."
                                        required
                                    />
                                    <div className="mt-2 flex flex-col sm:flex-row sm:justify-between text-sm">
                                        <div className={`font-medium ${razonIsValid ? 'text-green-600' : razonLength > 0 ? 'text-yellow-600' : 'text-gray-500'}`}>
                                            {razonLength < 20 ? `Mínimo ${20 - razonLength} caracteres más` :
                                                razonLength > 2000 ? `Excede por ${razonLength - 2000} caracteres` :
                                                    'Longitud adecuada'}
                                        </div>
                                        <div className="text-gray-500 mt-1 sm:mt-0">
                                            {razonLength} / 2000 caracteres
                                        </div>
                                    </div>
                                    {errors.razon_solicitud && (
                                        <p className="mt-1 text-sm text-red-600">{errors.razon_solicitud}</p>
                                    )}
                                </div>
                            </div>

                            {/* 6. Solicitud presentada por */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold text-famedic-darker border-b pb-2">
                                    <span className="bg-famedic-light text-white rounded-full w-6 h-6 inline-flex items-center justify-center text-sm mr-2">6</span>
                                    Solicitud presentada por *
                                </h3>

                                <div className="space-y-3">
                                    <label className={`flex items-center p-3 border rounded-lg cursor-pointer transition-all duration-200 ${data.solicitado_por === 'titular'
                                            ? 'border-famedic-light bg-famedic-light/10 shadow-sm'
                                            : 'border-gray-200 hover:bg-gray-50 hover:border-gray-300'
                                        }`}>
                                        <input
                                            type="radio"
                                            name="solicitado_por"
                                            value="titular"
                                            checked={data.solicitado_por === 'titular'}
                                            onChange={() => setData('solicitado_por', 'titular')}
                                            className="h-5 w-5 text-famedic-light border-gray-300 focus:ring-famedic-light"
                                        />
                                        <div className="ml-3">
                                            <span className="text-sm font-medium text-gray-900">
                                                Titular
                                            </span>
                                            <p className="text-sm text-gray-500 mt-0.5">
                                                Soy el titular de los datos personales
                                            </p>
                                        </div>
                                    </label>

                                    <label className={`flex items-center p-3 border rounded-lg cursor-pointer transition-all duration-200 ${data.solicitado_por === 'representante'
                                            ? 'border-famedic-light bg-famedic-light/10 shadow-sm'
                                            : 'border-gray-200 hover:bg-gray-50 hover:border-gray-300'
                                        }`}>
                                        <input
                                            type="radio"
                                            name="solicitado_por"
                                            value="representante"
                                            checked={data.solicitado_por === 'representante'}
                                            onChange={() => setData('solicitado_por', 'representante')}
                                            className="h-5 w-5 text-famedic-light border-gray-300 focus:ring-famedic-light"
                                        />
                                        <div className="ml-3">
                                            <span className="text-sm font-medium text-gray-900">
                                                Representante legal
                                            </span>
                                            <p className="text-sm text-gray-500 mt-0.5">
                                                Actúo en representación legal del titular
                                            </p>
                                        </div>
                                    </label>
                                </div>

                                {errors.solicitado_por && (
                                    <p className="mt-1 text-sm text-red-600">{errors.solicitado_por}</p>
                                )}
                            </div>

                            {/* Plazo de respuesta */}
                            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div className="flex">
                                    <div className="flex-shrink-0">
                                        <svg className="h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div className="ml-3">
                                        <h3 className="text-sm font-semibold text-blue-800">
                                            Plazo de respuesta
                                        </h3>
                                        <div className="mt-1 text-sm text-blue-700">
                                            <p>
                                                De conformidad con el Capítulo II de la Ley Federal de Protección de Datos Personales en Posesión de Particulares,
                                                usted recibirá una respuesta a la presente solicitud dentro de los próximos <strong>20 (veinte) días hábiles</strong> posteriores a su recepción.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Botones de acción */}
                            <div className="flex flex-col-reverse sm:flex-row sm:justify-between sm:items-center pt-6 border-t border-gray-200 gap-4">
                                <div className="flex flex-col sm:flex-row gap-3">
                                    <Link
                                        href={route('home')}
                                        className="inline-flex justify-center items-center px-4 py-2.5 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors text-sm font-medium"
                                    >
                                        Cancelar
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={handleClearForm}
                                        className="inline-flex justify-center items-center px-4 py-2.5 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors text-sm font-medium"
                                    >
                                        Limpiar formulario
                                    </button>
                                </div>
                                <button
                                    type="submit"
                                    disabled={processing || !tieneDerechosSeleccionados || !razonIsValid || !data.es_usuario}
                                    className="inline-flex justify-center items-center px-6 py-2.5 bg-famedic-light text-white rounded-md hover:bg-famedic-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-famedic-light disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-sm font-medium shadow-sm"
                                >
                                    {processing ? (
                                        <>
                                            <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            Procesando...
                                        </>
                                    ) : 'Enviar solicitud'}
                                </button>
                            </div>
                        </form>

                        {/* Información de contacto */}
                        <div className="mt-6 bg-gray-50 p-4 md:p-6 rounded-lg border border-gray-200">
                            <h3 className="text-lg font-semibold text-gray-800 mb-4">
                                Información de contacto adicional
                            </h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 className="text-sm font-medium text-gray-700 mb-2">Departamento de Datos Personales</h4>
                                    <p className="text-sm text-gray-600">
                                        <strong className="block mb-1">Correo electrónico:</strong>
                                        <a href="mailto:contacto@famedic.com.mx" className="text-famedic-light hover:text-famedic-dark transition-colors">
                                            contacto@famedic.com.mx
                                        </a>
                                    </p>
                                </div>
                                <div>
                                    <h4 className="text-sm font-medium text-gray-700 mb-2">Dirección</h4>
                                    <p className="text-sm text-gray-600">
                                        Calle José Clemente Orozco número 335, Despacho 202<br />
                                        Colonia Valle Oriente, C. P. 66269<br />
                                        San Pedro Garza García, Nuevo León, México
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Footer informativo */}
                    <div className="mt-8 text-center text-sm text-gray-500">
                        <p>© {new Date().getFullYear()} Grupo Famedic, S.A. de C.V. Todos los derechos reservados.</p>
                        <p className="mt-1">
                            <Link href={route('privacy-policy')} className="text-famedic-light hover:text-famedic-dark transition-colors">
                                Política de privacidad
                            </Link>
                            {' • '}
                            <Link href={route('terms-of-service')} className="text-famedic-light hover:text-famedic-dark transition-colors">
                                Términos y condiciones
                            </Link>
                        </p>
                    </div>
                </div>
            </div>

            {/* Estilos inline para animaciones */}
            <style>{`
    @keyframes fade-in {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .animate-fade-in {
        animation: fade-in 0.3s ease-out;
    }
`}</style>
        </FamedicLayout>
    );
}