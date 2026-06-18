import { GiftIcon } from "@heroicons/react/24/outline";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import BalanceCreditRules from "@/Components/Coupons/BalanceCreditRules";
import BalanceCreditApplyButton from "@/Components/Coupons/BalanceCreditApplyButton";
import {
	buildBalanceCreditSummary,
	formatCouponMoney,
} from "@/lib/couponPatientUi";

export default function BalanceCreditCard({
	balanceCouponsCents = 0,
	availableBalanceCoupons = [],
	cartTotalCents = 0,
	selectedCouponId = null,
	onApply,
	onClear,
}) {
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
		applicableCount,
		totalCredits,
		canApply,
	} = summary;

	const applied =
		selectedCouponId != null &&
		availableBalanceCoupons.some((c) => c.id === selectedCouponId);
	const appliedCoupon =
		availableBalanceCoupons.find((c) => c.id === selectedCouponId) ?? null;
	const couponForRules = appliedCoupon ?? displayCoupon;

	const handleApply = () => {
		if (!canApply || !bestCoupon) return;
		onApply?.(bestCoupon.id);
	};

	return (
		<div className="rounded-xl border-2 border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-teal-50 p-4 shadow-sm dark:border-emerald-700/50 dark:from-emerald-950/30 dark:via-zinc-900 dark:to-teal-950/20">
			<div className="flex gap-3">
				<div className="flex size-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300">
					<GiftIcon className="size-6" aria-hidden="true" />
				</div>
				<div className="min-w-0 flex-1 space-y-3">
					<div>
						<Subheading className="text-emerald-900 dark:text-emerald-100">
							Saldo a favor
						</Subheading>
						<Text className="mt-1 text-sm font-medium text-emerald-800 dark:text-emerald-200">
							Tienes {formatCouponMoney(balanceCents)} de saldo a favor
							{totalCredits > 1 && (
								<span className="font-normal text-zinc-600 dark:text-zinc-400">
									{" "}
									({applicableCount > 0
										? `${applicableCount} aplicable${applicableCount !== 1 ? "s" : ""} de ${totalCredits}`
										: `${totalCredits} créditos`})
								</span>
							)}
						</Text>
						{applied && appliedCoupon && (
							<Text className="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
								Aplicado: {formatCouponMoney(appliedCoupon.remaining_cents)} de
								descuento
							</Text>
						)}
					</div>

					<BalanceCreditRules
						coupon={couponForRules}
						cartTotalCents={cartTotalCents}
						primaryReason={applied ? "applicable" : primaryReason}
						selectedCoupon={appliedCoupon}
					/>

					{(onApply || onClear) && (
						<BalanceCreditApplyButton
							applied={applied}
							canApply={canApply && !applied}
							onApply={handleApply}
							onClear={onClear}
							applyLabel="Aplicar saldo a favor"
							clearLabel="Quitar saldo aplicado"
						/>
					)}
				</div>
			</div>
		</div>
	);
}
