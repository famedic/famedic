import {
	couponMeetsMinPurchase,
	isCouponApplicableForCheckout,
	isCouponWithinValidity,
} from "@/lib/couponEligibilityUi";

export function formatCouponMoney(cents) {
	if (cents == null || cents === "") return "—";
	const n = Number(cents);
	if (Number.isNaN(n)) return "—";
	return (n / 100).toLocaleString("es-MX", {
		style: "currency",
		currency: "MXN",
	});
}

/**
 * @returns {'applicable'|'below_minimum'|'balance_too_large'|'scheduled'|'expired'|'not_valid'|'none'}
 */
export function getCouponAvailabilityReason(coupon, cartTotalCents) {
	if (!coupon) return "none";
	if (coupon.reason) return coupon.reason;
	if (!isCouponWithinValidity(coupon)) {
		if (coupon.validity_status === "programado") return "scheduled";
		if (coupon.validity_status === "vencido") return "expired";
		return "not_valid";
	}
	if (!couponMeetsMinPurchase(coupon, cartTotalCents)) return "below_minimum";
	if (coupon.remaining_cents > cartTotalCents) return "balance_too_large";
	return "applicable";
}

export function getAmountMissingForMinimum(coupon, cartTotalCents) {
	if (coupon?.missing_for_minimum_cents != null) {
		return Math.max(0, Number(coupon.missing_for_minimum_cents));
	}
	if (!coupon?.min_purchase_cents) return 0;
	if (couponMeetsMinPurchase(coupon, cartTotalCents)) return 0;
	return Math.max(0, coupon.min_purchase_cents - cartTotalCents);
}

/** @alias getAmountMissingForMinimum */
export const getMissingForMinimum = getAmountMissingForMinimum;

export function isCouponApplicableForTotal(coupon, cartTotalCents) {
	if (coupon?.is_applicable != null) {
		return Boolean(coupon.is_applicable);
	}
	return isCouponApplicableForCheckout(coupon, cartTotalCents);
}

/**
 * Recomendación híbrida: vence pronto → mayor monto → menor id.
 */
export function getRecommendedCoupon(coupons, cartTotalCents) {
	const applicable = (coupons ?? []).filter((c) =>
		isCouponApplicableForTotal(c, cartTotalCents),
	);
	if (applicable.length === 0) return null;

	return [...applicable].sort((a, b) => {
		const aExpires = a.expires_at
			? new Date(a.expires_at).getTime()
			: Number.MAX_SAFE_INTEGER;
		const bExpires = b.expires_at
			? new Date(b.expires_at).getTime()
			: Number.MAX_SAFE_INTEGER;
		if (aExpires !== bExpires) return aExpires - bExpires;
		if (a.remaining_cents !== b.remaining_cents) {
			return b.remaining_cents - a.remaining_cents;
		}
		return a.id - b.id;
	})[0];
}

/** @deprecated Prefer getRecommendedCoupon (regla híbrida). */
export function getBestApplicableCoupon(coupons, cartTotalCents) {
	return getRecommendedCoupon(coupons, cartTotalCents);
}

export function normalizeBalanceCreditPresentation(
	balanceCreditPresentation,
	availableBalanceCoupons = [],
	cartTotalCents = 0,
	balanceCouponsCents = 0,
) {
	if (balanceCreditPresentation?.coupons?.length) {
		return balanceCreditPresentation;
	}

	const coupons = (availableBalanceCoupons ?? []).map((coupon) => {
		const reason = getCouponAvailabilityReason(coupon, cartTotalCents);
		const missing = getAmountMissingForMinimum(coupon, cartTotalCents);

		return {
			...coupon,
			formatted_remaining: formatCouponMoney(coupon.remaining_cents),
			is_applicable: reason === "applicable",
			is_recommended: false,
			reason,
			label: getCouponReasonLabel(reason),
			missing_for_minimum_cents: missing > 0 ? missing : null,
			formatted_missing_for_minimum:
				missing > 0 ? formatCouponMoney(missing) : null,
		};
	});

	const recommended = getRecommendedCoupon(coupons, cartTotalCents);
	if (recommended) {
		coupons.forEach((c) => {
			c.is_recommended = c.id === recommended.id;
		});
	}

	const applicable = coupons.filter((c) => c.is_applicable);
	const conditional = coupons.filter((c) =>
		["below_minimum", "balance_too_large"].includes(c.reason),
	);
	const totalBalance = coupons.reduce((sum, c) => sum + c.remaining_cents, 0);
	const applicableBalance = applicable.reduce(
		(sum, c) => sum + c.remaining_cents,
		0,
	);
	const conditionalBalance = conditional.reduce(
		(sum, c) => sum + c.remaining_cents,
		0,
	);

	return {
		total_balance_cents: totalBalance || balanceCouponsCents,
		applicable_balance_cents: applicableBalance,
		conditional_balance_cents: conditionalBalance,
		applicable_coupons_count: applicable.length,
		conditional_coupons_count: conditional.length,
		scheduled_coupons_count: 0,
		best_coupon: recommended,
		coupons,
		cartTotalCents,
	};
}

