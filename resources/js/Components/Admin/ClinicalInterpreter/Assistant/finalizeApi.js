import { getCsrf } from "./interpretApi";
import {
	summarizeValidationItems,
	toApiValidationItems,
} from "./labSelection";

/**
 * Same payload shape MatchingEngine uses for commercial + clinical order APIs.
 * Only API-safe fields; ignored/deferred stay as ignored (excluded from order engine).
 */
export function buildCommercialContext({
	interpretation,
	validatedItems = [],
	documentMeta = null,
	metrics = null,
}) {
	const doc = documentMeta ? { ...documentMeta } : null;
	if (doc) {
		delete doc.preview_url;
		delete doc.contents;
		delete doc.base64;
	}

	return {
		session_id: interpretation?.session_id || null,
		items: toApiValidationItems(validatedItems),
		document: doc,
		interpretation: interpretation || null,
		metrics: metrics || null,
	};
}

async function postJson(routeName, body) {
	try {
		const res = await fetch(route(routeName), {
			method: "POST",
			headers: {
				Accept: "application/json",
				"Content-Type": "application/json",
				"X-Requested-With": "XMLHttpRequest",
				"X-CSRF-TOKEN": getCsrf(),
			},
			credentials: "same-origin",
			body: JSON.stringify(body),
		});
		const data = await res.json().catch(() => ({}));
		if (!res.ok || !data.ok) {
			return {
				ok: false,
				message:
					data.message ||
					data.errors?.items?.[0] ||
					"No se pudo completar la acción. Intenta de nuevo.",
				status: res.status,
			};
		}
		return { ok: true, data };
	} catch {
		return {
			ok: false,
			message: "Se interrumpió la conexión. Revisa tu red e intenta de nuevo.",
		};
	}
}

export function fetchCommercialProposal(context) {
	return postJson("admin.clinical-interpreter.commercial.proposal", context);
}

export function storeClinicalOrder(context) {
	return postJson("admin.clinical-interpreter.clinical-orders.store", context);
}

export function openClinicalOrder(uuid) {
	if (!uuid) return;
	window.location.href = route(
		"admin.clinical-interpreter.clinical-orders.show",
		uuid,
	);
}

/** Normalize proposal for the order summary UI. */
export function buildOrderSummaryView({
	proposal,
	interpretation,
	validatedItems = [],
}) {
	const summary = proposal?.summary || {};
	const labs = proposal?.groups?.laboratories || [];
	const packages = proposal?.packages || [];
	const itemStats = summarizeValidationItems(validatedItems);

	const includedItems = (validatedItems || []).filter((i) =>
		["confirmed", "corrected"].includes(i.validation_status),
	);
	const omittedItems = (validatedItems || []).filter(
		(i) =>
			i.validation_status === "ignored" &&
			(i.resolution === "omitted" || !i.resolution),
	);
	const pendingItems = (validatedItems || []).filter(
		(i) =>
			i.validation_status === "ignored" && i.resolution === "deferred",
	);

	const studies =
		labs.length > 0
			? labs.map((lab) => ({
					id: lab.detection_id || lab.laboratory_test_id || lab.name,
					name: lab.name || lab.detected_name || "Estudio",
					detectedName: lab.detected_name || null,
					laboratory: lab.laboratory || null,
					price: lab.price || null,
					deliveryTime: lab.delivery_time || null,
				}))
			: includedItems.map((i) => ({
					id: i.detection_id,
					name: i.match?.name || i.detected_name || "Estudio",
					detectedName: i.detected_name || null,
					laboratory: i.match?.laboratory || null,
					price: i.match?.price || null,
					deliveryTime: i.match?.delivery_time || null,
				}));

	const participatingLabs = [
		...new Set(studies.map((s) => s.laboratory).filter(Boolean)),
	];

	const estimatedTime =
		studies.find((s) => s.deliveryTime)?.deliveryTime ||
		labs.find((l) => l.delivery_time)?.delivery_time ||
		null;

	const savingsCents = packages.reduce(
		(sum, pkg) => sum + (Number(pkg.savings_cents) || 0),
		0,
	);
	const savingsLabel =
		summary.discounts ||
		(savingsCents > 0
			? `$${(savingsCents / 100).toFixed(2)} MXN`
			: null);

	const patient = interpretation?.patient || {};

	return {
		patientName: patient.name || null,
		patientAge: patient.age ?? null,
		patientSex: patient.sex ?? null,
		studiesCount: summary.studies_count ?? studies.length,
		studies,
		omittedStudies: omittedItems.map((i) => ({
			id: i.detection_id,
			name: i.detected_name || i.match?.name || "Estudio",
		})),
		pendingStudies: pendingItems.map((i) => ({
			id: i.detection_id,
			name: i.detected_name || i.match?.name || "Estudio",
		})),
		counts: {
			included: itemStats.included,
			omitted: itemStats.omitted,
			pending: itemStats.pendingReview,
		},
		total: summary.total || summary.price_total || summary.subtotal || null,
		subtotal: summary.subtotal || null,
		participatingLabs,
		estimatedTime,
		packagesCount: packages.length,
		packages,
		savingsLabel,
		savingsCents,
		canGenerateOrder: itemStats.included > 0,
	};
}
