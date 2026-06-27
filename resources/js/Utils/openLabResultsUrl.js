/**
 * Navega a resultados en la misma pestaña (siempre funciona tras OTP/async).
 */
export function navigateToLabResults(url) {
	if (!url) return { opened: false, method: "none" };

	window.location.assign(url);
	return { opened: true, method: "same-tab" };
}

/**
 * Intenta nueva pestaña; si el navegador bloquea, abre en la misma pestaña.
 * Útil cuando ya hay sesión OTP activa (sin modal).
 */
export function openLabResultsInNewTabOrSame(url) {
	if (!url) return { opened: false, method: "none", popupBlocked: false };

	let popup = null;
	try {
		popup = window.open(url, "_blank", "noopener,noreferrer");
	} catch {
		popup = null;
	}

	if (popup) {
		try {
			popup.focus?.();
		} catch {
			// ignore
		}
		return { opened: true, method: "tab", popupBlocked: false };
	}

	window.location.assign(url);
	return { opened: true, method: "same-tab", popupBlocked: true };
}

/** @deprecated Usar navigateToLabResults o openLabResultsInNewTabOrSame según el flujo. */
export function openLabResultsUrl(url) {
	return openLabResultsInNewTabOrSame(url);
}