export function buildBalanceCreditSummary(
	availableBalanceCoupons,
	cartTotalCents,
	balanceCents = 0,
	balanceCreditPresentation = null,
) {
	const presentation = normalizeBalanceCreditPresentation(
		balanceCreditPresentation,
		availableBalanceCoupons,
		cartTotalCents,
		balanceCents,
	);

	const { coupons } = presentation;
	if (!coupons.length) {
		return { show: false };
	}

	const bestCoupon =
		presentation.best_coupon ??
		getRecommendedCoupon(coupons, cartTotalCents);
	const displayCoupon = bestCoupon ?? coupons[0];
	const primaryReason = getCouponAvailabilityReason(
		displayCoupon,
		cartTotalCents,
	);
	const isMulti = coupons.length > 1;

	return {
		show: true,
		isMulti,
		presentation,
		balanceCents: isMulti
			? presentation.applicable_balance_cents
			: displayCoupon.remaining_cents,
		totalBalanceCents: presentation.total_balance_cents,
		applicableBalanceCents: presentation.applicable_balance_cents,
		conditionalBalanceCents: presentation.conditional_balance_cents,
		applicableCount: presentation.applicable_coupons_count,
		conditionalCount: presentation.conditional_coupons_count,
		totalCredits: coupons.length,
		bestCoupon,
		displayCoupon,
		coupons,
		primaryReason,
		amountMissingForMinimum: getAmountMissingForMinimum(
			displayCoupon,
			cartTotalCents,
		),
		canApply: presentation.applicable_coupons_count > 0,
		status: getBalanceCreditStatus(primaryReason),
	};
}

export function getCouponReasonLabel(reason) {
	switch (reason) {
		case "applicable":
			return "Aplicable ahora";
		case "below_minimum":
			return "Requiere compra mínima";
		case "balance_too_large":
			return "Saldo mayor al total";
		case "scheduled":
			return "Disponible próximamente";
		default:
			return "No disponible";
	}
}

export function getMultiCreditCartHeadline(summary) {
	if (!summary?.isMulti) return null;

	const { presentation } = summary;
	if (presentation.applicable_coupons_count > 0) {
		return {
			title: "Tienes varios créditos a favor",
			applicableLine: `${formatCouponMoney(presentation.applicable_balance_cents)} aplicables en esta compra`,
			conditionalLine:
				presentation.conditional_coupons_count > 0
					? `${formatCouponMoney(presentation.conditional_balance_cents)} disponibles bajo condiciones`
					: null,
		};
	}

	return {
		title: "Tienes créditos a favor",
		applicableLine: "Ninguno aplica a esta compra",
		conditionalLine:
			presentation.conditional_coupons_count > 0
				? `${formatCouponMoney(presentation.conditional_balance_cents)} disponibles bajo condiciones`
				: null,
	};
}

export function getCouponListItemLines(coupon, cartTotalCents) {
	const lines = [];
	const reason = coupon.reason ?? getCouponAvailabilityReason(coupon, cartTotalCents);

	if (
		!coupon.expires_at &&
		!coupon.valid_from &&
		!coupon.min_purchase_cents &&
		reason === "applicable"
	) {
		lines.push({ key: "no_rules", text: "Sin vigencia y sin compra mínima." });
	}

	if (coupon.expires_at) {
		lines.push({
			key: "expires",
			text: `Vence el ${formatCouponDateLong(coupon.expires_at)}`,
		});
	} else if (reason !== "scheduled") {
		lines.push({ key: "no_expiry", text: "Sin vigencia" });
	}

	if (coupon.min_purchase_cents) {
		lines.push({
			key: "min",
			text: `Compra mínima: ${
				coupon.formatted_min_purchase ??
				formatCouponMoney(coupon.min_purchase_cents)
			}`,
		});
	} else {
		lines.push({ key: "no_min", text: "Sin compra mínima" });
	}

	if (reason === "below_minimum") {
		const missing = getAmountMissingForMinimum(coupon, cartTotalCents);
		if (missing > 0) {
			lines.push({
				key: "shortfall",
				text: `Te faltan ${formatCouponMoney(missing)} para usarlo.`,
				tone: "warning",
			});
		}
	}

	if (reason === "balance_too_large") {
		lines.push({
			key: "too_large",
			text: "Este crédito es mayor que el total de la compra. Podrás usarlo en una compra de mayor monto.",
			tone: "warning",
		});
	}

	if (reason === "applicable") {
		lines.push({ key: "ok", text: "Aplicable ahora" });
	}

	return lines;
}

/**
 * @returns {'applicable'|'minimum_not_met'|'balance_greater_than_total'|'scheduled'|'not_available'}
 */
export function getBalanceCreditStatus(primaryReason) {
	switch (primaryReason) {
		case "applicable":
			return "applicable";
		case "below_minimum":
			return "minimum_not_met";
		case "balance_too_large":
			return "balance_greater_than_total";
		case "scheduled":
			return "scheduled";
		default:
			return "not_available";
	}
}

