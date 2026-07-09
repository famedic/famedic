import { trackZohoSalesIqEvent } from "@/lib/zohoSalesIQ";
import { syncZobotContextFromEvent } from "@/lib/zohoZobotContext";

const QUERY_MAX_LENGTH = 100;

function isBrowser() {
	return typeof window !== "undefined";
}

function getConfigEnv() {
	if (!isBrowser()) {
		return "";
	}

	return window.__FAMEDIC_ZOHO_SALESIQ__?.env ?? "";
}

function shouldLogEvents() {
	const env = getConfigEnv();

	return env !== "production" && env !== "";
}

export function getZohoCurrentPage() {
	if (!isBrowser()) {
		return "";
	}

	return window.location.pathname;
}

export function truncateZohoQuery(query, maxLength = QUERY_MAX_LENGTH) {
	if (typeof query !== "string") {
		return "";
	}

	const trimmed = query.trim();

	if (trimmed.length <= maxLength) {
		return trimmed;
	}

	return `${trimmed.slice(0, maxLength)}…`;
}

export function maskPromoCode(code) {
	if (typeof code !== "string" || code.length < 3) {
		return undefined;
	}

	const trimmed = code.trim();

	if (trimmed.length <= 3) {
		return `${trimmed[0]}**`;
	}

	return `${trimmed.slice(0, 3)}***${trimmed.slice(-2)}`;
}

export function mapCouponFailureReason(message = "") {
	const normalized = String(message).toLowerCase();

	if (
		normalized.includes("expir") ||
		normalized.includes("programado") ||
		normalized.includes("vigencia")
	) {
		return "expired";
	}

	if (
		normalized.includes("compra mínima") ||
		normalized.includes("compra minima") ||
		normalized.includes("mínima") ||
		normalized.includes("minima")
	) {
		return "min_purchase";
	}

	if (
		normalized.includes("ya fue utilizado") ||
		normalized.includes("ya usado") ||
		normalized.includes("agotado")
	) {
		return "already_used";
	}

	if (
		normalized.includes("no aplica") ||
		normalized.includes("no puedes combinar") ||
		normalized.includes("carrito")
	) {
		return "not_applicable";
	}

	if (normalized.includes("inválido") || normalized.includes("invalido")) {
		return "invalid";
	}

	return "unknown";
}

export function mapPaymentGateway(paymentMethod) {
	const value = String(paymentMethod ?? "").toLowerCase();

	if (!value) {
		return "unknown";
	}

	if (value === "paypal") {
		return "paypal";
	}

	if (value === "odessa" || value.includes("odessa")) {
		return "odessa";
	}

	if (value === "coupon_balance") {
		return "coupon_balance";
	}

	if (/^\d+$/.test(value)) {
		return "efevoopay";
	}

	return "unknown";
}

export function mapPaymentErrorCode(message = "") {
	const normalized = String(message).toLowerCase();

	if (normalized.includes("fondos")) {
		return "insufficient_funds";
	}

	if (normalized.includes("rechaz")) {
		return "bank_declined";
	}

	if (normalized.includes("no está disponible") || normalized.includes("no esta disponible")) {
		return "bank_unavailable";
	}

	if (
		normalized.includes("datos de la tarjeta") ||
		normalized.includes("tarjeta no")
	) {
		return "invalid_card";
	}

	return "unknown";
}

export function sanitizeSafeUserMessage(message) {
	if (typeof message !== "string") {
		return "No pudimos completar el pago.";
	}

	const trimmed = message.trim();

	return trimmed || "No pudimos completar el pago.";
}

export function trackZohoBusinessEvent(eventName, payload = {}) {
	if (shouldLogEvents()) {
		console.debug(`[Zoho SalesIQ] ${eventName}`, payload);
	}

	trackZohoSalesIqEvent(eventName, payload);
	syncZobotContextFromEvent(eventName, payload);
}
