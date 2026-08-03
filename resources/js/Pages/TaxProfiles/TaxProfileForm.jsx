import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Field, Label } from "@/Components/Catalyst/fieldset";
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
	ArrowUpTrayIcon,
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
	TaxProfileEntryModeCard,
	TaxProfileCompactAlert,
	TaxProfilePhysicalPersonNotice,
} from "@/Pages/TaxProfiles/TaxProfileFormUI";
import {
	mapExtractionResponseToTaxProfileForm,
	formatFileSize,
	isPdfFile,
	MAX_TAX_CERTIFICATE_BYTES,
} from "@/lib/mapExtractionResponseToTaxProfileForm";
import {
	mapTaxProfileExtractionError,
	TAX_PROFILE_EXTRACTION_CODES,
} from "@/lib/taxProfileExtractionErrors";
import {
	normalizeRfcInput,
	classifyRfcForIndividualProfile,
	rfcHintMessage,
} from "@/lib/taxProfileRfcHints";

// Pasos del proceso. La revisión final incluye la confirmación y el guardado.
const STEPS = {
	UPLOAD: 1,
	REVIEW: 2,
};

// Modos de entrada de datos
const ENTRY_MODES = {
	AUTOMATIC: "automatic",
	MANUAL: "manual",
};

const FIELD_LABELS = {
	name: "Nombre",
	rfc: "RFC",
	zipcode: "Código postal",
	tax_regime: "Régimen fiscal",
};

const EXTRACTION_MESSAGES = [
	"Validando la constancia…",
	"Extrayendo los datos fiscales…",
	"Preparando la información para que la revises…",
];

const EXTRACTION_MESSAGE_INTERVAL_MS = 2600;
const EXTRACTION_SLOW_NOTICE_MS = 12000;
const EXTRACTION_TIMEOUT_MS = 45000;

