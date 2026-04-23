/**
 * Estado de presentación para pedidos de laboratorio (panel paciente).
 */

export function purchaseHasResults(purchase) {
	if (typeof purchase.has_results === "boolean") {
		return purchase.has_results;
	}
	return purchase.study_status === "results_ready";
}

export function purchaseIsInvoicedPipeline(purchase) {
	if (typeof purchase.is_pipeline_invoiced === "boolean") {
		return purchase.is_pipeline_invoiced;
	}
	return Boolean(purchase.has_invoice && purchase.invoice_requested);
}

export function purchaseIsCancelled(purchase) {
	return purchase.study_status === "cancelled";
}

/**
 * Badge principal: Facturado (azul) > Completado (verde) > En proceso (amarillo).
 */
export function getOrderBadgePresentation(purchase) {
	if (purchaseIsCancelled(purchase)) {
		return {
			key: "cancelled",
			label: "Cancelado",
			className:
				"bg-red-500/15 text-red-200 ring-1 ring-red-400/30 dark:bg-red-950/50 dark:text-red-100",
		};
	}
	if (purchaseIsInvoicedPipeline(purchase)) {
		return {
			key: "invoiced",
			label: "Facturado",
			className:
				"bg-sky-500/15 text-sky-100 ring-1 ring-sky-400/35 dark:bg-sky-950/40 dark:text-sky-100",
		};
	}
	if (purchaseHasResults(purchase)) {
		return {
			key: "completed",
			label: "Completado",
			className:
				"bg-emerald-500/15 text-emerald-100 ring-1 ring-emerald-400/35 dark:bg-emerald-950/40 dark:text-emerald-100",
		};
	}
	return {
		key: "processing",
		label: "En proceso",
		className:
			"bg-amber-500/15 text-amber-100 ring-1 ring-amber-400/35 dark:bg-amber-950/40 dark:text-amber-100",
	};
}

/**
 * Acción principal: prioriza ver resultados cuando existen.
 */
export function getPrimaryPurchaseAction(purchase) {
	if (purchaseHasResults(purchase) && purchase.result_view_url) {
		return {
			key: "results",
			label: "Ver resultados",
			href: null,
		};
	}
	if (purchaseIsInvoicedPipeline(purchase) && purchase.invoice_url) {
		return {
			key: "invoice",
			label: "Ver factura",
			href: purchase.invoice_url,
		};
	}
	return {
		key: "order",
		label: "Ver pedido",
		href: purchase.show_detail_url,
	};
}

export function exportLaboratoryPurchasesPageCsv(purchases) {
	const rows = purchases.map((p) => ({
		Estudio: p.study_name ?? "",
		Paciente: p.patient_name ?? "",
		Folio: p.temporarly_hide_gda_order_id ? "" : String(p.gda_order_id ?? ""),
		Laboratorio: p.laboratory_name ?? "",
		Fecha: p.purchased_at_formatted ?? "",
		Estado: getOrderBadgePresentation(p).label,
		Total: p.formatted_total ?? "",
	}));
	const headers = Object.keys(rows[0] ?? { Estudio: "" });
	const esc = (v) => {
		const s = String(v ?? "");
		if (/[",\n]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
		return s;
	};
	const lines = [
		headers.join(","),
		...rows.map((r) => headers.map((h) => esc(r[h])).join(",")),
	];
	const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8;" });
	const url = URL.createObjectURL(blob);
	const a = document.createElement("a");
	a.href = url;
	a.download = `pedidos-laboratorio-${new Date().toISOString().slice(0, 10)}.csv`;
	a.click();
	URL.revokeObjectURL(url);
}