export function getBalanceCreditStatusBadge(primaryReason, applied = false) {
	if (applied) {
		return { label: "Aplicado", color: "emerald" };
	}

	switch (primaryReason) {
		case "applicable":
			return { label: "Disponible", color: "emerald" };
		case "below_minimum":
			return { label: "Requiere mínimo", color: "amber" };
		case "balance_too_large":
			return { label: "No aplicable", color: "amber" };
		case "scheduled":
			return { label: "Disponible próximamente", color: "blue" };
		default:
			return { label: "No disponible", color: "zinc" };
	}
}

export function getBalanceCreditMessage(summary, applied = false) {
	if (!summary?.show) return "";

	if (summary.isMulti && !applied) {
		const headline = getMultiCreditCartHeadline(summary);
		if (headline?.applicableLine) {
			return headline.applicableLine;
		}
	}

	const { primaryReason, amountMissingForMinimum } = summary;

	if (applied) {
		return "Saldo aplicado a esta compra.";
	}

	switch (primaryReason) {
		case "applicable":
			return "Puedes usarlo en esta compra.";
		case "below_minimum":
			return amountMissingForMinimum > 0
				? `Te faltan ${formatCouponMoney(amountMissingForMinimum)} para poder usarlo.`
				: "Puedes usarlo en esta compra si cumples el monto mínimo.";
		case "balance_too_large":
			return "Este crédito es mayor que el total de la compra. Podrás usarlo en una compra de mayor monto.";
		case "scheduled":
			return "Tu crédito estará disponible próximamente.";
		default:
			return "Tienes créditos a favor, pero ninguno aplica a esta compra.";
	}
}

/**
 * @returns {'success'|'warning'|'info'|'neutral'|'applied'}
 */
export function getBalanceCreditMessageTone(summary, applied = false) {
	if (!summary?.show) return "neutral";
	if (applied) return "applied";

	if (summary.isMulti && summary.applicableCount === 0) {
		return "warning";
	}

	switch (summary.primaryReason) {
		case "applicable":
			return "success";
		case "below_minimum":
		case "balance_too_large":
			return "warning";
		case "scheduled":
			return "info";
		default:
			return "neutral";
	}
}

export function canApplyBalanceCredit(summary, applied = false) {
	if (!summary?.show || applied) return false;
	return summary.canApply === true;
}

/**
 * Filas para el acordeón de términos (solo presentación).
 */
export function buildBalanceCreditTermsRows(
	coupon,
	cartTotalCents,
	primaryReason,
	applied = false,
) {
	if (!coupon) return [];

	const rows = [];
	const shortfall = getAmountMissingForMinimum(coupon, cartTotalCents);
	const reason = applied ? "applicable" : primaryReason;

	if (coupon.expires_at) {
		rows.push({
			key: "expires_at",
			label: "Disponible hasta:",
			value: formatCouponDate(coupon.expires_at) ?? "—",
		});
	} else if (reason !== "scheduled") {
		rows.push({
			key: "no_expiry",
			label: "Vigencia:",
			value: "Sin fecha de vencimiento",
		});
	}

	if (coupon.valid_from && reason === "scheduled") {
		rows.push({
			key: "valid_from",
			label: "Disponible desde:",
			value: formatCouponDateLong(coupon.valid_from) ?? "—",
			tone: "info",
		});
	}

	if (coupon.min_purchase_cents) {
		rows.push({
			key: "min_purchase",
			label: "Compra mínima requerida:",
			value:
				coupon.formatted_min_purchase ??
				formatCouponMoney(coupon.min_purchase_cents),
		});
	} else {
		rows.push({
			key: "no_min",
			label: "Compra mínima:",
			value: "Sin compra mínima",
		});
	}

	if (reason === "below_minimum" && shortfall > 0) {
		rows.push({
			key: "shortfall",
			label: "Te faltan:",
			value: `${formatCouponMoney(shortfall)} para usarlo`,
			tone: "warning",
		});
	}

	if (reason === "applicable") {
		rows.push({
			key: "applicable",
			label: "Estado:",
			value: "Puedes usarlo en esta compra.",
			tone: "success",
		});
	}

	if (reason === "balance_too_large") {
		rows.push({
			key: "too_large",
			label: "Restricción:",
			value: "El saldo es mayor que el total de esta compra.",
			tone: "warning",
		});
	}

	rows.push({
		key: "conditions",
		label: "Condiciones:",
		value: "Aplica a compras que cumplan las condiciones del laboratorio.",
	});

	return rows;
}

export function formatCouponDate(iso) {
	if (!iso) return null;
	return new Date(iso).toLocaleString("es-MX", {
		dateStyle: "short",
		timeStyle: "short",
	});
}

export function formatCouponDateLong(iso) {
	if (!iso) return null;
	return new Date(iso).toLocaleString("es-MX", {
		dateStyle: "long",
		timeStyle: "short",
	});
}
