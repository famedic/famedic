/** Caracteres legibles sin O/0, I/1 para códigos promocionales. */
const SAFE_CHARS = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";

/**
 * Genera un código promocional legible, p. ej. FAM-8F3K o EVENTO-7X2Q.
 *
 * @param {string} [prefix='FAM']
 * @param {number} [segmentLength=4]
 */
export function generatePromoCode(prefix = "FAM", segmentLength = 4) {
	const cleanPrefix = String(prefix ?? "FAM")
		.toUpperCase()
		.replace(/[^A-Z0-9]/g, "")
		.slice(0, 12);
	const length = Math.max(3, Math.min(8, Number(segmentLength) || 4));
	let segment = "";
	for (let i = 0; i < length; i++) {
		segment += SAFE_CHARS[Math.floor(Math.random() * SAFE_CHARS.length)];
	}
	return `${cleanPrefix || "FAM"}-${segment}`;
}

export function normalizePromoCodeInput(value) {
	return String(value ?? "")
		.toUpperCase()
		.replace(/\s+/g, "");
}
