/**
 * Presentación consistente de totales de pedidos de laboratorio (solo UI).
 * El backend persiste total_cents (bruto) y coupon_discount_cents por separado.
 */

function formatMxFromCents(cents) {
	if (cents == null || cents === "") return "—";
	const n = Number(cents);
	if (Number.isNaN(n)) return "—";
	return (n / 100).toLocaleString("es-MX", {
		style: "currency",
		currency: "MXN",
	});
}

function resolveCreditAppliedCents(purchase) {
	const couponDiscountCents = Number(purchase?.coupon_discount_cents || 0);
	const firstTx = purchase?.transactions?.[0];
	const couponFromTxCents = Number(firstTx?.details?.coupon_amount_cents || 0);
	let creditAppliedCents = Math.max(couponDiscountCents, couponFromTxCents);

	const paymentMethodKey =
		purchase?.payment_method || firstTx?.payment_method || "";

	if (
		creditAppliedCents === 0 &&
		paymentMethodKey === "coupon_balance"
	) {
		creditAppliedCents = Number(purchase?.total_cents || 0);
	}

	return Math.max(0, creditAppliedCents);
}

/**
 * @param {object} purchase LaboratoryPurchase con items y transacciones opcionales
 */
export function buildLaboratoryPurchaseTotals(purchase) {
	const items = purchase?.laboratory_purchase_items || [];
	const itemsSubtotalCents = items.reduce(
		(acc, item) => acc + Number(item.price_cents || 0),
		0,
	);

	const grossTotalCents =
		Number(purchase?.total_cents) ||
		itemsSubtotalCents ||
		0;

	const catalogDiscountCents = Math.max(
		0,
		itemsSubtotalCents - grossTotalCents,
	);

	const creditAppliedCents = resolveCreditAppliedCents(purchase);

	const netTotalCents = Math.max(0, grossTotalCents - creditAppliedCents);

	const hasAppliedCreditBalance =
		creditAppliedCents > 0 ||
		(purchase?.payment_method || purchase?.transactions?.[0]?.payment_method) ===
			"coupon_balance";

	const subtotalDisplayCents =
		itemsSubtotalCents > 0 ? itemsSubtotalCents : grossTotalCents;

	return {
		subtotalCents: subtotalDisplayCents,
		grossTotalCents,
		catalogDiscountCents,
		creditAppliedCents,
		netTotalCents,
		hasAppliedCreditBalance,
		hasCatalogDiscount: catalogDiscountCents > 0,
		subtotal: formatMxFromCents(subtotalDisplayCents),
		catalogDiscount: formatMxFromCents(catalogDiscountCents),
		creditApplied:
			creditAppliedCents > 0 ? formatMxFromCents(creditAppliedCents) : null,
		netTotal:
			purchase?.formatted_net_total ?? formatMxFromCents(netTotalCents),
		creditAppliedMessage:
			creditAppliedCents > 0
				? `Se aplicó un crédito a favor de ${formatMxFromCents(creditAppliedCents)}.`
				: null,
	};
}
