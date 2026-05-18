/**
 * Abre resultados de laboratorio evitando el bloqueo de ventanas emergentes tras OTP/async.
 *
 * Uso: llamar prepareLabResultsPopup() de forma síncrona en el onClick del usuario,
 * luego complete(url) cuando OTP esté validado (o abort() si cancela el modal).
 */
export function prepareLabResultsPopup() {
	let popup = null;

	try {
		popup = window.open("about:blank", "_blank", "noopener,noreferrer");
	} catch {
		popup = null;
	}

	return {
		complete(url) {
			if (!url) {
				this.abort();
				return { opened: false, method: "none", popupBlocked: false };
			}

			if (popup && !popup.closed) {
				try {
					popup.location.href = url;
					popup.focus?.();
					return { opened: true, method: "tab", popupBlocked: false };
				} catch {
					// Continúa con fallback en la misma pestaña.
				}
			}

			window.location.assign(url);
			return { opened: true, method: "same-tab", popupBlocked: popup === null };
		},
		abort() {
			if (popup && !popup.closed) {
				try {
					popup.close();
				} catch {
					// ignore
				}
			}
			popup = null;
		},
	};
}

/** Apertura directa (sin OTP previo); usa nueva pestaña o la misma si el navegador bloquea. */
export function openLabResultsUrl(url) {
	if (!url) return { opened: false, method: "none", popupBlocked: false };

	const popup = prepareLabResultsPopup();
	return popup.complete(url);
}
