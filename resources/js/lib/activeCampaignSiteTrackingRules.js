const BLOCKED_PREFIXES = [
	"/admin",
	"/api",
	"/apigda",
	"/auth",
	"/derechos-arco",
	"/descargar-documento",
	"/efevoo",
	"/efevoopay",
	"/forgot-password",
	"/invoice",
	"/invoice-requests",
	"/laboratory/webhook",
	"/login",
	"/logout",
	"/magic-login",
	"/mis-solicitudes-arco",
	"/payment-methods/3ds",
	"/paypal",
	"/register/invitation",
	"/reset-password",
	"/solicitud-arco",
	"/tax-profiles",
	"/verify-email",
	"/verify-phone",
	"/webhook",
	"/webhooks",
];

const BLOCKED_EXACT_PATHS = new Set([
	"/confirm-password",
	"/email/verification-notification",
	"/phone/verification-notification",
]);

const SENSITIVE_QUERY_KEYS = new Set([
	"code",
	"email",
	"expires",
	"hash",
	"redirect",
	"signature",
	"state",
	"session",
	"session_id",
	"token",
]);

function toUrl(input, origin = "https://famedic.com.mx") {
	try {
		return new URL(input || "/", origin);
	} catch {
		return new URL("/", origin);
	}
}

function normalizePath(pathname) {
	const path = pathname || "/";
	return path !== "/" ? path.replace(/\/+$/, "") : path;
}

function pathStartsWith(path, prefix) {
	return path === prefix || path.startsWith(`${prefix}/`);
}

function hasSensitiveQuery(searchParams) {
	for (const key of searchParams.keys()) {
		const normalized = key.toLowerCase();

		if (SENSITIVE_QUERY_KEYS.has(normalized)) return true;
		if (normalized.includes("token")) return true;
		if (normalized.includes("otp")) return true;
	}

	return false;
}

function hasSensitivePathSegment(path) {
	const segments = path.split("/").filter(Boolean);

	if (segments.some((segment) => segment.toLowerCase().includes("otp"))) {
		return true;
	}

	if (
		segments.includes("laboratory-purchases") &&
		(segments.includes("results") ||
			segments.includes("results-automatic-fetch") ||
			segments.includes("download-pdf") ||
			segments.includes("email-pdf"))
	) {
		return true;
	}

	return false;
}

export function sanitizeTrackingUrl(input, origin = "https://famedic.com.mx") {
	const url = toUrl(input, origin);
	return `${url.origin}${normalizePath(url.pathname)}`;
}

export function shouldTrack(input, origin = "https://famedic.com.mx") {
	const url = toUrl(input, origin);
	const path = normalizePath(url.pathname);

	if (BLOCKED_EXACT_PATHS.has(path)) return false;
	if (BLOCKED_PREFIXES.some((prefix) => pathStartsWith(path, prefix)))
		return false;
	if (hasSensitiveQuery(url.searchParams)) return false;
	if (hasSensitivePathSegment(path)) return false;

	return true;
}
