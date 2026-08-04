import { isCouponEligibilityFormComplete } from "@/lib/couponEligibilityUi";

export const CREDIT_TYPE_META = {
	balance: {
		title: "Configurar saldo a favor",
		help: "Asigna un saldo directo al usuario. No requiere vigencia, monto mínimo ni código promocional.",
		amountLabel: "Monto del saldo (MXN)",
		showConcept: true,
		showPublicCode: false,
		showRulesTab: false,
		showAssignmentTab: true,
	},
	coupon: {
		title: "Configurar cupón",
		help: "Crea un cupón con reglas comerciales como vigencia, expiración y compra mínima.",
		amountLabel: "Monto del cupón (MXN)",
		showConcept: true,
		showPublicCode: false,
		showRulesTab: true,
		showAssignmentTab: true,
	},
	shared_promo: {
		title: "Configurar código promocional",
		help: "Crea un código que los usuarios podrán ingresar en checkout. Ideal para eventos, influencers o campañas compartidas.",
		amountLabel: "Monto del descuento (MXN)",
		showConcept: false,
		showPublicCode: true,
		showRulesTab: true,
		showAssignmentTab: false,
	},
};

export function creditTypeMeta(creditType) {
	return CREDIT_TYPE_META[creditType] ?? CREDIT_TYPE_META.balance;
}

export function visibleTabsForCreditType(creditType) {
	const meta = creditTypeMeta(creditType);
	const tabs = [{ id: "credit", label: "Datos del beneficio" }];
	if (meta.showRulesTab) {
		tabs.push({ id: "rules", label: creditType === "shared_promo" ? "Vigencia y uso" : "Reglas de uso" });
	}
	if (meta.showAssignmentTab) {
		tabs.push({ id: "assignment", label: "Beneficiarios" });
	}
	tabs.push({ id: "summary", label: "Resumen" });
	return tabs;
}

export function defaultFieldsForCreditType(creditType) {
	if (creditType === "balance") {
		return {
			validity_mode: "open",
			minimum_purchase_mode: "none",
			valid_from: "",
			expires_at: "",
			min_purchase_mxn: "",
			promo_code: "",
			auto_generate_promo_code: false,
			code: "",
			assignment_mode: undefined,
		};
	}
	if (creditType === "shared_promo") {
		return {
			assignment_mode: "none",
			max_redemptions: "100",
			max_uses_per_user: "1",
			auto_generate_promo_code: false,
			promo_code: "",
			coupon_concept_id: "",
			concept_other: "",
			code: "",
		};
	}
	return {
		promo_code: "",
		auto_generate_promo_code: false,
	};
}

export function isCreditStepComplete(data, { amountOk, conceptStepOk }) {
	const type = data.credit_type ?? "balance";
	if (!amountOk) return false;

	if (type === "shared_promo") {
		const hasCode =
			data.auto_generate_promo_code || String(data.promo_code ?? "").trim() !== "";
		const maxR = parseInt(String(data.max_redemptions), 10);
		const maxU = parseInt(String(data.max_uses_per_user), 10);
		return hasCode && maxR > 0 && maxU > 0;
	}

	if (!conceptStepOk) return false;
	return true;
}

export function isRulesStepComplete(data) {
	const type = data.credit_type ?? "balance";
	if (type === "balance") return true;
	return isCouponEligibilityFormComplete(data);
}

export function buildShareablePreview(data, formatMxn) {
	const code = String(data.promo_code ?? "").trim().toUpperCase();
	if (!code) return "";
	const amount = formatMxn(parseFloat(String(data.amount_mxn).replace(",", "")) || 0);
	let msg = `Usa el código ${code} en tu checkout de Famedic para obtener ${amount} de descuento.`;
	if (data.validity_mode === "configured" && data.expires_at) {
		try {
			const d = new Date(data.expires_at);
			if (!Number.isNaN(d.getTime())) {
				msg += ` Vigente hasta ${d.toLocaleDateString("es-MX")}.`;
			}
		} catch {
			// ignore
		}
	}
	return msg;
}
