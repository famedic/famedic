/**
 * Catálogo de procedencia de datos — referencia visual Famedic.
 * Reutilizable por ActiveCampaign Ops y futuras consolas (GA4, Ads, WhatsApp, etc.).
 */

export const DATA_SOURCES = {
	FAMEDIC_DATABASE: {
		key: "FAMEDIC_DATABASE",
		label: "FAMEDIC",
		subtitle: "Database",
		dot: "bg-emerald-500",
		ring: "ring-emerald-200 dark:ring-emerald-900",
		bg: "bg-emerald-50 dark:bg-emerald-950/50",
		text: "text-emerald-800 dark:text-emerald-300",
		border: "border-emerald-200 dark:border-emerald-800",
		legend: "FAMEDIC Database",
		emoji: "🟢",
	},
	ACTIVECAMPAIGN_API: {
		key: "ACTIVECAMPAIGN_API",
		label: "ActiveCampaign",
		subtitle: "Live API",
		dot: "bg-sky-500",
		ring: "ring-sky-200 dark:ring-sky-900",
		bg: "bg-sky-50 dark:bg-sky-950/50",
		text: "text-sky-800 dark:text-sky-300",
		border: "border-sky-200 dark:border-sky-800",
		legend: "ActiveCampaign API",
		emoji: "🔵",
	},
	ACTIVECAMPAIGN_MIRROR: {
		key: "ACTIVECAMPAIGN_MIRROR",
		label: "Mirror Cache",
		subtitle: "TTL 5 min",
		dot: "bg-orange-500",
		ring: "ring-orange-200 dark:ring-orange-900",
		bg: "bg-orange-50 dark:bg-orange-950/50",
		text: "text-orange-800 dark:text-orange-300",
		border: "border-orange-200 dark:border-orange-800",
		legend: "Mirror Cache",
		emoji: "🟠",
	},
	HYBRID: {
		key: "HYBRID",
		label: "Hybrid",
		subtitle: "AC + Famedic",
		dot: "bg-zinc-500",
		ring: "ring-zinc-200 dark:ring-zinc-700",
		bg: "bg-zinc-100 dark:bg-zinc-800/60",
		text: "text-zinc-700 dark:text-zinc-300",
		border: "border-zinc-200 dark:border-zinc-700",
		legend: "Hybrid",
		emoji: "⚫",
	},
	AI_GENERATED: {
		key: "AI_GENERATED",
		label: "AI Generated",
		subtitle: "Calculated",
		dot: "bg-violet-500",
		ring: "ring-violet-200 dark:ring-violet-900",
		bg: "bg-violet-50 dark:bg-violet-950/50",
		text: "text-violet-800 dark:text-violet-300",
		border: "border-violet-200 dark:border-violet-800",
		legend: "AI Generated",
		emoji: "🟣",
	},
	PROXY: {
		key: "PROXY",
		label: "Proxy",
		subtitle: "Estimated",
		dot: "bg-amber-400",
		ring: "ring-amber-200 dark:ring-amber-900",
		bg: "bg-amber-50 dark:bg-amber-950/50",
		text: "text-amber-900 dark:text-amber-300",
		border: "border-amber-200 dark:border-amber-800",
		legend: "Proxy",
		emoji: "🟡",
	},
	// Futuras integraciones (mismo sistema visual)
	GA4: {
		key: "GA4",
		label: "GA4",
		subtitle: "Analytics",
		dot: "bg-orange-600",
		ring: "ring-orange-200",
		bg: "bg-orange-50 dark:bg-orange-950/50",
		text: "text-orange-900 dark:text-orange-300",
		border: "border-orange-200 dark:border-orange-800",
		legend: "Google Analytics 4",
		emoji: "🟠",
	},
	META_ADS: {
		key: "META_ADS",
		label: "Meta Ads",
		subtitle: "API",
		dot: "bg-blue-600",
		ring: "ring-blue-200",
		bg: "bg-blue-50 dark:bg-blue-950/50",
		text: "text-blue-900 dark:text-blue-300",
		border: "border-blue-200 dark:border-blue-800",
		legend: "Meta Ads",
		emoji: "🔵",
	},
	GOOGLE_ADS: {
		key: "GOOGLE_ADS",
		label: "Google Ads",
		subtitle: "API",
		dot: "bg-red-500",
		ring: "ring-red-200",
		bg: "bg-red-50 dark:bg-red-950/50",
		text: "text-red-800 dark:text-red-300",
		border: "border-red-200 dark:border-red-800",
		legend: "Google Ads",
		emoji: "🔴",
	},
	WHATSAPP: {
		key: "WHATSAPP",
		label: "WhatsApp",
		subtitle: "Messaging",
		dot: "bg-green-500",
		ring: "ring-green-200",
		bg: "bg-green-50 dark:bg-green-950/50",
		text: "text-green-800 dark:text-green-300",
		border: "border-green-200 dark:border-green-800",
		legend: "WhatsApp",
		emoji: "🟢",
	},
	SMS: {
		key: "SMS",
		label: "SMS",
		subtitle: "Messaging",
		dot: "bg-teal-500",
		ring: "ring-teal-200",
		bg: "bg-teal-50 dark:bg-teal-950/50",
		text: "text-teal-800 dark:text-teal-300",
		border: "border-teal-200 dark:border-teal-800",
		legend: "SMS",
		emoji: "🟢",
	},
	PUSH: {
		key: "PUSH",
		label: "Push",
		subtitle: "Notifications",
		dot: "bg-indigo-500",
		ring: "ring-indigo-200",
		bg: "bg-indigo-50 dark:bg-indigo-950/50",
		text: "text-indigo-800 dark:text-indigo-300",
		border: "border-indigo-200 dark:border-indigo-800",
		legend: "Push",
		emoji: "🟣",
	},
	MAILGUN: {
		key: "MAILGUN",
		label: "Mailgun",
		subtitle: "Email",
		dot: "bg-rose-500",
		ring: "ring-rose-200",
		bg: "bg-rose-50 dark:bg-rose-950/50",
		text: "text-rose-800 dark:text-rose-300",
		border: "border-rose-200 dark:border-rose-800",
		legend: "Mailgun",
		emoji: "🔴",
	},
	STRIPE: {
		key: "STRIPE",
		label: "Stripe",
		subtitle: "Payments",
		dot: "bg-violet-600",
		ring: "ring-violet-200",
		bg: "bg-violet-50 dark:bg-violet-950/50",
		text: "text-violet-900 dark:text-violet-300",
		border: "border-violet-200 dark:border-violet-800",
		legend: "Stripe",
		emoji: "🟣",
	},
	ODESSA: {
		key: "ODESSA",
		label: "Odessa",
		subtitle: "Lab platform",
		dot: "bg-cyan-600",
		ring: "ring-cyan-200",
		bg: "bg-cyan-50 dark:bg-cyan-950/50",
		text: "text-cyan-900 dark:text-cyan-300",
		border: "border-cyan-200 dark:border-cyan-800",
		legend: "Odessa",
		emoji: "🔵",
	},
	MURGUIA: {
		key: "MURGUIA",
		label: "Murguía",
		subtitle: "Membership",
		dot: "bg-lime-600",
		ring: "ring-lime-200",
		bg: "bg-lime-50 dark:bg-lime-950/50",
		text: "text-lime-900 dark:text-lime-300",
		border: "border-lime-200 dark:border-lime-800",
		legend: "Murguía",
		emoji: "🟢",
	},
	LABORATORIOS: {
		key: "LABORATORIOS",
		label: "Laboratorios",
		subtitle: "Domain",
		dot: "bg-emerald-600",
		ring: "ring-emerald-200",
		bg: "bg-emerald-50 dark:bg-emerald-950/50",
		text: "text-emerald-900 dark:text-emerald-300",
		border: "border-emerald-200 dark:border-emerald-800",
		legend: "Laboratorios",
		emoji: "🟢",
	},
	CLINICAL_AI: {
		key: "CLINICAL_AI",
		label: "Interpretador IA",
		subtitle: "Clinical AI",
		dot: "bg-fuchsia-500",
		ring: "ring-fuchsia-200",
		bg: "bg-fuchsia-50 dark:bg-fuchsia-950/50",
		text: "text-fuchsia-900 dark:text-fuchsia-300",
		border: "border-fuchsia-200 dark:border-fuchsia-800",
		legend: "Interpretador Clínico IA",
		emoji: "🟣",
	},
	PRESCRIPTION_READER: {
		key: "PRESCRIPTION_READER",
		label: "Lector Recetas",
		subtitle: "AI OCR",
		dot: "bg-purple-500",
		ring: "ring-purple-200",
		bg: "bg-purple-50 dark:bg-purple-950/50",
		text: "text-purple-900 dark:text-purple-300",
		border: "border-purple-200 dark:border-purple-800",
		legend: "Lector de Recetas",
		emoji: "🟣",
	},
};

