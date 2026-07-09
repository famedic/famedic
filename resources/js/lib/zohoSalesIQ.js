import { router } from "@inertiajs/react";

const SCRIPT_ID = "zsiqscript";
const MAX_STRING_LENGTH = 500;
const MAX_PAYLOAD_KEYS = 25;

const FORBIDDEN_KEY_PATTERN =
	/(password|token|otp|secret|card|cvv|cvc|pan|raw_response|gateway_response|remember)/i;

let initialized = false;
let latestVisitorContext = null;
/** Campos seguros de Zobot (último evento / intent) — se fusionan en visitor.info. */
let latestZobotFields = {};

function isBrowser() {
	return typeof window !== "undefined" && typeof document !== "undefined";
}

function getConfig() {
	return window.__FAMEDIC_ZOHO_SALESIQ__ ?? {};
}

function isEnabled() {
	return !!getConfig().enabled && !!getConfig().widgetUrl;
}

function getWidgetUrl() {
	return getConfig().widgetUrl ?? null;
}

function truncateString(value) {
	if (typeof value !== "string") {
		return value;
	}

	return value.length > MAX_STRING_LENGTH
		? `${value.slice(0, MAX_STRING_LENGTH)}…`
		: value;
}

function sanitizePayloadValue(value) {
	if (value === null || value === undefined) {
		return undefined;
	}

	if (typeof value === "string") {
		return truncateString(value);
	}

	if (typeof value === "number" || typeof value === "boolean") {
		return value;
	}

	if (Array.isArray(value)) {
		return value
			.slice(0, 10)
			.map((item) => sanitizePayloadValue(item))
			.filter((item) => item !== undefined);
	}

	return truncateString(String(value));
}

function sanitizePayload(payload) {
	if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
		return {};
	}

	const cleaned = {};

	for (const [key, value] of Object.entries(payload)) {
		if (FORBIDDEN_KEY_PATTERN.test(key)) {
			continue;
		}

		const sanitized = sanitizePayloadValue(value);

		if (sanitized !== undefined) {
			cleaned[key] = sanitized;
		}

		if (Object.keys(cleaned).length >= MAX_PAYLOAD_KEYS) {
			break;
		}
	}

	return cleaned;
}

function whenZohoReady(callback) {
	if (!isBrowser()) {
		return;
	}

	if (window.$zoho?.salesiq?.visitor) {
		callback();
		return;
	}

	window.addEventListener("zoho-salesiq-ready", callback, { once: true });
}

function setupZohoGlobals() {
	window.$zoho = window.$zoho || {};
	window.$zoho.salesiq = window.$zoho.salesiq || { ready() {} };

	const previousReady = window.$zoho.salesiq.ready;

	window.$zoho.salesiq.ready = function zohoSalesIqReady() {
		if (typeof previousReady === "function") {
			previousReady();
		}

		window.$zoho.salesiq.tracking?.on?.();

		const position = getConfig().floatPosition ?? "left";
		window.$zoho.salesiq.floatbutton?.position?.(position);

		window.dispatchEvent(new Event("zoho-salesiq-ready"));
	};
}

function loadZohoWidget() {
	if (!isBrowser()) {
		return;
	}

	if (document.getElementById(SCRIPT_ID)) {
		return;
	}

	const widgetUrl = getWidgetUrl();

	if (!widgetUrl) {
		return;
	}

	setupZohoGlobals();

	const script = document.createElement("script");
	script.id = SCRIPT_ID;
	script.defer = true;
	script.src = widgetUrl;
	document.body.appendChild(script);
}

function buildVisitorInfo(context, extra = {}) {
	const page =
		context?.page ??
		`${window.location.pathname}${window.location.search}`;

	return sanitizePayload({
		"Pagina actual": page,
		Hostname: window.location.hostname,
		Ambiente: context?.env ?? getConfig().env,
		Ruta: context?.route,
		"Usuario ID": context?.userId,
		"Cliente ID": context?.customerId,
		"Membresia activa": context?.membershipActive,
		"Estudios en carrito": context?.cart?.itemCount,
		"Marcas en carrito": context?.cart?.brands?.join(", "),
		...latestZobotFields,
		...extra,
	});
}

