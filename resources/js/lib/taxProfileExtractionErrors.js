export const TAX_PROFILE_EXTRACTION_CODES = {
	LEGAL_ENTITY_NOT_ALLOWED: "TAX_PROFILE_LEGAL_ENTITY_NOT_ALLOWED",
	INVALID_DOCUMENT: "TAX_CERTIFICATE_INVALID_DOCUMENT",
	PROTECTED: "TAX_CERTIFICATE_PROTECTED",
	UNREADABLE: "TAX_CERTIFICATE_UNREADABLE",
	NOT_CSF: "TAX_CERTIFICATE_NOT_CSF",
	EXTRACTION_FAILED: "TAX_CERTIFICATE_EXTRACTION_FAILED",
	EXTRACTION_TIMEOUT: "TAX_CERTIFICATE_EXTRACTION_TIMEOUT",
	RATE_LIMITED: "TAX_CERTIFICATE_RATE_LIMITED",
	ALREADY_PROCESSING: "TAX_CERTIFICATE_ALREADY_PROCESSING",
	INCONSISTENT_DATA: "TAX_CERTIFICATE_INCONSISTENT_DATA",
};

/**
 * @typedef {{
 *   code: string,
 *   title: string,
 *   message: string,
 *   variant: 'blocked'|'error'|'warning',
 *   allowManual: boolean,
 *   allowRetry: boolean,
 *   clearExtractedData: boolean,
 * }} ExtractionErrorView
 */

/**
 * @param {string|null|undefined} code
 * @param {string|null|undefined} fallbackMessage
 * @param {number|null|undefined} httpStatus
 * @returns {ExtractionErrorView}
 */
export function mapTaxProfileExtractionError(code, fallbackMessage = null, httpStatus = null) {
	const normalized = typeof code === "string" ? code : null;

	if (normalized === TAX_PROFILE_EXTRACTION_CODES.LEGAL_ENTITY_NOT_ALLOWED) {
		return {
			code: normalized,
			title: "No podemos crear este perfil fiscal",
			message:
				"La constancia corresponde a una persona moral. Actualmente, Famedic solo permite facturación a personas físicas.",
			variant: "blocked",
			allowManual: false,
			allowRetry: true,
			clearExtractedData: true,
		};
	}

	const catalog = {
		[TAX_PROFILE_EXTRACTION_CODES.INVALID_DOCUMENT]: {
			title: "Archivo no válido",
			message:
				"El archivo no es un PDF válido. Selecciona una Constancia de Situación Fiscal en formato PDF.",
			variant: "error",
			allowManual: true,
			allowRetry: true,
			clearExtractedData: true,
		},
		[TAX_PROFILE_EXTRACTION_CODES.PROTECTED]: {
			title: "PDF protegido",
			message:
				"El PDF está protegido y no puede procesarse. Descarga una constancia sin contraseña o captura tus datos manualmente.",
			variant: "error",
			allowManual: true,
			allowRetry: true,
			clearExtractedData: true,
		},
		[TAX_PROFILE_EXTRACTION_CODES.UNREADABLE]: {
			title: "No pudimos leer el PDF",
			message:
				"No pudimos leer la información del PDF. Si es un documento escaneado, utiliza una constancia descargada directamente del SAT o captura tus datos manualmente.",
			variant: "error",
			allowManual: true,
			allowRetry: true,
			clearExtractedData: true,
		},
		[TAX_PROFILE_EXTRACTION_CODES.NOT_CSF]: {
			title: "Documento no reconocido",
			message:
				"El documento no parece ser una Constancia de Situación Fiscal. Verifica el archivo e inténtalo nuevamente.",
			variant: "error",
			allowManual: true,
			allowRetry: true,
			clearExtractedData: true,
		},
		[TAX_PROFILE_EXTRACTION_CODES.EXTRACTION_TIMEOUT]: {
			title: "Tiempo de espera agotado",
			message:
				"El procesamiento tardó más de lo esperado. Inténtalo nuevamente o captura tus datos manualmente.",
			variant: "warning",
			allowManual: true,
			allowRetry: true,
			clearExtractedData: true,
		},
		[TAX_PROFILE_EXTRACTION_CODES.RATE_LIMITED]: {
			title: "Demasiados intentos",
			message:
				"Has realizado varios intentos en poco tiempo. Espera un momento antes de volver a intentarlo.",
			variant: "warning",
			allowManual: true,
			allowRetry: false,
			clearExtractedData: true,
		},
		[TAX_PROFILE_EXTRACTION_CODES.ALREADY_PROCESSING]: {
			title: "Procesamiento en curso",
			message:
				"Ya estamos procesando esta constancia. Espera a que termine el intento actual.",
			variant: "warning",
			allowManual: false,
			allowRetry: false,
			clearExtractedData: false,
		},
		[TAX_PROFILE_EXTRACTION_CODES.INCONSISTENT_DATA]: {
			title: "Datos no confirmables",
			message:
				"Encontramos información que no pudimos validar de forma segura. Revisa otra constancia o captura tus datos manualmente.",
			variant: "error",
			allowManual: true,
			allowRetry: true,
			clearExtractedData: true,
		},
		[TAX_PROFILE_EXTRACTION_CODES.EXTRACTION_FAILED]: {
			title: "No fue posible procesar la constancia",
			message:
				"No fue posible procesar la constancia en este momento. Puedes intentarlo nuevamente o capturar tus datos manualmente.",
			variant: "error",
			allowManual: true,
			allowRetry: true,
			clearExtractedData: true,
		},
	};

	if (normalized && catalog[normalized]) {
		return {
			code: normalized,
			...catalog[normalized],
		};
	}

	if (httpStatus === 429) {
		return {
			code: TAX_PROFILE_EXTRACTION_CODES.RATE_LIMITED,
			...catalog[TAX_PROFILE_EXTRACTION_CODES.RATE_LIMITED],
		};
	}

	const safeFallback =
		typeof fallbackMessage === "string" &&
		fallbackMessage.trim() !== "" &&
		!/openai|stack|exception|sql|trace|vendor\\|/i.test(fallbackMessage)
			? fallbackMessage.trim()
			: "No fue posible procesar la constancia en este momento. Puedes intentarlo nuevamente o capturar tus datos manualmente.";

	return {
		code: normalized || "TAX_CERTIFICATE_EXTRACTION_FAILED",
		title: "No fue posible procesar la constancia",
		message: safeFallback,
		variant: "error",
		allowManual: true,
		allowRetry: true,
		clearExtractedData: true,
	};
}
