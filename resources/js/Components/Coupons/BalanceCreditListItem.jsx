import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import BalanceCreditStatusBadge from "@/Components/Coupons/BalanceCreditStatusBadge";
import {
	formatCouponDate,
	formatCouponMoney,
	getCouponListItemLines,
} from "@/lib/couponPatientUi";

export default function BalanceCreditListItem({
	coupon,
	cartTotalCents = 0,
	selectedCouponId = null,
	onApply,
	onClear,
	showActions = false,
	compact = false,
}) {
	const applied = selectedCouponId != null && coupon.id === selectedCouponId;
	const lines = getCouponListItemLines(coupon, cartTotalCents);
	const canApply = coupon.is_applicable && !applied;

	return (
		<div
			className={[
				"rounded-lg border px-3 py-2.5",
				applied
					? "border-violet-300 bg-violet-50/80 dark:border-violet-700/50 dark:bg-violet-950/30"
					: "border-violet-100/90 bg-white/60 dark:border-violet-900/35 dark:bg-zinc-900/20",
			].join(" ")}
		>
			<div className="flex items-start justify-between gap-2">
				<div className="min-w-0 flex-1 space-y-1">
					<div className="flex flex-wrap items-center gap-2">
						<p className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
							{coupon.formatted_remaining ?? formatCouponMoney(coupon.remaining_cents)}
						</p>
						<BalanceCreditStatusBadge
							primaryReason={applied ? "applicable" : coupon.reason}
							applied={applied}
						/>
						{coupon.is_recommended && !applied && (
							<Badge color="violet">Recomendado</Badge>
						)}
					</div>
					<p className="text-sm text-zinc-700 dark:text-zinc-300">{coupon.label}</p>
					{lines.map((line) => (
						<p
							key={line.key}
							className={[
								"text-xs leading-snug",
								line.tone === "warning"
									? "text-amber-700 dark:text-amber-300"
									: "text-zinc-600 dark:text-zinc-400",
							].join(" ")}
						>
							{line.text}
						</p>
					))}
				</div>

				{showActions && (
					<div className="shrink-0">
						{applied ? (
							<Button type="button" plain className="text-sm" onClick={onClear}>
								Quitar
							</Button>
						) : canApply ? (
							<Button
								type="button"
								color="famedic"
								className="text-sm"
								onClick={() => onApply?.(coupon.id)}
							>
								Aplicar
							</Button>
						) : (
							<span className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
								No aplicable
							</span>
						)}
					</div>
				)}
			</div>

			{compact && coupon.expires_at && (
				<p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
					Vence el {formatCouponDate(coupon.expires_at)}
				</p>
			)}
		</div>
	);
}
