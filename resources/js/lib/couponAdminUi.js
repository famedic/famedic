import { couponValiditySummary } from "@/lib/couponEligibilityUi";

export function couponUsageSummary(c) {
	if (c.approval_status === "pending_authorization") {
		return { label: "Pendiente autorización", color: "purple" };
	}
	const direct = c.coupon_users ?? c.couponUsers ?? [];
	const childCount = c.child_coupons_count ?? 0;
	if (direct.length === 0 && childCount === 0) {
		return { label: "Sin asignar", color: "zinc" };
	}
	if (childCount > 0) {
		return { label: `Campaña (${childCount})`, color: "cyan" };
	}
	const pending = direct.filter((a) => !a.used_at);
	const used = direct.filter((a) => a.used_at);
	if (pending.length > 0 && used.length === 0) {
		return { label: "Pendiente de usar", color: "amber" };
	}
	if (used.length > 0 && pending.length === 0) {
		return { label: "Usado", color: "blue" };
	}
	return { label: "Mixto", color: "orange" };
}

export function couponActiveBadge(c) {
	if (!c.is_active) return { label: "Inactivo", color: "zinc" };
	return { label: "Activo", color: "emerald" };
}

export function couponValidityBadge(c) {
	return couponValiditySummary(c);
}

export function beneficiaryStatusMeta(row) {
	if (row.is_pending_user || row.status === "pending_user") {
		return { label: "Pendiente de registro", color: "amber" };
	}
	if (row.transaction?.is_reversed) {
		return { label: "Revertido", color: "orange" };
	}
	if (row.used_at || row.transaction) {
		return { label: "Usado", color: "blue" };
	}
	if (row.claimed_at) {
		return { label: "Reclamado", color: "emerald" };
	}
	if (row.child_coupon_id || row.status === "assigned") {
		return { label: "Asignado", color: "emerald" };
	}
	return { label: "Pendiente", color: "zinc" };
}

export const LOG_ACTION_META = {
	reverse_coupon_application: { label: "Reverso de saldo", color: "orange", group: "reverso" },
	assign_coupon: { label: "Asignación", color: "emerald", group: "asignación" },
	coupon_beneficiaries_previewed: { label: "Preview beneficiarios", color: "zinc", group: "asignación" },
	coupon_beneficiaries_confirmed: { label: "Confirmación beneficiarios", color: "emerald", group: "asignación" },
	coupon_beneficiary_assigned: { label: "Beneficiario asignado", color: "emerald", group: "asignación" },
	coupon_beneficiary_pending_user: { label: "Pendiente de registro", color: "amber", group: "pendiente" },
	coupon_beneficiary_linked: { label: "Vinculación al registrarse", color: "cyan", group: "vinculación" },
	coupon_beneficiary_link_skipped: { label: "Vinculación omitida", color: "zinc", group: "vinculación" },
	coupon_beneficiary_invitation_sent: { label: "Invitación enviada", color: "sky", group: "invitación" },
	coupon_beneficiary_invitation_resent: { label: "Invitación reenviada", color: "sky", group: "invitación" },
	coupon_beneficiary_invitation_failed: { label: "Error de invitación", color: "red", group: "invitación" },
	coupon_beneficiary_activation_notified: { label: "Activación notificada", color: "emerald", group: "activación" },
	coupon_beneficiary_activation_notify_failed: { label: "Error de activación", color: "red", group: "activación" },
	approval_request_created: { label: "Solicitud de aprobación", color: "purple", group: "aprobación" },
	update_coupon_settings: { label: "Configuración", color: "zinc", group: "configuración" },
};

export function logActionMeta(action) {
	return LOG_ACTION_META[action] ?? { label: action ?? "—", color: "zinc", group: "otro" };
}

export function computeIndexMetrics(couponItems) {
	const items = couponItems ?? [];
	let active = 0;
	let pendingAuth = 0;
	let expired = 0;
	let scheduled = 0;
	let totalAssigned = 0;

	for (const c of items) {
		if (c.is_active && c.approval_status === "active") active += 1;
		if (c.approval_status === "pending_authorization") pendingAuth += 1;
		if (c.validity_status === "vencido") expired += 1;
		if (c.validity_status === "programado") scheduled += 1;
		totalAssigned += c.child_coupons_count ?? 0;
	}

	return {
		total: items.length,
		active,
		pendingAuth,
		expired,
		scheduled,
		totalAssigned,
	};
}
