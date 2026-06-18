import { useState } from "react";
import { GiftIcon, ChevronDownIcon } from "@heroicons/react/24/outline";
import BalanceCreditRules from "@/Components/Coupons/BalanceCreditRules";
import BalanceCreditStatusBadge from "@/Components/Coupons/BalanceCreditStatusBadge";
import BalanceCreditApplyButton from "@/Components/Coupons/BalanceCreditApplyButton";
import {
	buildBalanceCreditSummary,
	formatCouponMoney,
	getBalanceCreditMessage,
	getBalanceCreditMessageTone,
	canApplyBalanceCredit,
} from "@/lib/couponPatientUi";

const messageToneClasses = {
	success:
		"text-emerald-700 dark:text-emerald-300",
	warning:
		"text-amber-700 dark:text-amber-300",
	info:
		"text-sky-700 dark:text-sky-300",
	applied:
		"text-violet-700 dark:text-violet-300",
	neutral:
		"text-zinc-600 dark:text-zinc-400",
};

export default function BalanceCreditCard({
	balanceCouponsCents = 0,
	availableBalanceCoupons = [],
	cartTotalCents = 0,
	variant = "checkout",
	defaultExpanded = false,
	selectedCouponId = null,
	onApply,
	onClear,
}) {
	const [expanded, setExpanded] = useState(defaultExpanded);

	const summary = buildBalanceCreditSummary(
		availableBalanceCoupons,
		cartTotalCents,
		balanceCouponsCents,
	);

	if (!summary.show) return null;

	const {
		displayCoupon,
		bestCoupon,
		primaryReason,
		balanceCents,
		amountMissingForMinimum,
	} = summary;

	const applied =
		selectedCouponId != null &&
		availableBalanceCoupons.some((c) => c.id === selectedCouponId);
	const appliedCoupon =
		availableBalanceCoupons.find((c) => c.id === selectedCouponId) ?? null;
	const couponForRules = appliedCoupon ?? displayCoupon;
	const rulesReason = applied ? "applicable" : primaryReason;
	const compactMessage = getBalanceCreditMessage(summary, applied);
	const messageTone = getBalanceCreditMessageTone(summary, applied);
	const showApplyActions = variant === "checkout" && (onApply || onClear);
	const isCart = variant === "cart";

	const handleApply = () => {
		if (!canApplyBalanceCredit(summary, applied) || !bestCoupon) return;
		onApply?.(bestCoupon.id);
	};

	return (
		<div
			className={[
				"min-w-0 overflow-hidden rounded-lg border border-violet-100/90 bg-violet-50/35 px-4 py-3.5 shadow-sm",
				"dark:border-violet-900/35 dark:bg-violet-950/15",
				isCart ? "-mx-1" : "",
			].join(" ")}
		>
			<div className="flex gap-3">
				<div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-violet-100 text-violet-700 dark:bg-violet-900/45 dark:text-violet-300">
					<GiftIcon className="size-5" aria-hidden="true" />
				</div>

				<div className="min-w-0 flex-1 space-y-2">
					<div className="flex items-start justify-between gap-2">
						<h3 className="text-sm font-semibold leading-snug text-zinc-900 dark:text-zinc-100">
							Saldo a favor disponible
						</h3>
						<BalanceCreditStatusBadge
							primaryReason={rulesReason}
							applied={applied}
						/>
					</div>

					<p className="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
						{formatCouponMoney(balanceCents)}
					</p>

					<p
						className={[
							"text-sm leading-snug",
							messageToneClasses[messageTone] ?? messageToneClasses.neutral,
						].join(" ")}
					>
						{rulesReason === "below_minimum" &&
						amountMissingForMinimum > 0 &&
						!applied ? (
							<>
								Te faltan{" "}
								<span className="font-semibold">
									{formatCouponMoney(amountMissingForMinimum)}
								</span>{" "}
								para poder usarlo.
							</>
						) : (
							compactMessage
						)}
					</p>

					<div className="pt-0.5">
						<button
							type="button"
							className="inline-flex w-full items-center justify-center gap-1 text-xs font-medium text-violet-700 hover:text-violet-800 dark:text-violet-300 dark:hover:text-violet-200 sm:justify-start sm:text-sm"
							onClick={() => setExpanded((v) => !v)}
							aria-expanded={expanded}
						>
							{expanded ? "Ocultar términos de uso" : "Ver términos de uso"}
							<ChevronDownIcon
								className={[
									"size-3.5 transition-transform sm:size-4",
									expanded ? "rotate-180" : "",
								].join(" ")}
								aria-hidden="true"
							/>
						</button>

						<BalanceCreditRules
							coupon={couponForRules}
							cartTotalCents={cartTotalCents}
							primaryReason={rulesReason}
							applied={applied}
							expanded={expanded}
							className="mt-2"
						/>
					</div>

					{showApplyActions && (
						<div className="pt-1">
							<BalanceCreditApplyButton
								applied={applied}
								canApply={canApplyBalanceCredit(summary, applied)}
								onApply={handleApply}
								onClear={onClear}
							/>
						</div>
					)}
				</div>
			</div>
		</div>
	);
}
