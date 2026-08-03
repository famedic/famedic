/**
 * Maps PF-IA.2 extract-data response into TaxProfileForm / store field names.
 * Prefers flat contract keys; uses fields.<name>.value only as fallback.
 */

function asNonEmptyString(value) {
	if (value === null || value === undefined) {
		return "";
	}

	const str = String(value).trim();
	return str;
}

function fieldValue(data, flatKey, nestedKey) {
	const flat = data?.[flatKey];
	if (flat !== null && flat !== undefined && String(flat).trim() !== "") {
		return asNonEmptyString(flat);
	}

	const nested = data?.fields?.[nestedKey ?? flatKey]?.value;
	if (nested !== null && nested !== undefined && String(nested).trim() !== "") {
		return asNonEmptyString(nested);
	}

	return "";
}

/**
 * @param {Record<string, unknown>|null|undefined} data
 * @param {(text: string) => string|null} [resolveTaxRegime]
 */
export function mapExtractionResponseToTaxProfileForm(data, resolveTaxRegime) {
	if (!data || typeof data !== "object") {
		return {
			form: {
				name: "",
				rfc: "",
				zipcode: "",
				tax_regime: null,
			},
			extractedPayload: null,
			missingFields: [],
			warnings: [],
			status: null,
			confirmable: false,
		};
	}

	const status = typeof data.status === "string" ? data.status : null;
	const confirmable = status === "completed" || status === "partial";

	const name =
		fieldValue(data, "name") ||
		fieldValue(data, "nombre") ||
		fieldValue(data, "razon_social");

	const rfc = fieldValue(data, "rfc").toUpperCase();
	const zipcode =
		fieldValue(data, "zipcode") ||
		fieldValue(data, "codigo_postal") ||
		fieldValue(data, "codigo_postal_original");

	let taxRegime = fieldValue(data, "tax_regime");
	const regimenText =
		fieldValue(data, "regimen_fiscal") ||
		fieldValue(data, "regimen_fiscal_original");

	if ((!taxRegime || !/^\d{3}$/.test(taxRegime)) && regimenText && typeof resolveTaxRegime === "function") {
		taxRegime = resolveTaxRegime(regimenText) || "";
	}

	if (taxRegime && !/^\d{3}$/.test(taxRegime)) {
		taxRegime = "";
	}

	const missingFields = Array.isArray(data.missing_fields)
		? data.missing_fields.filter((item) => typeof item === "string")
		: [];

	const warnings = Array.isArray(data.warnings)
		? data.warnings.filter((item) => typeof item === "string" && item.trim() !== "")
		: [];

	const form = {
		name,
		rfc,
		zipcode,
		tax_regime: taxRegime || null,
	};

	const extractedPayload = confirmable
		? {
				rfc: rfc || null,
				nombre: name || null,
				razon_social: asNonEmptyString(data.razon_social) || name || null,
				codigo_postal: zipcode || null,
				codigo_postal_original:
					asNonEmptyString(data.codigo_postal_original) || zipcode || null,
				regimen_fiscal: regimenText || taxRegime || null,
				regimen_fiscal_original:
					asNonEmptyString(data.regimen_fiscal_original) || regimenText || null,
				tax_regime: taxRegime || null,
				domicilio_fiscal: asNonEmptyString(data.domicilio_fiscal) || null,
				fecha_emision:
					asNonEmptyString(data.fecha_emision) ||
					asNonEmptyString(data.fecha_emision_constancia) ||
					null,
				fecha_emision_constancia:
					asNonEmptyString(data.fecha_emision_constancia) ||
					asNonEmptyString(data.fecha_emision) ||
					null,
				fecha_inscripcion: asNonEmptyString(data.fecha_inscripcion) || null,
				estatus_sat: asNonEmptyString(data.estatus_sat) || null,
				actividades_economicas:
					asNonEmptyString(data.actividades_economicas) || null,
				tipo_persona: "fisica",
				tipo_persona_confianza:
					typeof data.tipo_persona_confianza === "number"
						? data.tipo_persona_confianza
						: null,
				tipo_persona_detectado_por:
					asNonEmptyString(data.tipo_persona_detectado_por) || null,
			}
		: null;

	return {
		form,
		extractedPayload,
		missingFields,
		warnings,
		status,
		confirmable,
	};
}

export function formatFileSize(bytes) {
	if (!Number.isFinite(bytes) || bytes < 0) {
		return "";
	}

	if (bytes < 1024) {
		return `${bytes} B`;
	}

	if (bytes < 1024 * 1024) {
		return `${(bytes / 1024).toFixed(1)} KB`;
	}

	return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

export function isPdfFile(file) {
	if (!file) {
		return false;
	}

	const name = String(file.name || "").toLowerCase();
	const type = String(file.type || "").toLowerCase();

	return type === "application/pdf" || name.endsWith(".pdf");
}

export const MAX_TAX_CERTIFICATE_BYTES = 5 * 1024 * 1024;
