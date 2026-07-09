/**
 * Contexto mínimo para Zobot (Fase 3).
 * Mapea eventos de negocio → intents y campos seguros en visitor.info.
 * La lógica conversacional vive en el portal Zoho; aquí solo se pasa contexto.
 */

import { setZohoZobotVisitorFields } from "@/lib/zohoSalesIQ";

export const ZOBOT_INTENTS = Object.freeze({
	HELP_PAYMENT: "help_payment",
	HELP_CART: "help_cart",
	HELP_STUDY_SEARCH: "help_study_search",
	HELP_COUPON_BALANCE: "help_coupon_balance",
	HELP_MEMBERSHIP: "help_membership",
	HELP_APPOINTMENT: "help_appointment",
	TALK_TO_HUMAN: "talk_to_human",
	GENERAL_HELP: "general_help",
});

/** Evento de negocio → intent sugerido para el bot / operador. */
export const EVENT_TO_INTENT = Object.freeze({
	payment_failed: ZOBOT_INTENTS.HELP_PAYMENT,
	cart_idle: ZOBOT_INTENTS.HELP_CART,
	cart_viewed: ZOBOT_INTENTS.HELP_CART,
	checkout_started: ZOBOT_INTENTS.HELP_CART,
	search_no_results: ZOBOT_INTENTS.HELP_STUDY_SEARCH,
	coupon_failed: ZOBOT_INTENTS.HELP_COUPON_BALANCE,
	balance_credit_available: ZOBOT_INTENTS.HELP_COUPON_BALANCE,
	membership_checkout_started: ZOBOT_INTENTS.HELP_MEMBERSHIP,
	human_help_requested: ZOBOT_INTENTS.TALK_TO_HUMAN,
	checkout_step_changed: ZOBOT_INTENTS.GENERAL_HELP,
	payment_success: ZOBOT_INTENTS.GENERAL_HELP,
});

export const HUMAN_ATTENTION_HOURS = Object.freeze({
	timezone: "America/Monterrey",
	operator: "Lydia",
	department: "Atencion a Clientes",
	weekdays: "Lunes a viernes 8:00 AM – 6:00 PM",
	saturday: "Sabado 8:00 AM – 1:00 PM",
	sunday: "Domingo sin atencion humana",
	summary:
		"Lun–Vie 8:00–18:00; Sab 8:00–13:00; Dom cerrado (America/Monterrey)",
});

/**
 * @param {string} eventName
 * @returns {string}
 */
export function resolveZobotIntentFromEvent(eventName) {
	return EVENT_TO_INTENT[eventName] ?? ZOBOT_INTENTS.GENERAL_HELP;
}

/**
 * Construye campos seguros (etiquetas en español) para visitor.info.
 * El Zobot / operador puede leerlos en el portal SalesIQ.
 *
 * @param {string} eventName
 * @param {Record<string, unknown>} payload
 * @returns {Record<string, string|number|boolean>}
 */
export function buildZobotVisitorFields(eventName, payload = {}) {
	const intent = resolveZobotIntentFromEvent(eventName);
	const fields = {
		"Ultimo evento": eventName,
		"Intent sugerido": intent,
		"Horario atencion humana": HUMAN_ATTENTION_HOURS.summary,
		"Operador humano": HUMAN_ATTENTION_HOURS.operator,
	};

	if (payload.topic) {
		fields["Tema ayuda"] = String(payload.topic);
	}

	if (payload.source) {
		fields["Fuente ayuda"] = String(payload.source);
	}

	if (payload.brand) {
		fields.Marca = String(payload.brand);
	}

	if (payload.step) {
		fields["Paso checkout"] = String(payload.step);
	}

	if (payload.previous_step) {
		fields["Paso anterior"] = String(payload.previous_step);
	}

	if (payload.checkout_type) {
		fields["Tipo checkout"] = String(payload.checkout_type);
	}

	if (typeof payload.item_count === "number") {
		fields["Estudios en carrito"] = payload.item_count;
	}

	if (typeof payload.cart_total_cents === "number") {
		fields["Monto carrito cents"] = payload.cart_total_cents;
	}

	if (typeof payload.total_cents === "number") {
		fields["Total cents"] = payload.total_cents;
	}

	if (typeof payload.amount_cents === "number") {
		fields["Monto membresia cents"] = payload.amount_cents;
	}

	if (typeof payload.balance_amount_cents === "number") {
		fields["Saldo a favor cents"] = payload.balance_amount_cents;
	}

	if (payload.has_balance_credit !== undefined) {
		fields["Tiene saldo a favor"] = Boolean(payload.has_balance_credit);
	}

	if (payload.safe_error_code) {
		fields["Codigo error pago"] = String(payload.safe_error_code);
	}

	if (payload.safe_message) {
		fields["Mensaje error pago"] = String(payload.safe_message);
	}

	if (payload.gateway) {
		fields["Gateway pago"] = String(payload.gateway);
	}

	if (payload.reason_code) {
		fields["Motivo cupon"] = String(payload.reason_code);
	}

	if (payload.coupon_type) {
		fields["Tipo cupon"] = String(payload.coupon_type);
	}

	if (payload.query) {
		fields["Busqueda sin resultados"] = String(payload.query);
	}

	if (payload.plan) {
		fields["Plan membresia"] = String(payload.plan);
	}

	if (payload.purchase_id) {
		fields["Pedido ID"] = payload.purchase_id;
	}

	return fields;
}

/**
 * Actualiza visitor.info con contexto de bot tras un evento de negocio.
 * No-op si Zoho está deshabilitado (delegado a setZohoZobotVisitorFields).
 *
 * @param {string} eventName
 * @param {Record<string, unknown>} payload
 */
export function syncZobotContextFromEvent(eventName, payload = {}) {
	const fields = buildZobotVisitorFields(eventName, payload);
	setZohoZobotVisitorFields(fields);
}
