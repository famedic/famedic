/**
 * Frontend-only RFC hints for personas físicas.
 * Backend remains the source of truth.
 */

export function normalizeRfcInput(value) {
	if (value === null || value === undefined) {
		return "";
	}

	return String(value).replace(/\s+/g, "").toUpperCase();
}

/**
 * @returns {'empty'|'incomplete'|'moral'|'invalid'|'individual'}
 */
export function classifyRfcForIndividualProfile(value) {
	const rfc = normalizeRfcInput(value);

	if (!rfc) {
		return "empty";
	}

	if (rfc.length === 12 && /^[A-ZÑ&]{3}[0-9]{6}[A-Z0-9]{3}$/.test(rfc)) {
		return "moral";
	}

	if (rfc.length < 13) {
		return "incomplete";
	}

	if (rfc.length > 13) {
		return "invalid";
	}

	if (/^[A-ZÑ&]{4}[0-9]{6}[A-Z0-9]{3}$/.test(rfc)) {
		return "individual";
	}

	return "invalid";
}

export function rfcHintMessage(value) {
	const kind = classifyRfcForIndividualProfile(value);

	switch (kind) {
		case "moral":
			return "El RFC corresponde a una persona moral. Actualmente solo permitimos perfiles fiscales de personas físicas.";
		case "incomplete":
			return "El RFC de persona física debe tener 13 caracteres.";
		case "invalid":
			return "El RFC no tiene un formato válido de persona física (XXXX999999XXX).";
		default:
			return null;
	}
}