export const DATA_MODES = {
	LIVE: {
		key: "LIVE",
		label: "Live",
		className:
			"border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300",
	},
	CACHE: {
		key: "CACHE",
		label: "Cache",
		className:
			"border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-800 dark:bg-orange-950/40 dark:text-orange-300",
	},
	LOCAL: {
		key: "LOCAL",
		label: "Local",
		className:
			"border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300",
	},
	CALCULATED: {
		key: "CALCULATED",
		label: "Calculated",
		className:
			"border-zinc-200 bg-zinc-100 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300",
	},
	ESTIMATED: {
		key: "ESTIMATED",
		label: "Estimated",
		className:
			"border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300",
	},
};

export const DATA_QUALITY = {
	A: {
		key: "A",
		label: "A",
		title: "Excelente",
		description: "Dato directo",
		className:
			"border-emerald-300 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-500",
	},
	B: {
		key: "B",
		label: "B",
		title: "Bueno",
		description: "Cache reciente",
		className:
			"border-sky-300 bg-sky-600 text-white dark:border-sky-500 dark:bg-sky-500",
	},
	C: {
		key: "C",
		label: "C",
		title: "Aceptable",
		description: "Dato híbrido",
		className:
			"border-zinc-300 bg-zinc-600 text-white dark:border-zinc-500 dark:bg-zinc-500",
	},
	D: {
		key: "D",
		label: "D",
		title: "Débil",
		description: "Estimado",
		className:
			"border-amber-300 bg-amber-500 text-white dark:border-amber-400 dark:bg-amber-500",
	},
	F: {
		key: "F",
		label: "F",
		title: "Sin instrumentación",
		description: "Sin instrumentación",
		className:
			"border-rose-300 bg-rose-600 text-white dark:border-rose-500 dark:bg-rose-500",
	},
};

