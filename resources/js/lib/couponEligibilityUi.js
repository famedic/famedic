export function toDatetimeLocalValue(iso) {
	if (!iso) return "";
	const d = new Date(iso);
	const pad = (n) => String(n).padStart(2, "0");
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function parseMinPurchaseMxnToCents(value) {
	const trimmed = String(value ?? "").trim();
	if (trimmed === "") return null;
	const parsed = parseFloat(trimmed.replace(",", ""));
	if (Number.isNaN(parsed) || parsed < 0) return null;
	return Math.round(parsed * 100);
}

export function inferValidityMode(validFrom, expiresAt) {
	return String(validFrom ?? "").trim() || String(expiresAt ?? "").trim()
		? "configured"
		: "open";
}

export function inferMinimumPurchaseMode(minPurchaseMxn) {
	return String(minPurchaseMxn ?? "").trim() !== "" ? "required" : "none";
}

export function isCouponEligibilityFormComplete(data) {
	if (data.validity_mode === "configured") {
		const hasFrom = String(data.valid_from ?? "").trim() !== "";
		const hasExpires = String(data.expires_at ?? "").trim() !== "";
		if (!hasFrom && !hasExpires) return false;
		if (hasFrom && hasExpires) {
			const from = new Date(data.valid_from).getTime();
			const expires = new Date(data.expires_at).getTime();
			if (!Number.isNaN(from) && !Number.isNaN(expires) && expires < from) {
				return false;
			}
		}
	}

	if (data.minimum_purchase_mode === "required") {
		const cents = parseMinPurchaseMxnToCents(data.min_purchase_mxn);
		if (cents === null || cents <= 0) return false;
	}

	return true;
}

/** Cupón con vigencia y compra mínima — requisito para asignación masiva a toda la plataforma. */
export function hasPlatformWideCouponRestrictionsFromForm(data) {
	const hasValidity =
		data?.validity_mode === "configured" &&
		(String(data?.valid_from ?? "").trim() !== "" ||
			String(data?.expires_at ?? "").trim() !== "");
	const minCents = parseMinPurchaseMxnToCents(data?.min_purchase_mxn);
	const hasMinPurchase =
		data?.minimum_purchase_mode === "required" && minCents !== null && minCents > 0;

	return hasValidity && hasMinPurchase;
}

export function hasPlatformWideCouponRestrictionsFromCoupon(coupon) {
	if (!coupon) return false;
	const hasValidity =
		String(coupon?.valid_from ?? "").trim() !== "" ||
		String(coupon?.expires_at ?? "").trim() !== "";
	const minCents = Number(coupon?.min_purchase_cents ?? 0);

	return hasValidity && minCents > 0;
}

export function appendCouponEligibilityToPayload(out, d) {
	out.validity_mode = d.validity_mode;
	out.minimum_purchase_mode = d.minimum_purchase_mode;

	if (d.validity_mode === "open") {
		out.valid_from = null;
		out.expires_at = null;
	} else {
		out.valid_from = d.valid_from?.trim() ? d.valid_from : null;
		out.expires_at = d.expires_at?.trim() ? d.expires_at : null;
	}

	if (d.minimum_purchase_mode === "none") {
		out.min_purchase_cents = null;
	} else {
		out.min_purchase_cents = parseMinPurchaseMxnToCents(d.min_purchase_mxn);
	}

	return out;
}

export function couponValiditySummary(c) {
	const status = c.validity_status ?? "sin_vigencia";
	switch (status) {
		case "programado":
			return { label: "Programado", color: "amber" };
		case "vigente":
			return c.expires_at
				? {
						label: `Vigente hasta ${new Date(c.expires_at).toLocaleString("es-MX", { dateStyle: "short", timeStyle: "short" })}`,
						color: "emerald",
					}
				: { label: "Vigente", color: "emerald" };
		case "vencido":
			return { label: "Vencido", color: "red" };
		default:
			return { label: "Sin vigencia", color: "zinc" };
	}
}

export function isCouponWithinValidity(c) {
	const now = Date.now();
	if (c.valid_from && new Date(c.valid_from).getTime() > now) return false;
	if (c.expires_at && new Date(c.expires_at).getTime() < now) return false;
	return true;
}

export function couponMeetsMinPurchase(c, totalCents) {
	if (!c.min_purchase_cents) return true;
	return totalCents >= c.min_purchase_cents;
}

export function couponCreditType(c) {
	return c?.type ?? c?.credit_type ?? "balance";
}

export function isCouponCreditType(c) {
	return couponCreditType(c) === "coupon";
}

export function isBalanceCreditType(c) {
	return couponCreditType(c) === "balance";
}

export function couponDiscountCents(c, totalCents) {
	if (!c || c.remaining_cents <= 0) return 0;
	if (!isCouponWithinValidity(c) || !couponMeetsMinPurchase(c, totalCents)) {
		return 0;
	}
	if (isCouponCreditType(c)) {
		return Math.min(c.remaining_cents, totalCents);
	}
	if (c.remaining_cents > totalCents) return 0;
	return c.remaining_cents;
}

export function isCouponApplicableForCheckout(c, totalCents) {
	if (!c || c.remaining_cents <= 0) return false;
	if (!isCouponWithinValidity(c) || !couponMeetsMinPurchase(c, totalCents)) {
		return false;
	}
	if (isCouponCreditType(c)) {
		return true;
	}
	return c.remaining_cents <= totalCents;
}

export function couponCreditTypeLabel(c) {
	if (c?.type_label) return c.type_label;
	return isCouponCreditType(c) ? "Cupón" : "Saldo a favor";
}

/**
 * @param {string|null|undefined} expiresAt ISO date string
 * @returns {{ expired: true } | { expired: false, days: number, hours: number } | null}
 */
export function getCreditExpiryCountdown(expiresAt) {
	if (!expiresAt) return null;
	const end = new Date(expiresAt).getTime();
	if (Number.isNaN(end)) return null;
	const diff = end - Date.now();
	if (diff <= 0) return { expired: true };
	const totalHours = Math.floor(diff / (1000 * 60 * 60));
	const days = Math.floor(totalHours / 24);
	const hours = totalHours % 24;
	return { expired: false, days, hours };
}

export function formatCreditExpiryCountdown(countdown) {
	if (!countdown || countdown.expired) return null;
	const segments = [];
	if (countdown.days > 0) {
		segments.push(`${countdown.days} ${countdown.days === 1 ? "día" : "días"}`);
	}
	segments.push(`${countdown.hours} ${countdown.hours === 1 ? "hora" : "horas"}`);
	return segments.join(" y ");
}