export default function TaxProfileForm({ isOpen }) {
	const { taxProfile, taxRegimes } = usePage().props;

	const [cachedTaxRegimes, setCachedTaxRegimes] = useState(taxRegimes || {});
	const [cachedEditMode, setCachedEditMode] = useState(
		route().current("tax-profiles.edit") || false
	);
	const [cachedTaxProfile, setCachedTaxProfile] = useState(taxProfile || null);

	const [activeStep, setActiveStep] = useState(STEPS.UPLOAD);
	const [entryMode, setEntryMode] = useState(ENTRY_MODES.AUTOMATIC);
	const [isModeSelected, setIsModeSelected] = useState(false);

	const [uploadedFile, setUploadedFile] = useState(null);
	const [isDragging, setIsDragging] = useState(false);
	const [processingPdf, setProcessingPdf] = useState(false);
	const [extractionMessage, setExtractionMessage] = useState(EXTRACTION_MESSAGES[0]);
	const [showSlowNotice, setShowSlowNotice] = useState(false);
	const [extractionError, setExtractionError] = useState(null);

	const [extractedData, setExtractedData] = useState(null);
	const [missingFields, setMissingFields] = useState([]);
	const [warnings, setWarnings] = useState([]);

	const [infoMessage, setInfoMessage] = useState(null);
	const [isSaving, setIsSaving] = useState(false);
	const [saveStep, setSaveStep] = useState("");

	const resetFormData = (profile) => ({
		name: profile?.name || "",
		rfc: profile?.rfc || "",
		zipcode: profile?.zipcode || "",
		tax_regime: profile?.tax_regime || null,
		cfdi_use: profile?.cfdi_use || "G03",
		fiscal_certificate: null,
		confirm_data: false,
	});

	const { data, setData, errors, setError, clearErrors } = useForm(
		resetFormData(taxProfile || {})
	);

	// Refs
	const fileInputRef = useRef(null);
	const manualFileInputRef = useRef(null);
	const extractInFlightRef = useRef(false);
	const abortControllerRef = useRef(null);
	const messageCycleRef = useRef(null);
	const slowNoticeTimeoutRef = useRef(null);

	const stopExtractionMessageCycle = () => {
		if (messageCycleRef.current) {
			clearInterval(messageCycleRef.current);
			messageCycleRef.current = null;
		}
		if (slowNoticeTimeoutRef.current) {
			clearTimeout(slowNoticeTimeoutRef.current);
			slowNoticeTimeoutRef.current = null;
		}
	};

	useEffect(() => {
		if (!isOpen) return;

		const isEditMode = route().current("tax-profiles.edit") || false;

		setCachedTaxRegimes(taxRegimes || {});
		setCachedTaxProfile(taxProfile || null);
		setCachedEditMode(isEditMode);
		setData(resetFormData(taxProfile || {}));

		setUploadedFile(null);
		setIsDragging(false);
		setProcessingPdf(false);
		setShowSlowNotice(false);
		setExtractionError(null);
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setInfoMessage(null);
		setIsSaving(false);
		setSaveStep("");

		extractInFlightRef.current = false;
		if (abortControllerRef.current) {
			abortControllerRef.current.abort();
			abortControllerRef.current = null;
		}
		stopExtractionMessageCycle();

		if (isEditMode && taxProfile) {
			// Edición: ir directo a revisión con los datos actuales; la constancia es opcional.
			setEntryMode(ENTRY_MODES.MANUAL);
			setIsModeSelected(true);
			setActiveStep(STEPS.REVIEW);
		} else {
			setEntryMode(ENTRY_MODES.AUTOMATIC);
			setIsModeSelected(false);
			setActiveStep(STEPS.UPLOAD);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [isOpen, taxProfile, taxRegimes, setData]);

	useEffect(() => {
		return () => {
			stopExtractionMessageCycle();
			abortControllerRef.current?.abort();
		};
	}, []);

	const encontrarRegimenPorTexto = (textoRegimen) => {
		if (!textoRegimen || !cachedTaxRegimes) return null;

		const textoLower = textoRegimen.toLowerCase();

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

		for (const [termino, clave] of Object.entries(mapeoTerminos)) {
			if (textoLower.includes(termino) && cachedTaxRegimes[clave]) {
				return clave;
			}
		}

		for (const [key, regimen] of Object.entries(cachedTaxRegimes)) {
			const regimenLower = (regimen?.name || "").toLowerCase();
			if (
				regimenLower &&
				(textoLower.includes(regimenLower) || regimenLower.includes(textoLower))
			) {
				return key;
			}
		}

		return null;
	};

	// ------------------------------------------------------------------
	// Selección / validación de archivo
	// ------------------------------------------------------------------

	const validateSelectedFile = (file) => {
		if (!file) return "Selecciona un archivo.";
		if (!isPdfFile(file)) return "Solo se aceptan archivos PDF.";
		if (file.size > MAX_TAX_CERTIFICATE_BYTES) {
			return `El archivo no debe superar ${formatFileSize(MAX_TAX_CERTIFICATE_BYTES)}.`;
		}
		return null;
	};

	const applySelectedFile = (file) => {
		const validationError = validateSelectedFile(file);
		if (validationError) {
			setError("fiscal_certificate", validationError);
			return;
		}

		clearErrors("fiscal_certificate");
		setExtractionError(null);
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setUploadedFile(file);
		setData("fiscal_certificate", file);
	};

	const handleFileInputChange = (e) => {
		const file = e.target.files?.[0];
		e.target.value = "";
		if (file) applySelectedFile(file);
	};

	const handleManualFileInputChange = (e) => {
		const file = e.target.files?.[0];
		e.target.value = "";
		if (!file) return;

		const validationError = validateSelectedFile(file);
		if (validationError) {
			setError("fiscal_certificate", validationError);
			return;
		}

		clearErrors("fiscal_certificate");
		setUploadedFile(file);
		setData("fiscal_certificate", file);
	};

	const handleDragOver = (e) => {
		e.preventDefault();
		if (!processingPdf) setIsDragging(true);
	};

	const handleDragLeave = (e) => {
		e.preventDefault();
		setIsDragging(false);
	};

	const handleDrop = (e) => {
		e.preventDefault();
		setIsDragging(false);
		if (processingPdf) return;
		const file = e.dataTransfer.files?.[0];
		if (file) applySelectedFile(file);
	};

	const handleRemoveFile = () => {
		setUploadedFile(null);
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setExtractionError(null);
		setData("fiscal_certificate", null);
		clearErrors("fiscal_certificate");

		if (fileInputRef.current) fileInputRef.current.value = "";
		if (manualFileInputRef.current) manualFileInputRef.current.value = "";
	};

	// ------------------------------------------------------------------
	// Extracción automática (PF-IA.2: POST tax-profiles.extract-data)
	// ------------------------------------------------------------------

	const handleBlockedExtraction = () => {
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setData("name", "");
		setData("rfc", "");
		setData("zipcode", "");
		setData("tax_regime", null);
		setExtractionError(
			mapTaxProfileExtractionError(TAX_PROFILE_EXTRACTION_CODES.LEGAL_ENTITY_NOT_ALLOWED)
		);
	};

	const applyExtractionError = (mappedError) => {
		setExtractionError(mappedError);
		if (mappedError.clearExtractedData) {
			setExtractedData(null);
			setMissingFields([]);
			setWarnings([]);
		}
	};

	const startExtraction = async () => {
		if (extractInFlightRef.current || processingPdf) return;
		if (!uploadedFile) return;

		const validationError = validateSelectedFile(uploadedFile);
		if (validationError) {
			setError("fiscal_certificate", validationError);
			return;
		}

		extractInFlightRef.current = true;
		setProcessingPdf(true);
		setExtractionError(null);
		setShowSlowNotice(false);
		setExtractionMessage(EXTRACTION_MESSAGES[0]);
		clearErrors("fiscal_certificate");
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setData("name", "");
		setData("rfc", "");
		setData("zipcode", "");
		setData("tax_regime", null);
		setData("confirm_data", false);

		let messageIndex = 0;
		messageCycleRef.current = setInterval(() => {
			messageIndex = (messageIndex + 1) % EXTRACTION_MESSAGES.length;
			setExtractionMessage(EXTRACTION_MESSAGES[messageIndex]);
		}, EXTRACTION_MESSAGE_INTERVAL_MS);

		slowNoticeTimeoutRef.current = setTimeout(() => {
			setShowSlowNotice(true);
		}, EXTRACTION_SLOW_NOTICE_MS);

		const controller = new AbortController();
		abortControllerRef.current = controller;
		const abortTimeoutId = setTimeout(() => controller.abort(), EXTRACTION_TIMEOUT_MS);

		try {
			const formData = new FormData();
			formData.append("fiscal_certificate", uploadedFile);

			const csrfToken = document
				.querySelector('meta[name="csrf-token"]')
				?.getAttribute("content");
			if (csrfToken) formData.append("_token", csrfToken);

			const response = await fetch(route("tax-profiles.extract-data"), {
				method: "POST",
				body: formData,
				credentials: "include",
				headers: {
					"X-Requested-With": "XMLHttpRequest",
					Accept: "application/json",
					...(csrfToken ? { "X-CSRF-TOKEN": csrfToken } : {}),
				},
				signal: controller.signal,
			});

			let result = null;
			try {
				result = await response.json();
			} catch {
				applyExtractionError(mapTaxProfileExtractionError(null, null, response.status));
				return;
			}

			if (response.ok && result?.success) {
				const mapped = mapExtractionResponseToTaxProfileForm(result.data, encontrarRegimenPorTexto);

				if (mapped.status === "rejected_legal_entity") {
					handleBlockedExtraction();
					return;
				}

				if (!mapped.confirmable) {
					setExtractionError(
						mapTaxProfileExtractionError(TAX_PROFILE_EXTRACTION_CODES.EXTRACTION_FAILED)
					);
					return;
				}

				if (mapped.form.name) setData("name", mapped.form.name);
				if (mapped.form.rfc) setData("rfc", mapped.form.rfc);
				if (mapped.form.zipcode) setData("zipcode", mapped.form.zipcode);
				if (mapped.form.tax_regime) setData("tax_regime", mapped.form.tax_regime);

				setExtractedData(mapped.extractedPayload);
				setMissingFields(mapped.missingFields);
				setWarnings(mapped.warnings);
				setExtractionError(null);
				setActiveStep(STEPS.REVIEW);
				return;
			}

			if (result?.code === TAX_PROFILE_EXTRACTION_CODES.LEGAL_ENTITY_NOT_ALLOWED) {
				handleBlockedExtraction();
				return;
			}

			applyExtractionError(
				mapTaxProfileExtractionError(result?.code, result?.message, response.status)
			);
		} catch (error) {
			if (error?.name === "AbortError") {
				applyExtractionError(
					mapTaxProfileExtractionError(TAX_PROFILE_EXTRACTION_CODES.EXTRACTION_TIMEOUT)
				);
			} else {
				// No exponer error.message crudo del navegador/proveedor.
				applyExtractionError(mapTaxProfileExtractionError(null));
			}
		} finally {
			clearTimeout(abortTimeoutId);
			stopExtractionMessageCycle();
			setShowSlowNotice(false);
			setProcessingPdf(false);
			extractInFlightRef.current = false;
			abortControllerRef.current = null;
		}
	};

	const handleRetryWithNewFile = () => {
		setUploadedFile(null);
		setData("fiscal_certificate", null);
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setExtractionError(null);
		setData("name", "");
		setData("rfc", "");
		setData("zipcode", "");
		setData("tax_regime", null);
		setData("confirm_data", false);
		setIsModeSelected(true);
		setEntryMode(ENTRY_MODES.AUTOMATIC);
		setActiveStep(STEPS.UPLOAD);
		if (fileInputRef.current) fileInputRef.current.value = "";
	};

	const handleSwitchToManual = () => {
		// Conserva el archivo (si lo hay) para el envío final, pero descarta
		// cualquier metadato de la extracción automática.
		setEntryMode(ENTRY_MODES.MANUAL);
		setExtractionError(null);
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setActiveStep(STEPS.REVIEW);
	};

	// ------------------------------------------------------------------
	// Navegación entre pasos
	// ------------------------------------------------------------------

	const handleEntryModeChange = (mode) => {
		setEntryMode(mode);
		setIsModeSelected(true);
		clearErrors("fiscal_certificate");
		setExtractionError(null);

		if (uploadedFile) {
			setUploadedFile(null);
			setExtractedData(null);
			setMissingFields([]);
			setWarnings([]);
			setData("fiscal_certificate", null);
		}
	};

	const handleBackToModeSelection = () => {
		if (processingPdf) return;
		setIsModeSelected(false);
		setUploadedFile(null);
		setData("fiscal_certificate", null);
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setExtractionError(null);
		clearErrors();
	};

	const handleNextStep = () => {
		if (activeStep !== STEPS.UPLOAD) return;

		if (!isModeSelected) {
			setInfoMessage({
				type: "error",
				message: "Selecciona un método para ingresar tus datos.",
			});
			return;
		}

		if (entryMode === ENTRY_MODES.MANUAL) {
			setActiveStep(STEPS.REVIEW);
			return;
		}

		// Modo automático: al seleccionar la tarjeta ya se muestra la subida.
		setIsModeSelected(true);
	};

	const handleBackFromReview = () => {
		if (isSaving) return;
		if (cachedEditMode) {
			requestClose();
			return;
		}
		setActiveStep(STEPS.UPLOAD);
	};

	const handleReplaceCertificate = () => {
		if (!cachedEditMode && entryMode === ENTRY_MODES.AUTOMATIC) {
			setUploadedFile(null);
			setData("fiscal_certificate", null);
			setExtractedData(null);
			setMissingFields([]);
			setWarnings([]);
			setExtractionError(null);
			clearErrors("fiscal_certificate");
			setActiveStep(STEPS.UPLOAD);
			return;
		}
		manualFileInputRef.current?.click();
	};

	// ------------------------------------------------------------------
	// Cierre / descarte
	// ------------------------------------------------------------------

	const isDirty = () => {
		if (uploadedFile) return true;
		if (extractedData) return true;
		if (extractionError) return true;
		if (data.name || data.rfc || data.zipcode || data.tax_regime) return true;
		return false;
	};

	const closeDialog = () => {
		setUploadedFile(null);
		setExtractedData(null);
		setMissingFields([]);
		setWarnings([]);
		setExtractionError(null);
		setInfoMessage(null);
		clearErrors();
		setActiveStep(STEPS.UPLOAD);
		setIsModeSelected(false);

		router.get(
			route("tax-profiles.index"),
			{},
			{ preserveState: true, preserveScroll: true }
		);
	};

	const requestClose = () => {
		if (processingPdf) return;

		if (isDirty()) {
			const confirmed = window.confirm(
				"Si cierras, se descartarán los datos no guardados. ¿Deseas continuar?"
			);
			if (!confirmed) return;
		}

		closeDialog();
	};

	// ------------------------------------------------------------------
	// Guardado (tax-profiles.store / tax-profiles.update)
	// ------------------------------------------------------------------

	const submit = async (e) => {
		e.preventDefault();

		if (activeStep !== STEPS.REVIEW) {
			handleNextStep();
			return;
		}

		setInfoMessage(null);
		clearErrors();

		const requiredFields = {
			name: data.name,
			rfc: data.rfc,
			zipcode: data.zipcode,
			tax_regime: data.tax_regime,
		};

		const missingRequired = Object.entries(requiredFields)
			.filter(([, value]) => !value)
			.map(([key]) => key);

		if (missingRequired.length > 0) {
			missingRequired.forEach((field) => setError(field, "Este campo es requerido"));
			setInfoMessage({
				type: "error",
				message: "Completa todos los campos requeridos antes de continuar.",
			});
			return;
		}

		const rfcClassification = classifyRfcForIndividualProfile(data.rfc);
		if (rfcClassification !== "individual") {
			setError("rfc", rfcHintMessage(data.rfc) || "Formato de RFC inválido.");
			setInfoMessage({ type: "error", message: "Verifica el formato de tu RFC." });
			return;
		}

		if (!/^\d{5}$/.test(data.zipcode)) {
			setError("zipcode", "Debe tener 5 dígitos");
			setInfoMessage({ type: "error", message: "El código postal debe tener 5 dígitos." });
			return;
		}

		// Constancia obligatoria al crear; en edición se conserva la existente.
		if (!cachedEditMode && (!uploadedFile || !data.fiscal_certificate)) {
			setError("fiscal_certificate", "Debe subir una constancia fiscal");
			setInfoMessage({ type: "error", message: "Debe subir una constancia fiscal." });
			return;
		}

		if (extractedData && !data.confirm_data) {
			setError("confirm_data", "Debe confirmar que los datos extraídos son correctos");
			return;
		}

		setIsSaving(true);
		setSaveStep("Validando datos...");

		// Deja que React pinte el estado de carga antes del trabajo pesado.
		await new Promise((resolve) => {
			requestAnimationFrame(() => requestAnimationFrame(resolve));
		});

		try {
			const formData = new FormData();
			formData.append("name", data.name);
			formData.append("rfc", data.rfc);
			formData.append("zipcode", data.zipcode);
			formData.append("tax_regime", data.tax_regime);
			formData.append("cfdi_use", data.cfdi_use || "G03");
			formData.append("entry_mode", entryMode);

			if (data.fiscal_certificate) {
				formData.append("fiscal_certificate", data.fiscal_certificate);
			}

			formData.append("confirm_data", data.confirm_data ? "1" : "0");

			if (extractedData) {
				formData.append("extracted_data", JSON.stringify(extractedData));
			}

			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
			if (csrfToken) {
				formData.append("_token", csrfToken);
			}

			let url = route("tax-profiles.store");
			let method = "POST";

			if (cachedEditMode && cachedTaxProfile) {
				formData.append("_method", "PUT");
				url = route("tax-profiles.update", {
					tax_profile: cachedTaxProfile.id,
				});
			}

			setSaveStep("Enviando al servidor...");

			const response = await fetch(url, {
				method: method,
				body: formData,
				headers: {
					Accept: "application/json",
					"X-Requested-With": "XMLHttpRequest",
				},
			});

			const usedProfileLockMessage =
				"Este perfil ya no se puede modificar porque fue utilizado en una solicitud de factura. Puedes usarlo en nuevas solicitudes o crear otro perfil con datos distintos.";

			const responseText = await response.text();

			const isUsedProfileLockStatus =
				cachedEditMode &&
				(response.status === 403 || response.status === 422);

			try {
				const result = JSON.parse(responseText);

				if (response.ok && result.success) {
					const successMessage = cachedEditMode
						? "Tu perfil fiscal ha sido actualizado correctamente."
						: "Tu perfil fiscal ha sido creado correctamente.";

					setInfoMessage({
						type: "success",
						message: successMessage,
					});

					setTimeout(() => {
						router.visit(route("tax-profiles.index"), {
							preserveState: true,
							preserveScroll: true,
						});
					}, 1500);
				} else {
					const lockFromBody =
						isUsedProfileLockStatus ||
						(cachedEditMode &&
							typeof result.message === "string" &&
							(result.message.includes("ya no se puede modificar") ||
								result.message.includes("ya fue utilizado")));

					if (lockFromBody) {
						setInfoMessage({
							type: "error",
							code: "used_profile_lock",
							message: usedProfileLockMessage,
						});
					} else if (result.errors) {
						Object.keys(result.errors).forEach((key) => {
							setError(key, result.errors[key][0]);
						});

						setInfoMessage({
							type: "error",
							message: "Por favor corrija los errores en el formulario.",
						});
					} else if (result.message) {
						setInfoMessage({
							type: "error",
							message: result.message,
						});

						if (result.message.toLowerCase().includes("rfc")) {
							setError("rfc", result.message);
						}
					}
				}
			} catch {
				if (response.ok) {
					setInfoMessage({
						type: "success",
						message: "La operación se completó correctamente.",
					});

					setTimeout(() => {
						router.visit(route("tax-profiles.index"), {
							preserveState: true,
							preserveScroll: true,
						});
					}, 1500);
				} else if (isUsedProfileLockStatus) {
					setInfoMessage({
						type: "error",
						code: "used_profile_lock",
						message: usedProfileLockMessage,
					});
				} else {
					setInfoMessage({
						type: "error",
						message: "Ocurrió un error en el servidor. Por favor intente nuevamente.",
					});
				}
			}
		} catch {
			setInfoMessage({
				type: "error",
				message: "Verifique su internet e intente nuevamente.",
			});
		} finally {
			setIsSaving(false);
			setSaveStep("");
		}
	};

	// ------------------------------------------------------------------
	// Render: banner informativo genérico
	// ------------------------------------------------------------------

	const renderInfoMessage = () => {
		if (!infoMessage) return null;

		return (
			<div
				className={`rounded-lg p-4 ${infoMessage.type === "success"
					? "bg-green-50 border border-green-200"
					: infoMessage.type === "error"
						? "bg-red-50 border border-red-200"
						: "bg-yellow-50 border border-yellow-200"
					}`}
				role="alert"
			>
				<div className="flex items-start">
					{infoMessage.type === "success" ? (
						<CheckCircleIcon className="h-5 w-5 text-green-400 mr-2 shrink-0" />
					) : (
						<ExclamationTriangleIcon className="h-5 w-5 text-red-400 mr-2 shrink-0" />
					)}
					<div className="min-w-0 space-y-3">
						<span className="font-medium block">{infoMessage.message}</span>
						{infoMessage.code === "used_profile_lock" && (
							<div className="flex flex-col gap-2 sm:flex-row">
								<Button
									type="button"
									outline
									onClick={() =>
										router.visit(route("tax-profiles.index"), {
											preserveScroll: true,
										})
									}
								>
									Volver al listado
								</Button>
								<Button
									type="button"
									href={route("tax-profiles.create")}
									preserveState
									preserveScroll
								>
									Crear otro perfil
								</Button>
							</div>
						)}
					</div>
				</div>
			</div>
		);
	};

	// ------------------------------------------------------------------
	// Paso: selección de modo de entrada
	// ------------------------------------------------------------------

	const renderModeSelectionStep = () => {
		return (
			<>
				<DialogTitle>
					{cachedEditMode ? "Actualizar perfil fiscal" : "Nuevo perfil fiscal"}
				</DialogTitle>
				<DialogDescription>
					Selecciona cómo deseas ingresar tu información fiscal.
				</DialogDescription>

				<DialogBody className="space-y-6">
					<TaxProfilePhysicalPersonNotice />

					{renderInfoMessage()}

					<div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
						<TaxProfileEntryModeCard
							selected={entryMode === ENTRY_MODES.AUTOMATIC}
							onSelect={() => handleEntryModeChange(ENTRY_MODES.AUTOMATIC)}
							icon={DocumentArrowUpIcon}
							title="Subir Constancia de Situación Fiscal"
							subtitle="Carga tu constancia y completaremos los datos automáticamente para que los revises."
							features={[
								"Sube tu constancia en PDF",
								"Revisa y confirma los datos antes de guardar",
							]}
							accent="blue"
						/>
						<TaxProfileEntryModeCard
							selected={entryMode === ENTRY_MODES.MANUAL}
							onSelect={() => handleEntryModeChange(ENTRY_MODES.MANUAL)}
							icon={PencilSquareIcon}
							title="Capturar datos manualmente"
							subtitle="Ingresa directamente la información de tu perfil fiscal."
							features={[
								"Ingresa tus datos manualmente",
								"Sube tu constancia en PDF",
							]}
							accent="emerald"
						/>
					</div>
				</DialogBody>

				<DialogActions>
					<Button autoFocus dusk="cancel" plain type="button" onClick={requestClose}>
						Cancelar
					</Button>
					<Button
						type="button"
						onClick={handleNextStep}
						disabled={!isModeSelected}
					>
						Continuar
						<ChevronRightIcon className="ml-2 h-4 w-4" />
					</Button>
				</DialogActions>
			</>
		);
	};

	// ------------------------------------------------------------------
	// Paso: constancia bloqueada (persona moral detectada)
	// ------------------------------------------------------------------

	const renderBlockedStep = () => {
		return (
			<>
				<DialogTitle>{extractionError.title}</DialogTitle>
				<DialogDescription>{extractionError.message}</DialogDescription>

				<DialogBody className="space-y-4">
					<TaxProfileCompactAlert tone="red">
						{extractionError.message}
					</TaxProfileCompactAlert>
				</DialogBody>

				<DialogActions>
					<Button dusk="cancel" plain type="button" onClick={requestClose}>
						Cancelar
					</Button>
					<Button type="button" onClick={handleRetryWithNewFile}>
						Cargar otra constancia
					</Button>
				</DialogActions>
			</>
		);
	};

	// ------------------------------------------------------------------
	// Paso: subir constancia (modo automático)
	// ------------------------------------------------------------------

	const renderUploadStep = () => {
		return (
			<>
				<DialogTitle>
					<button
						type="button"
						onClick={handleBackToModeSelection}
						className="flex items-center text-sm text-gray-500 hover:text-gray-700 mb-2 disabled:opacity-50"
						disabled={processingPdf}
					>
						<ArrowLeftIcon className="h-4 w-4 mr-1" />
						Cambiar método
					</button>
					Sube tu constancia fiscal
				</DialogTitle>
				<DialogDescription>
					Sube el PDF de tu Constancia de Situación Fiscal. Después de subirlo, extraeremos tus datos para que los revises.
				</DialogDescription>

				<DialogBody className="space-y-6">
					<TaxProfilePhysicalPersonNotice />

					{!uploadedFile && (
						<div
							role="button"
							tabIndex={0}
							aria-label="Zona para seleccionar o soltar la constancia fiscal en PDF"
							onDragOver={handleDragOver}
							onDragLeave={handleDragLeave}
							onDrop={handleDrop}
							onKeyDown={(e) => {
								if (e.key === "Enter" || e.key === " ") {
									e.preventDefault();
									fileInputRef.current?.click();
								}
							}}
							className={`rounded-xl border-2 border-dashed p-8 text-center transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 ${isDragging
								? "border-blue-400 bg-blue-50"
								: "border-slate-200 bg-slate-50/80 dark:border-slate-700 dark:bg-slate-800/40"
								}`}
						>
							<div className="mx-auto max-w-sm">
								<div className="w-12 h-12 mx-auto mb-3 flex items-center justify-center bg-blue-100 rounded-full">
									<ArrowUpTrayIcon className="h-6 w-6 text-blue-600" />
								</div>
								<p className="text-base font-semibold text-slate-900 dark:text-white">
									Arrastra tu constancia aquí
								</p>
								<p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
									o selecciona el archivo PDF desde tu computadora
								</p>
								<Button
									type="button"
									onClick={() => fileInputRef.current?.click()}
									className="mt-5 inline-flex items-center gap-2"
								>
									<ArrowUpTrayIcon className="h-4 w-4" />
									Seleccionar archivo
								</Button>
								<input
									ref={fileInputRef}
									type="file"
									className="hidden"
									accept="application/pdf"
									onChange={handleFileInputChange}
								/>
							</div>
							<div className="mt-4 pt-4 border-t border-gray-200 dark:border-slate-700">
								<p className="text-xs text-gray-500 dark:text-slate-400">
									Solo archivos PDF · Máximo {formatFileSize(MAX_TAX_CERTIFICATE_BYTES)}
								</p>
							</div>
						</div>
					)}

					{errors.fiscal_certificate && (
						<ErrorMessage>{errors.fiscal_certificate}</ErrorMessage>
					)}

					{uploadedFile && (
						<div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800/40">
							<div className="flex items-center justify-between gap-3">
								<div className="flex min-w-0 items-center gap-3">
									<div className="p-2 bg-blue-100 rounded-lg shrink-0">
										<DocumentTextIcon className="h-6 w-6 text-blue-600" />
									</div>
									<div className="min-w-0">
										<p className="truncate text-sm font-medium text-slate-900 dark:text-white">
											{uploadedFile.name}
										</p>
										<p className="text-xs text-slate-500 dark:text-slate-400">
											{formatFileSize(uploadedFile.size)}
										</p>
									</div>
								</div>
								<Button
									type="button"
									plain
									onClick={handleRemoveFile}
									disabled={processingPdf}
								>
									Cambiar archivo
								</Button>
							</div>

							{!processingPdf && !extractionError && (
								<div className="mt-4 flex flex-wrap items-center gap-4">
									<Button type="button" onClick={startExtraction} disabled={processingPdf}>
										Extraer datos
									</Button>
									<button
										type="button"
										onClick={handleSwitchToManual}
										className="text-sm text-slate-500 underline hover:text-slate-700 dark:text-slate-400"
									>
										Prefiero capturar mis datos manualmente
									</button>
								</div>
							)}

							{processingPdf && (
								<div className="mt-4 space-y-3">
									<div className="flex items-center gap-3">
										<ArrowPathIcon
											className="h-5 w-5 shrink-0 animate-spin text-blue-600"
											aria-hidden
										/>
										<span role="status" aria-live="polite" className="text-sm text-slate-700 dark:text-slate-300">
											{extractionMessage}
										</span>
									</div>
									<div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
										<div className="h-full w-full animate-pulse rounded-full bg-gradient-to-r from-blue-400 via-blue-600 to-blue-400" />
									</div>
									{showSlowNotice && (
										<p className="text-xs text-amber-600 dark:text-amber-400" role="status" aria-live="polite">
											Está tomando más tiempo de lo esperado. Puedes esperar, reintentar o capturar tus datos manualmente.
										</p>
									)}
								</div>
							)}
						</div>
					)}

					{extractionError && extractionError.variant !== "blocked" && (
						<TaxProfileCompactAlert tone="red">
							<div className="space-y-2">
								<p className="font-medium">{extractionError.title}</p>
								<p>{extractionError.message}</p>
								<div className="flex flex-wrap gap-2 pt-1">
									{extractionError.allowRetry && (
										<Button type="button" outline onClick={startExtraction}>
											Reintentar
										</Button>
									)}
									{extractionError.allowManual && (
										<Button type="button" outline onClick={handleSwitchToManual}>
											Capturar manualmente
										</Button>
									)}
								</div>
							</div>
						</TaxProfileCompactAlert>
					)}
				</DialogBody>

				<DialogActions>
					<Button
						autoFocus
						dusk="cancel"
						plain
						type="button"
						onClick={requestClose}
						disabled={processingPdf}
					>
						Cancelar
					</Button>
				</DialogActions>
			</>
		);
	};

	// ------------------------------------------------------------------
	// Paso: revisar y confirmar (común a ambos modos y a edición)
	// ------------------------------------------------------------------

	const renderReviewStep = () => {
		const rfcClassification = classifyRfcForIndividualProfile(data.rfc);
		const rfcHint = !errors.rfc ? rfcHintMessage(data.rfc) : null;
		const rfcHintTone =
			rfcClassification === "moral" || rfcClassification === "invalid"
				? "text-red-600"
				: "text-amber-600";

		return (
			<>
				<DialogTitle>
					{!cachedEditMode && (
						<button
							type="button"
							onClick={handleBackFromReview}
							className="flex items-center text-sm text-gray-500 hover:text-gray-700 mb-2"
							disabled={isSaving}
						>
							<ArrowLeftIcon className="h-4 w-4 mr-1" />
							Volver
						</button>
					)}
					Revisa tus datos fiscales
				</DialogTitle>
				<DialogDescription>
					Revisa que los datos coincidan con tu Constancia de Situación Fiscal antes de guardar el perfil.
				</DialogDescription>

				<DialogBody className="space-y-6">
					<TaxProfilePhysicalPersonNotice />

					{renderInfoMessage()}

					{warnings.length > 0 && (
						<TaxProfileCompactAlert tone="amber">
							<div className="space-y-1">
								<p className="font-medium">Revisa estos puntos antes de guardar:</p>
								<ul className="list-disc space-y-0.5 pl-4">
									{warnings.map((warning, index) => (
										<li key={index}>{warning}</li>
									))}
								</ul>
							</div>
						</TaxProfileCompactAlert>
					)}

					{missingFields.length > 0 && (
						<TaxProfileCompactAlert tone="amber">
							<div className="space-y-2">
								<p className="font-medium">
									No pudimos detectar estos campos automáticamente. Complétalos manualmente:
								</p>
								<div className="flex flex-wrap gap-2">
									{missingFields.map((field) => (
										<span
											key={field}
											className="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-500/20 dark:text-amber-200"
										>
											{FIELD_LABELS[field] || field}
											<span className="rounded-full bg-amber-200 px-1.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:bg-amber-500/30 dark:text-amber-100">
												Pendiente
											</span>
										</span>
									))}
								</div>
							</div>
						</TaxProfileCompactAlert>
					)}

					{uploadedFile ? (
						<div className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/40">
							<div className="flex min-w-0 items-center gap-3">
								<div className="p-2 bg-blue-100 rounded-lg shrink-0">
									<DocumentTextIcon className="h-5 w-5 text-blue-600" />
								</div>
								<div className="min-w-0">
									<p className="truncate text-sm font-medium text-slate-900 dark:text-white">
										{uploadedFile.name}
									</p>
									<p className="text-xs text-slate-500 dark:text-slate-400">
										{formatFileSize(uploadedFile.size)}
									</p>
								</div>
							</div>
							<Button type="button" plain onClick={handleReplaceCertificate} disabled={isSaving}>
								Reemplazar constancia
							</Button>
							<input
								ref={manualFileInputRef}
								type="file"
								className="hidden"
								accept="application/pdf"
								onChange={handleManualFileInputChange}
							/>
						</div>
					) : (
						<div className="space-y-2">
							<div className="rounded-lg border-2 border-dashed border-gray-300 p-6 text-center dark:border-slate-700">
								<DocumentTextIcon className="mx-auto mb-3 h-10 w-10 text-gray-400" />
								<p className="mb-4 text-sm text-gray-600 dark:text-slate-400">
									{cachedEditMode
										? `Opcional: sube tu constancia fiscal en PDF (máximo ${formatFileSize(MAX_TAX_CERTIFICATE_BYTES)}) para reemplazar la actual.`
										: `Sube tu constancia fiscal en PDF (máximo ${formatFileSize(MAX_TAX_CERTIFICATE_BYTES)}).`}
								</p>
								<Button
									type="button"
									onClick={() => manualFileInputRef.current?.click()}
									className="inline-flex items-center gap-2"
									disabled={isSaving}
								>
									<ArrowUpTrayIcon className="h-4 w-4" />
									Seleccionar archivo
								</Button>
								<input
									ref={manualFileInputRef}
									type="file"
									className="hidden"
									accept="application/pdf"
									onChange={handleManualFileInputChange}
								/>
							</div>
							{errors.fiscal_certificate && (
								<ErrorMessage>{errors.fiscal_certificate}</ErrorMessage>
							)}
						</div>
					)}

					<Field>
						<Label>Nombre *</Label>
						<Input
							dusk="name"
							required
							invalid={!!errors.name}
							value={data.name}
							onChange={(e) => {
								setData("name", e.target.value);
								clearErrors("name");
							}}
							type="text"
							autoComplete="given-name"
							disabled={isSaving}
							placeholder="Ej: Juan Pérez García"
						/>
						{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
					</Field>

					<Field>
						<Label>RFC *</Label>
						<Input
							dusk="rfc"
							required
							invalid={!!errors.rfc}
							value={data.rfc}
							onChange={(e) => {
								setData("rfc", normalizeRfcInput(e.target.value));
								clearErrors("rfc");
							}}
							type="text"
							disabled={isSaving}
							placeholder="Ej: MEBE931209BI2"
						/>
						{errors.rfc && <ErrorMessage>{errors.rfc}</ErrorMessage>}
						{rfcHint && (
							<p className={`mt-1 text-xs ${rfcHintTone}`}>{rfcHint}</p>
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
								const value = e.target.value.replace(/\D/g, "").slice(0, 5);
								setData("zipcode", value);
								clearErrors("zipcode");
							}}
							disabled={isSaving}
							placeholder="Ej: 64000"
						/>
						{errors.zipcode && <ErrorMessage>{errors.zipcode}</ErrorMessage>}
					</Field>

					<Field>
						<Label>Régimen fiscal *</Label>
						<Listbox
							invalid={!!errors.tax_regime}
							placeholder="Selecciona un régimen fiscal"
							value={data.tax_regime}
							onChange={(value) => {
								setData("tax_regime", value);
								clearErrors("tax_regime");
							}}
							disabled={isSaving}
						>
							{Object.keys(cachedTaxRegimes || {}).length > 0 ? (
								Object.entries(cachedTaxRegimes).map(([key, regimen]) => (
									<ListboxOption key={key} value={key}>
										<ListboxLabel>{`${key} - ${regimen?.name || "Desconocido"}`}</ListboxLabel>
									</ListboxOption>
								))
							) : (
								<ListboxOption value="" disabled>
									<ListboxLabel>Cargando regímenes...</ListboxLabel>
								</ListboxOption>
							)}
						</Listbox>
						{errors.tax_regime && <ErrorMessage>{errors.tax_regime}</ErrorMessage>}
						{data.tax_regime && cachedTaxRegimes?.[data.tax_regime] && (
							<p className="mt-1 text-xs text-gray-500">
								{cachedTaxRegimes[data.tax_regime].description}
							</p>
						)}
					</Field>

					{extractedData && (
						<div className="border-t border-slate-200 pt-4 dark:border-slate-700">
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
										Confirmo que los datos extraídos de mi constancia son correctos
									</span>
									<p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
										He verificado que la información coincide con mi Constancia de Situación Fiscal.
									</p>
									{errors.confirm_data && (
										<p className="mt-1 text-sm text-red-600">{errors.confirm_data}</p>
									)}
								</div>
							</label>
						</div>
					)}
				</DialogBody>

				<DialogActions>
					<Button plain type="button" onClick={handleBackFromReview} disabled={isSaving}>
						Volver
					</Button>
					<Button
						dusk="saveTaxProfile"
						type="submit"
						disabled={isSaving || (!!extractedData && !data.confirm_data)}
						aria-busy={isSaving}
						aria-live="polite"
						className={`min-w-[11.5rem] transition-opacity ${isSaving ? "cursor-wait opacity-90" : ""
							}`}
					>
						{isSaving ? (
							<span className="inline-flex items-center justify-center gap-2">
								<ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin" aria-hidden />
								<span>Guardando...</span>
							</span>
						) : (
							<span>{cachedEditMode ? "Actualizar perfil" : "Guardar perfil fiscal"}</span>
						)}
					</Button>
				</DialogActions>
			</>
		);
	};

	// ------------------------------------------------------------------
	// Overlay de guardado
	// ------------------------------------------------------------------

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
						{cachedEditMode ? "Actualizando perfil fiscal..." : "Guardando perfil fiscal..."}
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

	// ------------------------------------------------------------------
	// Render principal
	// ------------------------------------------------------------------

	const renderContent = () => {
		if (extractionError?.variant === "blocked") {
			return renderBlockedStep();
		}

		if (activeStep === STEPS.UPLOAD) {
			if (isModeSelected && entryMode === ENTRY_MODES.AUTOMATIC) {
				return renderUploadStep();
			}
			return renderModeSelectionStep();
		}

		if (activeStep === STEPS.REVIEW) {
			return renderReviewStep();
		}

		return renderModeSelectionStep();
	};

	return (
		<>
			{isSaving && <SavingProgress />}
			<Dialog open={isOpen} onClose={isSaving || processingPdf ? () => { } : requestClose}>
				<form dusk="taxProfileForm" onSubmit={submit}>
					<div className="relative border-b border-slate-200/80 px-6 pb-5 pt-6 dark:border-slate-800">
						<TaxProfileModalCloseButton
							onClose={requestClose}
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