export const DATA_STATUSES = {
	roadmap: {
		key: "roadmap",
		label: "ROADMAP",
		detail: "Próximamente",
		className:
			"border-violet-200 bg-violet-50 text-violet-900 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-200",
	},
	instrumentation: {
		key: "instrumentation",
		label: "INSTRUMENTACIÓN",
		detail: "Pendiente",
		className:
			"border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200",
	},
	configuration: {
		key: "configuration",
		label: "CONFIGURACIÓN",
		detail: "No habilitado",
		className:
			"border-zinc-200 bg-zinc-100 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200",
	},
	unavailable: {
		key: "unavailable",
		label: "NO DISPONIBLE",
		detail: "Servicio deshabilitado",
		className:
			"border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200",
	},
};

/** Fuentes mostradas en la leyenda fija del Operations Center */
export const LEGEND_SOURCES = [
	"FAMEDIC_DATABASE",
	"ACTIVECAMPAIGN_API",
	"ACTIVECAMPAIGN_MIRROR",
	"HYBRID",
	"AI_GENERATED",
	"PROXY",
];

/**
 * Detecta estados textuales legacy y los mapea a DataStatusBadge.
 * @param {unknown} value
 * @returns {keyof typeof DATA_STATUSES | null}
 */
export function detectDataStatus(value) {
	if (value == null || value === "" || value === "—") {
		return null;
	}
	const v = String(value).toLowerCase().normalize("NFD").replace(/\p{M}/gu, "");
	if (
		v.includes("proximamente") ||
		v.includes("roadmap") ||
		v.includes("en diseno") ||
		v.includes("planned")
	) {
		return "roadmap";
	}
	if (
		v.includes("requiere instrument") ||
		v.includes("instrumentacion") ||
		v.includes("sin instrument")
	) {
		return "instrumentation";
	}
	if (
		v.includes("no habilitado") ||
		v.includes("deshabilitado") ||
		v === "off" ||
		v.includes("disabled")
	) {
		return "configuration";
	}
	if (
		v.includes("no disponible") ||
		v.includes("sin probar") ||
		v.includes("unavailable") ||
		v.includes("not available")
	) {
		return "unavailable";
	}
	return null;
}

export function resolveSource(source) {
	if (!source) {
		return DATA_SOURCES.HYBRID;
	}
	const key = String(source).toUpperCase().replace(/[\s-]+/g, "_");
	return DATA_SOURCES[key] || DATA_SOURCES[source] || DATA_SOURCES.HYBRID;
}

export function resolveMode(mode) {
	if (!mode) {
		return DATA_MODES.LOCAL;
	}
	const key = String(mode).toUpperCase();
	return DATA_MODES[key] || DATA_MODES.LOCAL;
}

export function resolveQuality(quality) {
	if (!quality) {
		return DATA_QUALITY.C;
	}
	const key = String(quality).toUpperCase();
	return DATA_QUALITY[key] || DATA_QUALITY.C;
}
