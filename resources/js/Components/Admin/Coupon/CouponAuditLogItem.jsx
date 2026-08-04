import { useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import CouponStatusBadge from "@/Components/Admin/Coupon/CouponStatusBadge";
import { logActionMeta } from "@/lib/couponAdminUi";
import { creatorDisplayName, formatMxnFromCents, formatShortDateTime } from "@/lib/couponFormat";
import { ChevronDownIcon, ChevronRightIcon } from "@heroicons/react/16/solid";

function reversalReasonLabel(reason) {
	if (!reason) return "—";
	if (reason === "laboratory_purchase_cancelled") {
		return "Cancelación de pedido de laboratorio";
	}
	return String(reason).replaceAll("_", " ");
}

function renderHumanDetail(row) {
	const context = row.context ?? {};

	if (row.action === "reverse_coupon_application") {
		return (
			<dl className="grid gap-2 text-sm sm:grid-cols-2">
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Pedido</dt>
					<dd className="font-medium text-zinc-900 dark:text-zinc-100">
						{context.purchase_type ?? "—"} #{context.purchase_id ?? "—"}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Cupón</dt>
					<dd className="font-medium text-zinc-900 dark:text-zinc-100">
						#{context.coupon_id ?? "—"}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Monto restaurado</dt>
					<dd className="font-medium text-zinc-900 dark:text-zinc-100">
						{formatMxnFromCents(context.amount_restored_cents)}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Motivo</dt>
					<dd className="font-medium text-zinc-900 dark:text-zinc-100">
						{reversalReasonLabel(context.reason)}
					</dd>
				</div>
			</dl>
		);
	}

	if (context.email || context.beneficiary_email) {
		return (
			<dl className="grid gap-2 text-sm sm:grid-cols-2">
				{(context.email || context.beneficiary_email) && (
					<div>
						<dt className="text-zinc-500 dark:text-zinc-400">Correo</dt>
						<dd className="break-all font-medium text-zinc-900 dark:text-zinc-100">
							{context.email ?? context.beneficiary_email}
						</dd>
					</div>
				)}
				{context.parent_coupon_id != null && (
					<div>
						<dt className="text-zinc-500 dark:text-zinc-400">Cupón maestro</dt>
						<dd className="font-medium text-zinc-900 dark:text-zinc-100">
							#{context.parent_coupon_id}
						</dd>
					</div>
				)}
				{context.amount_restored_cents != null && (
					<div>
						<dt className="text-zinc-500 dark:text-zinc-400">Monto</dt>
						<dd className="font-medium text-zinc-900 dark:text-zinc-100">
							{formatMxnFromCents(context.amount_restored_cents)}
						</dd>
					</div>
				)}
			</dl>
		);
	}

	return (
		<pre className="max-w-3xl overflow-auto whitespace-pre-wrap rounded-lg bg-zinc-100 p-3 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
			{JSON.stringify(context, null, 2)}
		</pre>
	);
}

function statusBadgeColor(status) {
	if (status === "completed" || status === "approved") return "emerald";
	if (status === "pending") return "amber";
	if (status === "rejected" || status === "failed") return "red";
	return "zinc";
}

export default function CouponAuditLogItem({ row, defaultExpanded = false }) {
	const [expanded, setExpanded] = useState(defaultExpanded);
	const actionMeta = logActionMeta(row.action);

	return (
		<div className="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
			<button
				type="button"
				className="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
				onClick={() => setExpanded((v) => !v)}
				aria-expanded={expanded}
			>
				{expanded ? (
					<ChevronDownIcon className="mt-0.5 size-4 shrink-0 text-zinc-400" />
				) : (
					<ChevronRightIcon className="mt-0.5 size-4 shrink-0 text-zinc-400" />
				)}
				<div className="min-w-0 flex-1">
					<div className="flex flex-wrap items-center gap-2">
						<CouponStatusBadge label={actionMeta.label} color={actionMeta.color} />
						<Badge color={statusBadgeColor(row.status)}>{row.status ?? "—"}</Badge>
						<span className="text-xs text-zinc-500 dark:text-zinc-400">
							{formatShortDateTime(row.created_at)}
						</span>
					</div>
					<p className="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
						{creatorDisplayName(row.actor_user ?? row.actorUser)}
						{row.coupon_id ? (
							<span className="text-zinc-500 dark:text-zinc-400">
								{" "}
								· Cupón #{row.coupon_id}
							</span>
						) : null}
					</p>
				</div>
			</button>
			{expanded ? (
				<div className="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
					{renderHumanDetail(row)}
				</div>
			) : null}
		</div>
	);
}
