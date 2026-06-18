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
	if (!coupon?.min_purchase_cents) return 0;
	if (couponMeetsMinPurchase(coupon, cartTotalCents)) return 0;
	return Math.max(0, coupon.min_purchase_cents - cartTotalCents);
}

/** @alias getAmountMissingForMinimum */
export const getMissingForMinimum = getAmountMissingForMinimum;

export function isCouponApplicableForTotal(coupon, cartTotalCents) {
	return isCouponApplicableForCheckout(coupon, cartTotalCents);
}

export function getBestApplicableCoupon(coupons, cartTotalCents) {
	const applicable = (coupons ?? []).filter((c) =>
		isCouponApplicableForCheckout(c, cartTotalCents),
	);
	if (applicable.length === 0) return null;
	return [...applicable].sort((a, b) => b.remaining_cents - a.remaining_cents)[0];
}

export function buildBalanceCreditSummary(
	coupons,
	cartTotalCents,
	balanceCents = 0,
) {
	if (!balanceCents || balanceCents <= 0 || !(coupons?.length > 0)) {
		return { show: false };
	}

	const bestCoupon = getBestApplicableCoupon(coupons, cartTotalCents);
	const displayCoupon = bestCoupon ?? coupons[0];
	const primaryReason = getCouponAvailabilityReason(displayCoupon, cartTotalCents);
	const applicableCount = coupons.filter((c) =>
		isCouponApplicableForCheckout(c, cartTotalCents),
	).length;

	return {
		show: true,
		balanceCents,
		applicableCount,
		totalCredits: coupons.length,
		bestCoupon,
		displayCoupon,
		primaryReason,
		amountMissingForMinimum: getAmountMissingForMinimum(
			displayCoupon,
			cartTotalCents,
		),
		canApply: applicableCount > 0,
		status: getBalanceCreditStatus(primaryReason),
	};
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
			return "Tu saldo es mayor que el total de esta compra. Podrás usarlo en una compra de mayor monto.";
		case "scheduled":
			return "Tu crédito estará disponible próximamente.";
		default:
			return "Puedes usarlo en esta compra si cumples el monto mínimo.";
	}
}

/**
 * @returns {'success'|'warning'|'info'|'neutral'|'applied'}
 */
export function getBalanceCreditMessageTone(summary, applied = false) {
	if (!summary?.show) return "neutral";
	if (applied) return "applied";

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
 * @returns {Array<{key: string, label: string, value: string, tone?: 'default'|'warning'|'success'|'info'}>}
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
		tone: "info",
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
