import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Description, Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Button } from "@/Components/Catalyst/button";
import { usePage, useForm, router } from "@inertiajs/react";
import { ErrorMessage } from "@/Components/Catalyst/fieldset";
import { useEffect, useState, useRef } from "react";
import {
	ArrowPathIcon,
	DocumentTextIcon,
	CheckCircleIcon,
	ExclamationTriangleIcon,
	PencilIcon,
	ArrowUpTrayIcon,
	XMarkIcon,
	ChevronRightIcon,
	DocumentArrowUpIcon,
	PencilSquareIcon,
	ArrowLeftIcon,
} from "@heroicons/react/24/solid";
import {
	Listbox,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";
import {
	TaxProfileModalCloseButton,
	TaxProfileFormStepper,
	TaxProfilePageHeading,
	TaxProfileEntryModeCard,
	TaxProfileCompactAlert,
	TaxProfileTrustIndicators,
	TaxProfileModalFooter,
} from "@/Pages/TaxProfiles/TaxProfileFormUI";

// Definimos los pasos del proceso
const STEPS = {
	UPLOAD: 1,
	REVIEW: 2,
	CONFIRM: 3,
};

// Modos de entrada de datos
const ENTRY_MODES = {
	AUTOMATIC: 'automatic',
	MANUAL: 'manual'
};

export default function TaxProfileForm({ isOpen }) {
	console.log("🔵 TaxProfileForm - Componente montado, isOpen:", isOpen);

	const { taxProfile, taxRegimes } = usePage().props;
	console.log("📋 Props recibidas - taxProfile:", taxProfile, "taxRegimes:", Object.keys(taxRegimes || {}).length);

	const [cachedTaxRegimes, setCachedTaxRegimes] = useState(taxRegimes || {});
	const [cachedEditMode, setCachedEditMode] = useState(
		route().current("tax-profiles.edit") || false
	);
	const [cachedTaxProfile, setCachedTaxProfile] = useState(taxProfile || null);
	const [extractedData, setExtractedData] = useState(null);
	const [processingPdf, setProcessingPdf] = useState(false);
	const [uploadedFile, setUploadedFile] = useState(null);
	const [infoMessage, setInfoMessage] = useState(null);
	const [currentStep, setCurrentStep] = useState(null);
	const [uploadProgress, setUploadProgress] = useState(0);
	const [activeStep, setActiveStep] = useState(STEPS.UPLOAD);
	const [isEditing, setIsEditing] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const [saveStep, setSaveStep] = useState("");

	// Nuevo estado para el modo de entrada
	const [entryMode, setEntryMode] = useState(ENTRY_MODES.AUTOMATIC);
	const [fileRequired, setFileRequired] = useState(true);
	const [isModeSelected, setIsModeSelected] = useState(false);

	const resetFormData = (taxProfile) => ({
		name: taxProfile?.name || "",
		rfc: taxProfile?.rfc || "",
		zipcode: taxProfile?.zipcode || "",
		tax_regime: taxProfile?.tax_regime || null,
		cfdi_use: taxProfile?.cfdi_use || "G03",
		fiscal_certificate: null,
		confirm_data: false,
	});

	const { data, setData, errors, setError, clearErrors } = useForm(
		resetFormData(taxProfile || {})
	);

	// Refs
	const fileInputRef = useRef(null);
	const manualFileInputRef = useRef(null);
	const activeStepRef = useRef(activeStep);

	// Actualizar ref cuando activeStep cambie
	useEffect(() => {
		activeStepRef.current = activeStep;
	}, [activeStep]);

	useEffect(() => {
		console.log("🔄 useEffect - isOpen cambió a:", isOpen);

		if (isOpen) {
			console.log("🚀 INICIALIZANDO FORMULARIO (diálogo abierto)");

			const isEditMode = route().current("tax-profiles.edit") || false;
			console.log("📝 Modo edición detectado:", isEditMode);

			// Resetear todo al abrir
			setCachedTaxRegimes(taxRegimes || {});
			setCachedTaxProfile(taxProfile || null);
			setCachedEditMode(isEditMode);
			setData(resetFormData(taxProfile || {}));
			setExtractedData(null);
			setUploadedFile(null);
			setInfoMessage(null);
			setCurrentStep(null);
			setUploadProgress(0);
			setActiveStep(STEPS.UPLOAD);
			setIsEditing(false);
			setIsSaving(false);
			setSaveStep("");
			setEntryMode(ENTRY_MODES.AUTOMATIC);
			setFileRequired(true);
			setIsModeSelected(false);

			console.log("✅ Estado inicializado - activeStep:", STEPS.UPLOAD, "entryMode:", ENTRY_MODES.AUTOMATIC);
		}

	}, [isOpen, taxProfile, taxRegimes, setData]);

	// Debug para cambios de estado importantes
	useEffect(() => {
		console.log("📊 Estado actualizado:", {
			activeStep,
			entryMode,
			uploadedFile: uploadedFile?.name,
			extractedData: !!extractedData,
			processingPdf,
			isModeSelected
		});
	}, [activeStep, entryMode, uploadedFile, extractedData, processingPdf, isModeSelected]);

	// Función para cambiar el modo de entrada
	const handleEntryModeChange = (mode) => {
		console.log("🔄 Cambiando modo de entrada a:", mode);
		setEntryMode(mode);
		setIsModeSelected(true);

		// En ambos modos el archivo es obligatorio
		setFileRequired(true);
		clearErrors("fiscal_certificate");

		// Resetear archivo si cambia de modo
		if (uploadedFile) {
			setUploadedFile(null);
			setExtractedData(null);
			setData("fiscal_certificate", null);
		}
	};

	// Función para procesar archivo (común para selección y pegado) - SOLO PARA MODO AUTOMÁTICO
	const handleFileProcess = async (file, fromPaste = false) => {
		console.log("📄 handleFileProcess llamado con archivo:", file.name, "fromPaste:", fromPaste);

		if (!file) {
			console.log("❌ No hay archivo");
			return;
		}

		if (file.type !== "application/pdf") {
			console.log("❌ Tipo de archivo inválido:", file.type);
			setError("fiscal_certificate", "Solo se aceptan archivos PDF");
			return;
		}

		if (file.size > 5 * 1024 * 1024) {
			console.log("❌ Archivo muy grande:", file.size);
			setError("fiscal_certificate", "El archivo no debe superar 5MB");
			return;
		}

		// Reset estados
		console.log("🔄 Iniciando procesamiento de PDF");
		setProcessingPdf(true);
		setCurrentStep("Validando archivo...");
		setUploadProgress(10);
		setInfoMessage(null);
		clearErrors("fiscal_certificate");

		try {
			// Paso 1: Preparar datos
			console.log("📦 Preparando datos para enviar");
			setCurrentStep("Preparando para enviar...");
			setUploadProgress(20);
			await new Promise((resolve) => setTimeout(resolve, 300));

			const formData = new FormData();
			formData.append("fiscal_certificate", file);

			// Obtener CSRF token
			const metaTag = document.querySelector('meta[name="csrf-token"]');
			if (!metaTag) {
				console.log("❌ CSRF token no encontrado");
				console.log("🔔 Error de seguridad. Por favor, recarga la página.");
				throw new Error("CSRF_TOKEN_NOT_FOUND");
			}
			const csrfToken = metaTag.getAttribute("content");
			formData.append("_token", csrfToken);

			// Paso 2: Enviar al servidor
			console.log("🌐 Enviando archivo al servidor...");
			setCurrentStep("Enviando archivo...");
			setUploadProgress(40);

			const controller = new AbortController();
			const timeoutId = setTimeout(() => controller.abort(), 30000);

			const url = route("tax-profiles.extract-data");
			console.log("📤 Enviando a URL:", url);

			const response = await fetch(url, {
				method: "POST",
				body: formData,
				credentials: "include",
				headers: {
					"X-Requested-With": "XMLHttpRequest",
					Accept: "application/json",
					"X-CSRF-TOKEN": csrfToken,
				},
				signal: controller.signal,
			});

			clearTimeout(timeoutId);
			console.log("📥 Respuesta recibida:", response.status, response.statusText);

			// Leer respuesta
			const responseText = await response.text();
			console.log("📄 Texto de respuesta (primeros 500 chars):", responseText.substring(0, 500));

			// Parsear JSON
			let result;
			try {
				result = JSON.parse(responseText);
				console.log("✅ JSON parseado correctamente:", result.success ? "Éxito" : "Error");
			} catch (jsonError) {
				console.error("❌ Error parseando JSON:", jsonError);
				console.log("🔔 Respuesta inválida del servidor.");
				throw new Error("INVALID_JSON_RESPONSE");
			}

			// Verificar resultado
			setUploadProgress(80);
			setCurrentStep("Extrayendo información...");

			if (response.ok && result.success) {
				console.log("✅ Procesamiento exitoso, datos extraídos:", result.data);
				setUploadProgress(100);
				setCurrentStep("¡Completado!");

				// Guardar datos y archivo
				setExtractedData(result.data);
				setUploadedFile(file);
				setData("fiscal_certificate", file);

				// Rellenar campos
				if (result.data.rfc) setData("rfc", result.data.rfc);
				if (result.data.razon_social || result.data.nombre) {
					setData(
						"name",
						result.data.razon_social || result.data.nombre
					);
				}
				if (result.data.codigo_postal)
					setData("zipcode", result.data.codigo_postal);
				if (result.data.regimen_fiscal) {
					const regimenEncontrado = encontrarRegimenPorTexto(
						result.data.regimen_fiscal
					);
					if (regimenEncontrado)
						setData("tax_regime", regimenEncontrado);
				}

				clearErrors();

				// Mostrar mensaje de éxito
				console.log("🔔 Archivo procesado:", fromPaste
					? "Archivo pegado y procesado exitosamente. Revisa los datos."
					: "La información se extrajo correctamente. Revisa los datos.");

				setInfoMessage({
					type: "success",
					message: fromPaste
						? "Archivo pegado y procesado exitosamente. Revisa los datos."
						: "La información se extrajo correctamente. Revisa los datos."
				});

				// Avanzar al paso de revisión después de un breve retraso
				console.log("⏰ Programando cambio a paso REVIEW en 800ms...");
				setTimeout(() => {
					console.log("🔄 Cambiando a paso REVIEW ahora");
					setProcessingPdf(false);
					setCurrentStep(null);
					setUploadProgress(0);
					setActiveStep(STEPS.REVIEW);

					// Enfocar el primer campo para mejor UX
					setTimeout(() => {
						const firstInput = document.querySelector('input[dusk="name"]');
						if (firstInput && !isEditing) {
							firstInput.focus();
						}
					}, 100);
				}, 800);

			} else {
				console.log("❌ Error en respuesta del servidor:", result?.message);
				console.log("🔔 Error al procesar el archivo:", result?.message || `Error ${response.status}`);

				// Manejar error pero mantener el archivo subido
				setUploadedFile(file);
				setData("fiscal_certificate", file);

				// Mostrar mensaje de error pero permitir continuar
				setInfoMessage({
					type: "warning",
					message: "No se pudo extraer información automáticamente. Complete los datos manualmente."
				});

				// Cambiar al paso 2 inmediatamente
				setProcessingPdf(false);
				setCurrentStep(null);
				setUploadProgress(0);
				setActiveStep(STEPS.REVIEW);
			}
		} catch (error) {
			console.error("💥 Error en extracción:", error.name, error.message);

			// Mostrar notificación de error específico
			let errorMessage = "Ocurrió un error al procesar el archivo.";
			if (error.message === "CSRF_TOKEN_NOT_FOUND") {
				errorMessage = "Error de seguridad. Por favor, recarga la página.";
			} else if (error.message === "INVALID_JSON_RESPONSE") {
				errorMessage = "Respuesta inválida del servidor.";
			} else if (error.name === "AbortError") {
				errorMessage = "Tiempo de espera agotado. El archivo es muy grande o hay problemas de conexión.";
			}

			// Fallback: subir archivo sin extracción
			console.log("🔄 Usando fallback: subiendo archivo sin extracción");
			setData("fiscal_certificate", file);
			setUploadedFile(file);

			// Cambiar al paso 2 inmediatamente
			setProcessingPdf(false);
			setCurrentStep(null);
			setUploadProgress(0);
			setActiveStep(STEPS.REVIEW);

			// Mostrar mensaje informativo
			console.log("🔔 Archivo subido:", fromPaste
				? "Archivo pegado. Completa los datos manualmente."
				: "Completa los datos manualmente.");

			setInfoMessage({
				type: "warning",
				message: fromPaste
					? "Archivo pegado. Completa los datos manualmente."
					: "Completa los datos manualmente."
			});

			// También mostrar notificación del error si es relevante
			if (error.message && !error.message.includes("timeout")) {
				console.log("🔔 Nota técnica:", `No se pudo extraer información automáticamente: ${error.message}`);
				setTimeout(() => {
					setInfoMessage({
						type: "info",
						message: `No se pudo extraer información automáticamente: ${error.message}`
					});
				}, 1000);
			}
		}
	};

	// Función para manejar la selección de archivo EN MODO AUTOMÁTICO
	const handleFileUpload = async (e) => {
		console.log("📁 handleFileUpload llamado (modo automático)");
		const file = e.target.files[0];
		if (file) {
			console.log("📄 Archivo seleccionado:", file.name);
			await handleFileProcess(file, false);
		} else {
			console.log("❌ No se seleccionó ningún archivo");
		}
	};

	// Función para manejar la selección de archivo EN MODO MANUAL
	const handleManualFileUpload = (e) => {
		console.log("📁 handleManualFileUpload llamado (modo manual)");
		const file = e.target.files[0];
		if (file) {
			console.log("📄 Archivo seleccionado para modo manual:", file.name);

			if (file.type !== "application/pdf") {
				console.log("❌ Tipo de archivo inválido:", file.type);
				setError("fiscal_certificate", "Solo se aceptan archivos PDF");
				return;
			}

			if (file.size > 5 * 1024 * 1024) {
				console.log("❌ Archivo muy grande:", file.size);
				setError("fiscal_certificate", "El archivo no debe superar 5MB");
				return;
			}

			setUploadedFile(file);
			setData("fiscal_certificate", file);
			clearErrors("fiscal_certificate");

			console.log("✅ Archivo subido en modo manual:", file.name);
		} else {
			console.log("❌ No se seleccionó ningún archivo");
		}
	};

	// Función para eliminar archivo subido
	const handleRemoveFile = () => {
		console.log("🗑️ Eliminando archivo subido");
		setUploadedFile(null);
		setExtractedData(null);
		setData("fiscal_certificate", null);
		clearErrors("fiscal_certificate");

		// Resetear el input file correspondiente
		if (entryMode === ENTRY_MODES.AUTOMATIC && fileInputRef.current) {
			fileInputRef.current.value = '';
		} else if (entryMode === ENTRY_MODES.MANUAL && manualFileInputRef.current) {
			manualFileInputRef.current.value = '';
		}
	};

	// Función de validación de RFC
	const validarRFC = (rfc) => {
		if (!rfc) return false;

		// Eliminar espacios y convertir a mayúsculas
		rfc = rfc.trim().toUpperCase();

		// Patrones de RFC actualizados (más flexibles)
		const patronFisica = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{2,3}$/; // 12-13 caracteres
		const patronMoral = /^[A-ZÑ&]{3}[0-9]{6}[A-Z0-9]{3}$/; // 12 caracteres

		// Validar longitud
		if (rfc.length < 12 || rfc.length > 13) {
			return false;
		}

		return patronFisica.test(rfc) || patronMoral.test(rfc);
	};

	const encontrarRegimenPorTexto = (textoRegimen) => {
		if (!textoRegimen || !cachedTaxRegimes) return null;

		const textoLower = textoRegimen.toLowerCase();

		// Mapeo de términos comunes basado en tu catálogo
		const mapeoTerminos = {
			sueldos: "605",
			salarios: "605",
			arrendamiento: "606",
			empresariales: "612",
			profesionales: "612",
			incorporación: "621",
			fiscal: "621",
			resico: "626",
			simplificado: "626",
			confianza: "626",
			agrícolas: "622",
			ganaderas: "622",
			silvícolas: "622",
			pesqueras: "622",
			preferentes: "629",
			multinacionales: "629",
			enajenación: "630",
			dividendos: "611",
			intereses: "614",
			premios: "615",
			ingresos: "608",
			actividades: "612",
		};

		// Buscar por términos
		for (const [termino, clave] of Object.entries(mapeoTerminos)) {
			if (textoLower.includes(termino) && cachedTaxRegimes[clave]) {
				console.log(
					`Encontrado régimen por término "${termino}": ${clave}`
				);
				return clave; // Devuelve la clave (ej: "605")
			}
		}

		// Buscar por nombre de régimen en el catálogo
		for (const [key, regimen] of Object.entries(cachedTaxRegimes)) {
			const regimenLower = regimen.name.toLowerCase();
			if (
				textoLower.includes(regimenLower) ||
				regimenLower.includes(textoLower)
			) {
				console.log(
					`Encontrado régimen por nombre: ${key} - ${regimen.name}`
				);
				return key; // Devuelve la clave
			}
		}

		console.log(`No se encontró régimen para: ${textoRegimen}`);
		return null;
	};

	const handleNextStep = () => {
		console.log("➡️ handleNextStep llamado, activeStep actual:", activeStep);

		if (activeStep === STEPS.UPLOAD) {
			// Si estamos en el paso de selección de modo
			if (!isModeSelected) {
				console.log("❌ No se ha seleccionado un modo");
				setInfoMessage({
					type: "error",
					message: "Por favor selecciona un método para ingresar tus datos"
				});
				return;
			}

			// Si ya seleccionó modo pero aún no ha subido archivo (en modo automático)
			if (entryMode === ENTRY_MODES.AUTOMATIC && !uploadedFile) {
				console.log("❌ No se ha subido archivo en modo automático");
				setError("fiscal_certificate", "Debe subir una constancia fiscal");
				return;
			}

			// Avanzar al siguiente paso
			console.log("✅ Avanzando a REVIEW");
			setActiveStep(STEPS.REVIEW);

		} else if (activeStep === STEPS.REVIEW) {
			console.log("📋 Validando datos en paso REVIEW");

			// Validar campos requeridos antes de avanzar
			const requiredFields = {
				name: data.name,
				rfc: data.rfc,
				zipcode: data.zipcode,
				tax_regime: data.tax_regime
			};

			const missingFields = Object.entries(requiredFields)
				.filter(([_, value]) => !value)
				.map(([key]) => key);

			if (missingFields.length > 0) {
				console.log("❌ Campos incompletos:", missingFields);
				console.log("🔔 Complete todos los campos requeridos antes de continuar.");

				// Mostrar errores en los campos faltantes
				missingFields.forEach(field => {
					setError(field, `Este campo es requerido`);
				});

				setInfoMessage({
					type: "error",
					message: "Complete todos los campos requeridos antes de continuar."
				});
				return;
			}

			// Validar RFC
			if (data.rfc && !validarRFC(data.rfc)) {
				console.log("❌ RFC inválido:", data.rfc);
				setError("rfc", "Formato RFC inválido");
				console.log("🔔 Verifique el formato de su RFC.");

				setInfoMessage({
					type: "error",
					message: "Verifique el formato de su RFC."
				});
				return;
			}

			// Validar código postal
			if (data.zipcode && !/^\d{5}$/.test(data.zipcode)) {
				console.log("❌ Código postal inválido:", data.zipcode);
				setError("zipcode", "Debe tener 5 dígitos");
				console.log("🔔 El código postal debe tener 5 dígitos.");

				setInfoMessage({
					type: "error",
					message: "El código postal debe tener 5 dígitos."
				});
				return;
			}

			// Constancia obligatoria en ambos modos antes del resumen
			if (!uploadedFile || !data.fiscal_certificate) {
				console.log("❌ No se ha subido constancia fiscal");
				setError("fiscal_certificate", "Debe subir una constancia fiscal");
				setInfoMessage({
					type: "error",
					message: "Debe subir una constancia fiscal"
				});
				return;
			}

			console.log("✅ Todos los campos válidos, avanzando a CONFIRM");
			setActiveStep(STEPS.CONFIRM);
		}
	};

	const handlePrevStep = () => {
		console.log("⬅️ handlePrevStep llamado, activeStep actual:", activeStep);

		if (activeStep === STEPS.REVIEW) {
			console.log("🔄 Regresando a UPLOAD desde REVIEW");
			setActiveStep(STEPS.UPLOAD);

		} else if (activeStep === STEPS.CONFIRM) {
			console.log("🔄 Regresando a REVIEW desde CONFIRM");
			setActiveStep(STEPS.REVIEW);
		}
	};

	// Función para enviar el formulario
	const submit = async (e) => {
		e.preventDefault();

		// Solo guardar en el paso de confirmación; en otros pasos avanzar
		if (activeStep !== STEPS.CONFIRM) {
			handleNextStep();
			return;
		}

		console.log('=== GUARDANDO PERFIL FISCAL ===');

		// Resetear estados
		setInfoMessage(null);
		clearErrors();
		setIsSaving(true);
		setSaveStep("Validando datos...");

		// Permitir que React pinte el estado de carga antes del trabajo pesado
		await new Promise((resolve) => {
			requestAnimationFrame(() => requestAnimationFrame(resolve));
		});

		try {
			// Validaciones finales antes de enviar
			// Requerir archivo en ambos modos
			if (!data.fiscal_certificate) {
				setError("fiscal_certificate", "Debe subir una constancia fiscal");
				setIsSaving(false);
				return;
			}

			if (extractedData && !data.confirm_data) {
				setError(
					"confirm_data",
					"Debe confirmar que los datos extraídos son correctos",
				);
				setIsSaving(false);
				return;
			}

			// Preparar FormData
			const formData = new FormData();
			formData.append('name', data.name);
			formData.append('rfc', data.rfc);
			formData.append('zipcode', data.zipcode);
			formData.append('tax_regime', data.tax_regime);
			formData.append('cfdi_use', data.cfdi_use || 'G03');
			formData.append('entry_mode', entryMode);

			// Agregar archivo (obligatorio en ambos modos)
			formData.append('fiscal_certificate', data.fiscal_certificate);

			formData.append('confirm_data', data.confirm_data ? '1' : '0');

			if (extractedData) {
				formData.append('extracted_data', JSON.stringify(extractedData));
			}

			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
			if (csrfToken) {
				formData.append('_token', csrfToken);
			}

			// Determinar URL
			let url = route("tax-profiles.store");
			let method = 'POST';

			if (cachedEditMode && cachedTaxProfile) {
				formData.append('_method', 'PUT');
				url = route("tax-profiles.update", { tax_profile: cachedTaxProfile });
			}

			console.log('🌐 Enviando a:', url);

			setSaveStep('Enviando al servidor...');

			// Hacer la petición
			const response = await fetch(url, {
				method: method,
				body: formData,
				headers: {
					'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
				},
			});

			console.log('📡 Respuesta:', response.status, response.statusText);

			// Leer respuesta
			const responseText = await response.text();

			// Intentar parsear JSON
			try {
				const result = JSON.parse(responseText);
				console.log('📋 Resultado:', result);

				if (response.ok && result.success) {
					// Éxito - Mostrar mensaje de éxito
					const successTitle = cachedEditMode
						? '¡Perfil actualizado!'
						: '¡Perfil creado exitosamente!';

					const successMessage = cachedEditMode
						? 'Tu perfil fiscal ha sido actualizado correctamente.'
						: 'Tu perfil fiscal ha sido creado correctamente.';

					console.log(`🔔 ${successTitle}: ${successMessage}`);

					setInfoMessage({
						type: "success",
						message: successMessage
					});

					// Redirigir después de 2 segundos (para que el usuario vea el mensaje)
					setTimeout(() => {
						router.visit(route("tax-profiles.index"), {
							preserveState: true,
							preserveScroll: true,
						});
					}, 2000);

				} else {
					// Error del servidor
					console.error('❌ Error del servidor:', result);

					// Mostrar error específico si existe
					if (result.errors) {
						// Mostrar errores de validación de Laravel
						Object.keys(result.errors).forEach(key => {
							setError(key, result.errors[key][0]);
						});

						console.log('🔔 Por favor corrija los errores en el formulario.');

						setInfoMessage({
							type: "error",
							message: 'Por favor corrija los errores en el formulario.'
						});

					} else if (result.message) {
						// Mostrar mensaje de error general
						console.log(`🔔 Error: ${result.message}`);

						setInfoMessage({
							type: "error",
							message: result.message
						});

						// Si el error es sobre RFC duplicado, mostrarlo en el campo
						if (result.message.includes('RFC') || result.message.includes('rfc')) {
							setError('rfc', result.message);
						}
					}
				}

			} catch (jsonError) {
				console.error('❌ Error parseando JSON:', jsonError);
				console.log('📄 Texto de respuesta:', responseText.substring(0, 500));

				// Si no es JSON pero la respuesta fue exitosa, asumir éxito
				if (response.ok) {
					console.log('⚠️ Respuesta exitosa no-JSON');
					console.log('🔔 La operación se completó correctamente.');

					// Mostrar mensaje de éxito
					const successTitle = cachedEditMode
						? '¡Perfil actualizado!'
						: '¡Perfil creado exitosamente!';

					setInfoMessage({
						type: "success",
						message: 'La operación se completó correctamente.'
					});

					// Redirigir después de 2 segundos
					setTimeout(() => {
						router.visit(route("tax-profiles.index"), {
							preserveState: true,
							preserveScroll: true,
						});
					}, 2000);

				} else {
					console.log('🔔 Error del servidor. Por favor intente nuevamente.');

					setInfoMessage({
						type: "error",
						message: 'Por favor intente nuevamente.'
					});
				}
			}

		} catch (error) {
			console.error('💥 Error de red:', error);
			console.log('🔔 Error de conexión. Verifique su internet e intente nuevamente.');

			setInfoMessage({
				type: "error",
				message: 'Verifique su internet e intente nuevamente.'
			});

		} finally {
			setIsSaving(false);
			setSaveStep('');
		}
	};

	const closeDialog = () => {
		console.log("❌ Cerrando diálogo");
		router.get(
			route("tax-profiles.index"),
			{},
			{ preserveState: true, preserveScroll: true }
		);
	};

	// Paso 1: Selección de modo de entrada
	const renderModeSelectionStep = () => {
		console.log("🖼️ Renderizando paso de selección de modo, isModeSelected:", isModeSelected);

		return (
			<>
				<DialogTitle>
					{cachedEditMode
						? "Actualizar perfil fiscal"
						: "Nuevo perfil fiscal"}
				</DialogTitle>
				<DialogDescription>
					Selecciona cómo deseas ingresar tu información fiscal
				</DialogDescription>

				<DialogBody className="space-y-6">
					<div className="grid grid-cols-1 md:grid-cols-2 gap-6">
						{/* Opción 1: Modo Automático */}
						<div
							className={`p-6 rounded-xl border-2 cursor-pointer transition-all duration-200 hover:shadow-md ${entryMode === ENTRY_MODES.AUTOMATIC
								? 'border-blue-500 bg-blue-50'
								: 'border-gray-200 bg-white hover:border-gray-300'}`}
							onClick={() => handleEntryModeChange(ENTRY_MODES.AUTOMATIC)}
						>
							<div className="text-center mb-4">
								<div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 mb-4">
									<DocumentArrowUpIcon className="h-8 w-8 text-blue-600" />
								</div>
								<h3 className="text-lg font-semibold text-gray-900 mb-2">
									Extracción Automática
								</h3>
								<p className="text-sm text-gray-600">
									Recomendado
								</p>
							</div>

							<div className="space-y-3">
								<div className="flex items-start">
									<CheckCircleIcon className="h-5 w-5 text-green-500 mr-2 mt-0.5 flex-shrink-0" />
									<span className="text-sm text-gray-700">
										Sube tu constancia fiscal (PDF)
									</span>
								</div>
								<div className="flex items-start">
									<CheckCircleIcon className="h-5 w-5 text-green-500 mr-2 mt-0.5 flex-shrink-0" />
									<span className="text-sm text-gray-700">
										Analisis automático
									</span>
								</div>
								<div className="flex items-start">
									<CheckCircleIcon className="h-5 w-5 text-green-500 mr-2 mt-0.5 flex-shrink-0" />
									<span className="text-sm text-gray-700">
										Revisa y confirma
									</span>
								</div>
							</div>

							{entryMode === ENTRY_MODES.AUTOMATIC && (
								<div className="mt-6 text-center">
									<div className="text-sm font-medium text-blue-700 mb-2">
										✓ Seleccionado
									</div>
									<p className="text-xs text-gray-500">
										Haz clic en "Continuar" para subir tu archivo
									</p>
								</div>
							)}
						</div>

						{/* Opción 2: Modo Manual */}
						<div
							className={`p-6 rounded-xl border-2 cursor-pointer transition-all duration-200 hover:shadow-md ${entryMode === ENTRY_MODES.MANUAL
								? 'border-green-500 bg-green-50'
								: 'border-gray-200 bg-white hover:border-gray-300'}`}
							onClick={() => handleEntryModeChange(ENTRY_MODES.MANUAL)}
						>
							<div className="text-center mb-4">
								<div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-100 mb-4">
									<PencilSquareIcon className="h-8 w-8 text-green-600" />
								</div>
								<h3 className="text-lg font-semibold text-gray-900 mb-2">
									Llenado Manual
								</h3>
								<p className="text-sm text-gray-600">
									Sus datos fiscales
								</p>
							</div>

							<div className="space-y-3">
								<div className="flex items-start">
									<CheckCircleIcon className="h-5 w-5 text-green-500 mr-2 mt-0.5 flex-shrink-0" />
									<span className="text-sm text-gray-700">
										Ingresa tus datos manualmente
									</span>
								</div>
								<div className="flex items-start">
									<CheckCircleIcon className="h-5 w-5 text-green-500 mr-2 mt-0.5 flex-shrink-0" />
									<span className="text-sm text-gray-700">
										Sube tu constancia fiscal (PDF)
									</span>
								</div>
								<div className="flex items-start">
									<CheckCircleIcon className="h-5 w-5 text-green-500 mr-2 mt-0.5 flex-shrink-0" />
									<span className="text-sm text-gray-700">
										Útil si la lectura automática no reconoce tu .PDF
									</span>
								</div>
							</div>

							{entryMode === ENTRY_MODES.MANUAL && (
								<div className="mt-6 text-center">
									<div className="text-sm font-medium text-green-700 mb-2">
										✓ Seleccionado
									</div>
									<p className="text-xs text-gray-500">
										Haz clic en "Continuar" para ingresar tus datos
									</p>
								</div>
							)}
						</div>
					</div>

				</DialogBody>

				<DialogActions>
					<Button
						autoFocus
						dusk="cancel"
						plain
						type="button"
						onClick={closeDialog}
						disabled={processingPdf || isSaving}
					>
						Cancelar
					</Button>
					<Button
						type="button"
						onClick={handleNextStep}
						disabled={!isModeSelected || processingPdf || isSaving}
					>
						Continuar
						<ChevronRightIcon className="ml-2 h-4 w-4" />
					</Button>
				</DialogActions>
			</>
		);
	};

	// Paso 1B: Subir archivo (solo para modo automático)
	const renderUploadStep = () => {
		console.log("🖼️ Renderizando paso UPLOAD (modo automático)");

		return (
			<>
				<DialogTitle>
					<button
						type="button"
						onClick={() => {
							// Regresar a la selección de modo
							setActiveStep(STEPS.UPLOAD);
							setUploadedFile(null);
							setExtractedData(null);
							setData("fiscal_certificate", null);
							clearErrors();
						}}
						className="flex items-center text-sm text-gray-500 hover:text-gray-700 mb-2"
					>
						<ArrowLeftIcon className="h-4 w-4 mr-1" />
						Cambiar método
					</button>
					Sube tu constancia fiscal
				</DialogTitle>
				<DialogDescription>
					Sube el archivo PDF de tu constancia para extraer automáticamente tus datos
				</DialogDescription>

				<DialogBody className="space-y-6">
					<Field>
						<div className="space-y-4">
							{uploadedFile && (
									<div className="rounded-xl border-2 border-solid border-green-200 bg-green-50 p-8 text-center">
										<div className="space-y-4">
												<div className="flex items-center justify-center">
													<div className="p-3 bg-green-100 rounded-full">
														<CheckCircleIcon className="h-10 w-10 text-green-600" />
													</div>
												</div>
												<div>
													<h4 className="font-semibold text-green-800 text-lg">
														¡Archivo listo!
													</h4>
													<p className="text-sm text-green-600 mt-1">
														{uploadedFile.name}
													</p>
													<p className="text-xs text-green-500 mt-1">
														{(uploadedFile.size / 1024 / 1024).toFixed(2)} MB
													</p>
												</div>
												<Button
													type="button"
													onClick={handleRemoveFile}
													className="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700"
													disabled={processingPdf || isSaving}
												>
													<XMarkIcon className="h-4 w-4" />
													Cambiar archivo
												</Button>
											</div>
									</div>
								)}

							{processingPdf && (
								<div className="space-y-2">
									<div className="w-full bg-gray-200 rounded-full h-2.5">
										<div
											className="bg-blue-600 h-2.5 rounded-full transition-all duration-300"
											style={{ width: `${uploadProgress}%` }}
										></div>
									</div>
									<div className="flex justify-between text-sm text-gray-600">
										<span>{currentStep || "Procesando..."}</span>
										<span>{uploadProgress}%</span>
									</div>
								</div>
							)}

							{errors.fiscal_certificate && (
								<ErrorMessage>{errors.fiscal_certificate}</ErrorMessage>
							)}
						</div>
					</Field>

					{!uploadedFile && !processingPdf && (
						<div className="rounded-xl border border-slate-200 bg-slate-50/80 p-6 dark:border-slate-700 dark:bg-slate-800/40">
							<div className="mx-auto max-w-sm text-center">
								<div className="text-center p-4">
									<div className="w-12 h-12 mx-auto mb-3 flex items-center justify-center bg-blue-100 rounded-full">
										<ArrowUpTrayIcon className="h-6 w-6 text-blue-600" />
									</div>
									<p className="text-base font-semibold text-slate-900 dark:text-white">
										Selecciona tu constancia fiscal
									</p>
									<p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
										Busca el archivo PDF en tu computadora
									</p>
									<Button
										type="button"
										onClick={() => fileInputRef.current?.click()}
										className="mt-5 inline-flex items-center gap-2"
										disabled={processingPdf || isSaving}
									>
										<ArrowUpTrayIcon className="h-4 w-4" />
										Seleccionar archivo
									</Button>
									<input
										ref={fileInputRef}
										type="file"
										className="hidden"
										accept="application/pdf"
										onChange={handleFileUpload}
										disabled={processingPdf || isSaving}
									/>
								</div>
							</div>
							<div className="mt-4 pt-4 border-t border-gray-200">
								<p className="text-xs text-gray-500">
									<strong>Requisitos:</strong> Solo archivos PDF • Máximo 5MB • Constancia emitida en los últimos 3 meses
								</p>
							</div>
						</div>
					)}

					{/* Opción para cambiar a modo manual si hay problemas */}
					{uploadedFile && !processingPdf && (
						<div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
							<div className="flex items-center justify-between">
								<div>
									<p className="text-sm text-gray-700">
										¿Problemas con la extracción automática?
									</p>
									<p className="text-xs text-gray-500 mt-1">
										Puedes cambiar al modo manual si no se extraen los datos correctamente
									</p>
								</div>
								<Button
									type="button"
									plain
									onClick={() => {
										setEntryMode(ENTRY_MODES.MANUAL);
										// Mantener el archivo subido
										setActiveStep(STEPS.REVIEW);
									}}
									disabled={processingPdf || isSaving}
								>
									Cambiar a modo manual
								</Button>
							</div>
						</div>
					)}
				</DialogBody>

				<DialogActions>
					<Button
						autoFocus
						dusk="cancel"
						plain
						type="button"
						onClick={closeDialog}
						disabled={processingPdf || isSaving}
					>
						Cancelar
					</Button>
					<Button
						type="button"
						disabled={!uploadedFile || processingPdf || isSaving}
						onClick={handleNextStep}
					>
						Continuar
						<ChevronRightIcon className="ml-2 h-4 w-4" />
					</Button>
				</DialogActions>
			</>
		);
	};

	// Paso 2: Revisar y editar información (común para ambos modos)
	const renderReviewStep = () => {
		console.log("🖼️ Renderizando paso REVIEW, entryMode:", entryMode);

		return (
			<>
				<DialogTitle>
					{entryMode === ENTRY_MODES.MANUAL ? (
						<>
							<button
								type="button"
								onClick={() => {
									// Regresar al paso anterior
									if (uploadedFile) {
										// Si ya tiene archivo, regresar a UPLOAD
										setActiveStep(STEPS.UPLOAD);
									} else {
										// Si no tiene archivo, regresar a selección de modo
										setActiveStep(STEPS.UPLOAD);
										setIsModeSelected(true);
									}
								}}
								className="flex items-center text-sm text-gray-500 hover:text-gray-700 mb-2"
							>
								<ArrowLeftIcon className="h-4 w-4 mr-1" />
								Volver
							</button>
							Completa tu información fiscal
						</>
					) : (
						<>Revisa y completa tu información</>
					)}
				</DialogTitle>
				<DialogDescription>
					{entryMode === ENTRY_MODES.MANUAL
						? "Ingresa manualmente tus datos fiscales"
						: "Verifica los datos extraídos y completa los campos faltantes"}
				</DialogDescription>

				<DialogBody className="space-y-6">
					{/* Mostrar información del archivo */}
					{uploadedFile && (
						<FileInfo file={uploadedFile} onRemove={handleRemoveFile} />
					)}

					{/* Mensaje informativo */}
					{infoMessage && (
						<div
							className={`rounded-lg p-4 ${infoMessage.type === "success"
								? "bg-green-50 border border-green-200"
								: infoMessage.type === "error"
									? "bg-red-50 border border-red-200"
									: "bg-yellow-50 border border-yellow-200"
								}`}
						>
							<div className="flex items-center">
								{infoMessage.type === "success" ? (
									<CheckCircleIcon className="h-5 w-5 text-green-400 mr-2" />
								) : infoMessage.type === "error" ? (
									<ExclamationTriangleIcon className="h-5 w-5 text-red-400 mr-2" />
								) : (
									<ExclamationTriangleIcon className="h-5 w-5 text-yellow-400 mr-2" />
								)}
								<span className="font-medium">
									{infoMessage.message}
								</span>
							</div>
						</div>
					)}

					{/* Mostrar resumen de datos extraídos solo en modo automático */}
					{extractedData && entryMode === ENTRY_MODES.AUTOMATIC && (
						<div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
							<h4 className="font-semibold text-blue-800 mb-2 flex items-center">
								<DocumentTextIcon className="h-5 w-5 mr-2" />
								Resumen de datos extraídos
							</h4>
							<div className="grid grid-cols-2 gap-3 text-sm">
								<div>
									<SimpleLabel>RFC:</SimpleLabel>
									<div className="font-mono">
										{extractedData.rfc || "No detectado"}
									</div>
								</div>
								<div>
									<SimpleLabel>Régimen:</SimpleLabel>
									<div>
										{extractedData.regimen_fiscal ||
											"No detectado"}
									</div>
								</div>
							</div>
							<button
								type="button"
								onClick={() => {
									console.log("✏️ Cambiando modo edición a:", !isEditing);
									setIsEditing(!isEditing);
								}}
								className="mt-3 text-sm text-blue-600 hover:text-blue-800 flex items-center"
								disabled={isSaving}
							>
								<PencilIcon className="h-4 w-4 mr-1" />
								{isEditing ? "Ver resumen" : "Editar datos"}
							</button>
						</div>
					)}

					{/* Campos del formulario - Siempre editables en modo manual, en modo automático solo si isEditing es true */}
					{(isEditing || !extractedData || entryMode === ENTRY_MODES.MANUAL) ? (
						<>
							<Field>
								<Label>Nombre*</Label>
								<Input
									dusk="name"
									required
									invalid={!!errors.name}
									value={data.name}
									onChange={(e) =>
										setData("name", e.target.value)
									}
									type="text"
									autoComplete="given-name"
									disabled={isSaving}
									placeholder="Ej: Juan Pérez García"
								/>
								{errors.name && (
									<ErrorMessage>{errors.name}</ErrorMessage>
								)}
							</Field>

							<Field>
								<Label>RFC *</Label>
								<Input
									dusk="rfc"
									required
									invalid={!!errors.rfc}
									value={data.rfc}
									onChange={(e) => {
										const value = e.target.value
											.toUpperCase()
											.replace(/[^A-Z0-9&Ñ]/g, "");
										setData("rfc", value);
										clearErrors("rfc");

										// Validación en tiempo real
										if (value && !validarRFC(value)) {
											setError("rfc", "Formato RFC inválido");
										}
									}}
									type="text"
									disabled={isSaving}
									placeholder="Ej: MEBE931209BI2 (13 caracteres) o ABC123456XYZ (12 caracteres)"
								/>
								{errors.rfc && (
									<ErrorMessage>{errors.rfc}</ErrorMessage>
								)}
								{data.rfc && !errors.rfc && (
									<p className="text-xs text-gray-500 mt-1">
										{data.rfc.length === 12
											? "Persona Moral (12 caracteres)"
											: data.rfc.length === 13
												? "Persona Física (13 caracteres)"
												: "Formato: XXXX999999XXX o XXX999999XXX"}
									</p>
								)}
							</Field>

							<Field>
								<Label>Código postal *</Label>
								<Input
									dusk="zipcode"
									required
									invalid={!!errors.zipcode}
									type="text"
									autoComplete="postal-code"
									value={data.zipcode}
									onChange={(e) => {
										const value = e.target.value
											.replace(/\D/g, "")
											.slice(0, 5);
										setData("zipcode", value);
										clearErrors("zipcode");

										// Validación en tiempo real
										if (value && !/^\d{5}$/.test(value)) {
											setError(
												"zipcode",
												"Debe tener 5 dígitos"
											);
										}
									}}
									disabled={isSaving}
									placeholder="Ej: 64000"
								/>
								{errors.zipcode && (
									<ErrorMessage>{errors.zipcode}</ErrorMessage>
								)}
							</Field>

							<Field>
								<Label>Régimen fiscal *</Label>
								<Listbox
									invalid={!!errors.tax_regime}
									placeholder="Selecciona un régimen fiscal"
									value={data.tax_regime}
									onChange={(value) => {
										console.log(
											"Régimen seleccionado:",
											value,
											cachedTaxRegimes?.[value]
										);
										setData("tax_regime", value);
										clearErrors("tax_regime");
									}}
									disabled={isSaving}
								>
									{Object.keys(cachedTaxRegimes || {}).length >
										0 ? (
										Object.entries(cachedTaxRegimes).map(
											([key, regimen]) => (
												<ListboxOption key={key} value={key}>
													<ListboxLabel>{`${key} - ${regimen?.name ||
														"Desconocido"
														}`}</ListboxLabel>
												</ListboxOption>
											)
										)
									) : (
										<ListboxOption value="" disabled>
											<ListboxLabel>
												Cargando regímenes...
											</ListboxLabel>
										</ListboxOption>
									)}
								</Listbox>
								{errors.tax_regime && (
									<ErrorMessage>{errors.tax_regime}</ErrorMessage>
								)}
								{data.tax_regime &&
									cachedTaxRegimes?.[data.tax_regime] && (
										<p className="text-xs text-gray-500 mt-1">
											{
												cachedTaxRegimes[data.tax_regime]
													.description
											}
										</p>
									)}
							</Field>

							{/* Subida de archivo en modo manual (si aún no se ha subido) */}
							{entryMode === ENTRY_MODES.MANUAL && !uploadedFile && (
								<Field>


									<div className="space-y-3">
										<div className="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
											<DocumentTextIcon className="h-12 w-12 text-gray-400 mx-auto mb-3" />
											<p className="text-sm text-gray-600 mb-4">
												Sube tu constancia fiscal en PDF (máximo 5MB)
											</p>
											<Button
												type="button"
												onClick={(e) => {
													e.stopPropagation();
													manualFileInputRef.current?.click();
												}}
												className="inline-flex items-center gap-2"
												disabled={isSaving}
											>
												<ArrowUpTrayIcon className="h-4 w-4" />
												Seleccionar archivo
												<input
													ref={manualFileInputRef}
													type="file"
													className="hidden"
													accept="application/pdf"
													onChange={handleManualFileUpload}
													disabled={isSaving}
												/>
											</Button>
										</div>
										{errors.fiscal_certificate && (
											<ErrorMessage>{errors.fiscal_certificate}</ErrorMessage>
										)}
									</div>
								</Field>
							)}

							<Field className="hidden">
								<Label>Uso del CFDI *</Label>
								<select
									value={data.cfdi_use}
									onChange={(e) =>
										setData("cfdi_use", e.target.value)
									}
									className="block w-full rounded-lg border-gray-300 px-3 py-2 focus:border-blue-500 focus:ring-blue-500"
									disabled={isSaving}
								>
									<option value="G03">
										G03 - Gastos en general
									</option>
									<option value="G01">
										G01 - Adquisición de mercancías
									</option>
									<option value="G02">
										G02 - Devoluciones, descuentos o
										bonificaciones
									</option>
									<option value="P01">P01 - Por definir</option>
									<option value="D01">
										D01 - Honorarios médicos, dentales y gastos
										hospitalarios
									</option>
									<option value="D02">
										D02 - Gastos de funeral
									</option>
									<option value="D03">D03 - Donativos</option>
									<option value="D04">
										D04 - Intereses reales efectivamente pagados
										por créditos hipotecarios
									</option>
									<option value="D05">
										D05 - Aportaciones voluntarias al SAR
									</option>
									<option value="D06">
										D06 - Primas por seguros de gastos médicos
									</option>
									<option value="D07">
										D07 - Gastos de transportación escolar
										obligatoria
									</option>
									<option value="D08">
										D08 - Depósitos en cuentas para el ahorro
									</option>
									<option value="D09">
										D09 - Pagos por servicios educativos
										(colegiaturas)
									</option>
								</select>
								<Description>
									Para gastos médicos use "G03 - Gastos en
									general".
								</Description>
							</Field>
						</>
					) : (
						<div className="space-y-4">
							<div className="grid grid-cols-2 gap-4">
								<div className="bg-gray-50 p-4 rounded-lg">
									<SimpleLabel>RFC</SimpleLabel>
									<div className="font-mono text-lg">
										{data.rfc || "No ingresado"}
									</div>
								</div>
								<div className="bg-gray-50 p-4 rounded-lg">
									<SimpleLabel>Código Postal</SimpleLabel>
									<div className="text-lg">
										{data.zipcode || "No ingresado"}
									</div>
								</div>
							</div>
							<div className="bg-gray-50 p-4 rounded-lg">
								<SimpleLabel>Régimen Fiscal</SimpleLabel>
								<div className="text-lg">
									{data.tax_regime && cachedTaxRegimes?.[data.tax_regime]
										? `${data.tax_regime} - ${cachedTaxRegimes[data.tax_regime]
											.name
										}`
										: "No seleccionado"}
								</div>
								{data.tax_regime &&
									cachedTaxRegimes?.[data.tax_regime] && (
										<p className="text-sm text-gray-600 mt-2">
											{
												cachedTaxRegimes[data.tax_regime]
													.description
											}
										</p>
									)}
							</div>
							<div className="bg-gray-50 p-4 rounded-lg hidden">
								<SimpleLabel>Uso del CFDI</SimpleLabel>
								<div className="text-lg">
									{data.cfdi_use || "G03"}
								</div>
							</div>
						</div>
					)}
				</DialogBody>

				<DialogActions>
					<Button
						plain
						type="button"
						onClick={handlePrevStep}
						disabled={isSaving}
					>
						{entryMode === ENTRY_MODES.AUTOMATIC ? "Cambiar archivo" : "Volver"}
					</Button>
					<div className="flex gap-2">
						{extractedData && entryMode === ENTRY_MODES.AUTOMATIC && (
							<Button
								plain
								type="button"
								onClick={() => setIsEditing(!isEditing)}
								disabled={isSaving}
							>
								{isEditing ? "Ver resumen" : "Editar datos"}
							</Button>
						)}
						<Button
							type="button"
							onClick={handleNextStep}
							disabled={isSaving}
						>
							Continuar
							<ChevronRightIcon className="ml-2 h-4 w-4" />
						</Button>
					</div>
				</DialogActions>
			</>
		);
	};

	// Paso 3: Confirmar y guardar (común para ambos modos)
	const renderConfirmStep = () => {
		console.log("🖼️ Renderizando paso CONFIRM, entryMode:", entryMode);

		return (
			<>
				<DialogTitle>Confirma tu información</DialogTitle>
				<DialogDescription>
					Verifica que todos los datos sean correctos antes de guardar
				</DialogDescription>

				<DialogBody className="space-y-6">
					<TaxProfileCompactAlert>
						<strong className="font-medium">¡Importante!</strong> Los datos
						que confirmes se utilizarán para solicitar tus facturas.
						Asegúrate de que sean correctos; errores podrían afectar la
						validez fiscal de tus compras.
					</TaxProfileCompactAlert>

					<div className="bg-gray-50 border border-gray-200 rounded-lg p-6">
						<h4 className="font-semibold text-gray-800 mb-4">
							Resumen de tu perfil fiscal
						</h4>
						<div className="space-y-4">
							<div className="grid grid-cols-2 gap-4">
								<div>
									<SimpleLabel className="text-gray-500">
										RFC
									</SimpleLabel>
									<div className="font-mono">{data.rfc}</div>
								</div>
								<div>
									<SimpleLabel className="text-gray-500">
										Nombre
									</SimpleLabel>
									<div className="font-medium">{data.name}</div>
								</div>
							</div>
							<div className="grid grid-cols-2 gap-4">
								<div>
									<SimpleLabel className="text-gray-500">
										Código Postal
									</SimpleLabel>
									<div>{data.zipcode}</div>
								</div>
								<div>
									<SimpleLabel className="text-gray-500">
										Régimen Fiscal
									</SimpleLabel>
									<div>
										{cachedTaxRegimes?.[data.tax_regime]
											? `${data.tax_regime} - ${cachedTaxRegimes[data.tax_regime]
												.name
											}`
											: data.tax_regime}
									</div>
									{cachedTaxRegimes?.[data.tax_regime] && (
										<p className="text-sm text-gray-600 mt-1">
											{
												cachedTaxRegimes[data.tax_regime]
													.description
											}
										</p>
									)}
								</div>
							</div>
							<div className="hidden">
								<SimpleLabel className="text-gray-500">
									Uso del CFDI
								</SimpleLabel>
								<div>{data.cfdi_use || "G03"}</div>
							</div>
						</div>
					</div>

					{/* Información del archivo */}
					{uploadedFile && (
						<div className="bg-green-50 border border-green-200 rounded-lg p-4">
							<div className="flex items-center space-x-3">
								<div className="p-2 bg-green-100 rounded-lg">
									<DocumentTextIcon className="h-6 w-6 text-green-600" />
								</div>
								<div>
									<h4 className="font-medium text-green-800 text-sm">
										Archivo adjunto
									</h4>
									<p className="text-xs text-green-700 mt-1">
										{uploadedFile.name} • {(uploadedFile.size / 1024 / 1024).toFixed(2)} MB
									</p>
								</div>
							</div>
						</div>
					)}

					{extractedData && entryMode === ENTRY_MODES.AUTOMATIC && (
						<div className="border-t pt-4">
							<label className="flex items-start space-x-3">
								<input
									type="checkbox"
									checked={data.confirm_data}
									onChange={(e) => {
										setData("confirm_data", e.target.checked);
										clearErrors("confirm_data");
									}}
									className="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
									disabled={isSaving}
								/>
								<div>
									<span className="font-medium text-slate-900 dark:text-white">
										Confirmo que los datos extraídos de mi
										constancia son correctos
									</span>
									<p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
										He verificado que toda la información
										coincide con mi constancia de situación
										fiscal y está actualizada.
									</p>
									{errors.confirm_data && (
										<p className="mt-1 text-sm text-red-600">
											{errors.confirm_data}
										</p>
									)}
								</div>
							</label>
						</div>
					)}
				</DialogBody>

				<DialogActions>
					<Button
						plain
						type="button"
						onClick={handlePrevStep}
						disabled={isSaving}
					>
						Volver a editar
					</Button>
					<div className="flex gap-2">
						<Button
							dusk="saveTaxProfile"
							type="submit"
							disabled={
								isSaving ||
								(extractedData && !data.confirm_data)
							}
							aria-busy={isSaving}
							aria-live="polite"
							className={`min-w-[11.5rem] transition-opacity ${
								isSaving ? "cursor-wait opacity-90" : ""
							}`}
						>
							{isSaving ? (
								<span className="inline-flex items-center justify-center gap-2">
									<ArrowPathIcon
										className="h-4 w-4 shrink-0 animate-spin"
										aria-hidden
									/>
									<span>Guardando...</span>
								</span>
							) : (
								<span>
									{cachedEditMode
										? "Actualizar perfil"
										: "Guardar perfil fiscal"}
								</span>
							)}
						</Button>
					</div>
				</DialogActions>
			</>
		);
	};

	// Componente Label personalizado para usar fuera de Field
	const SimpleLabel = ({ children, className = "" }) => (
		<div className={`text-sm font-medium text-gray-700 ${className}`}>
			{children}
		</div>
	);

	// Componente para mostrar progreso de guardado
	const SavingProgress = () => (
		<div
			className="fixed inset-0 z-[200] flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-[2px]"
			role="alertdialog"
			aria-modal="true"
			aria-labelledby="saving-progress-title"
			aria-describedby="saving-progress-desc"
		>
			<div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
				<div className="text-center">
					<div className="relative mx-auto mb-5 flex h-14 w-14 items-center justify-center">
						<span className="absolute inset-0 animate-ping rounded-full bg-blue-500/20" />
						<span className="relative flex h-14 w-14 items-center justify-center rounded-full bg-blue-500/10 ring-1 ring-blue-500/30">
							<ArrowPathIcon
								className="h-7 w-7 animate-spin text-blue-600 dark:text-blue-400"
								aria-hidden
							/>
						</span>
					</div>
					<h3
						id="saving-progress-title"
						className="text-lg font-semibold text-slate-900 dark:text-white"
					>
						{cachedEditMode
							? "Actualizando perfil fiscal..."
							: "Guardando perfil fiscal..."}
					</h3>
					<p
						id="saving-progress-desc"
						className="mt-2 text-sm text-slate-600 dark:text-slate-400"
					>
						{saveStep}
					</p>
					<div className="mt-5 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
						<div className="h-full w-full animate-pulse rounded-full bg-gradient-to-r from-blue-400 via-blue-600 to-blue-400 dark:from-blue-500 dark:via-blue-400 dark:to-blue-500" />
					</div>
					<p className="mt-4 text-xs text-slate-500 dark:text-slate-500">
						Por favor no cierres esta ventana
					</p>
				</div>
			</div>
		</div>
	);

	// Componente para mostrar información del archivo subido
	const FileInfo = ({ file, onRemove }) => (
		<div className="bg-green-50 border border-green-200 rounded-lg p-4">
			<div className="flex items-center justify-between">
				<div className="flex items-center space-x-3">
					<div className="p-2 bg-green-100 rounded-lg">
						<DocumentTextIcon className="h-6 w-6 text-green-600" />
					</div>
					<div>
						<h4 className="font-medium text-green-800 text-sm">
							Archivo listo para procesar
						</h4>
						<p className="text-xs text-green-700 mt-1">
							{file.name} • {(file.size / 1024 / 1024).toFixed(2)} MB
						</p>
					</div>
				</div>
				<button
					type="button"
					onClick={onRemove}
					className="p-1 hover:bg-red-100 rounded-full transition-colors"
					disabled={processingPdf || isSaving}
				>
					<XMarkIcon className="h-5 w-5 text-red-500" />
				</button>
			</div>
		</div>
	);

	// Renderizar contenido basado en el paso activo y modo seleccionado
	const renderContent = () => {
		console.log("🎬 renderContent llamado, activeStep:", activeStep, "isModeSelected:", isModeSelected, "entryMode:", entryMode);

		if (activeStep === STEPS.UPLOAD) {
			// Si ya seleccionó modo automático y no ha subido archivo, mostrar pantalla de subida
			if (isModeSelected && entryMode === ENTRY_MODES.AUTOMATIC) {
				return renderUploadStep();
			}
			// Si ya seleccionó modo manual, ir directamente a review
			if (isModeSelected && entryMode === ENTRY_MODES.MANUAL) {
				setTimeout(() => setActiveStep(STEPS.REVIEW), 0);
				return null;
			}
			// Mostrar selección de modo
			return renderModeSelectionStep();
		} else if (activeStep === STEPS.REVIEW) {
			return renderReviewStep();
		} else if (activeStep === STEPS.CONFIRM) {
			return renderConfirmStep();
		}

		return renderModeSelectionStep();
	};

	console.log("🏁 Renderizando componente principal, activeStep:", activeStep);

	return (
		<>
			{isSaving && <SavingProgress />}
			<Dialog
				open={isOpen}
				onClose={isSaving ? () => { } : closeDialog}
			>
				<form dusk="taxProfileForm" onSubmit={submit}>
					<div className="relative border-b border-slate-200/80 px-6 pb-5 pt-6 dark:border-slate-800">
						<TaxProfileModalCloseButton
							onClose={closeDialog}
							disabled={isSaving || processingPdf}
						/>
						<TaxProfileFormStepper activeStep={activeStep} />
					</div>

					{renderContent()}
				</form>
			</Dialog>
		</>
	);
}