function identifyVisitor(context) {
	if (!context || !window.$zoho?.salesiq?.visitor) {
		return;
	}

	const visitor = window.$zoho.salesiq.visitor;

	if (context.name) {
		visitor.name?.(context.name);
	}

	if (context.email) {
		visitor.email?.(context.email);
	}

	if (context.phone) {
		visitor.contactnumber?.(context.phone);
	}

	const info = buildVisitorInfo(context);

	if (Object.keys(info).length > 0) {
		visitor.info?.(info);
	}
}

function trackZohoPageView(context = latestVisitorContext) {
	if (!window.$zoho?.salesiq?.visitor?.customaction) {
		return;
	}

	const path =
		context?.page ??
		`${window.location.pathname}${window.location.search}`;

	window.$zoho.salesiq.visitor.customaction(`Page: ${path}`);

	const info = buildVisitorInfo(context);

	if (Object.keys(info).length > 0) {
		window.$zoho.salesiq.visitor.info?.(info);
	}
}

export function setZohoSalesIqVisitorContext(context) {
	if (!isEnabled() || !context) {
		return;
	}

	latestVisitorContext = context;

	whenZohoReady(() => {
		identifyVisitor(context);
	});
}

/**
 * Actualiza campos de contexto para Zobot / operador (Fase 3).
 * Se fusionan en visitor.info y se conservan entre pageviews.
 */
export function setZohoZobotVisitorFields(fields = {}) {
	if (!isEnabled() || !isBrowser()) {
		return;
	}

	const cleaned = sanitizePayload(fields);

	if (Object.keys(cleaned).length === 0) {
		return;
	}

	latestZobotFields = {
		...latestZobotFields,
		...cleaned,
	};

	whenZohoReady(() => {
		const info = buildVisitorInfo(latestVisitorContext);

		if (Object.keys(info).length > 0) {
			window.$zoho?.salesiq?.visitor?.info?.(info);
		}
	});
}

/**
 * Base para eventos de negocio (Fase 2+) y contexto Zobot (Fase 3).
 * Valida, limpia el payload y lo envía si SalesIQ está disponible.
 */
export function trackZohoSalesIqEvent(eventName, payload = {}) {
	if (!isEnabled() || !isBrowser()) {
		return;
	}

	const name =
		typeof eventName === "string" ? eventName.trim() : String(eventName);

	if (!name) {
		return;
	}

	const cleaned = sanitizePayload(payload);

	whenZohoReady(() => {
		window.$zoho?.salesiq?.visitor?.customaction?.(name);

		const info = buildVisitorInfo(latestVisitorContext, {
			Evento: name,
			...cleaned,
		});

		if (Object.keys(info).length > 0) {
			window.$zoho?.salesiq?.visitor?.info?.(info);
		}
	});
}

function handleInertiaFinish(event) {
	const context = event?.detail?.page?.props?.zohoSalesIq ?? null;

	if (context) {
		setZohoSalesIqVisitorContext(context);
	}

	whenZohoReady(() => trackZohoPageView(context ?? latestVisitorContext));
}

export function initZohoSalesIQTracking(initialPageProps = null) {
	if (!isBrowser() || initialized || !isEnabled()) {
		return;
	}

	initialized = true;

	loadZohoWidget();

	const initialContext = initialPageProps?.zohoSalesIq ?? null;

	if (initialContext) {
		setZohoSalesIqVisitorContext(initialContext);
	}

	whenZohoReady(() => trackZohoPageView(initialContext ?? latestVisitorContext));
	router.on("finish", handleInertiaFinish);
}
