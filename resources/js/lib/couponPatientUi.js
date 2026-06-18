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
	};
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
