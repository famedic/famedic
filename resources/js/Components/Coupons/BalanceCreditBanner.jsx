import { Link } from "@inertiajs/react";
import { GiftIcon } from "@heroicons/react/24/outline";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import BalanceCreditRules from "@/Components/Coupons/BalanceCreditRules";
import {
	buildBalanceCreditSummary,
	formatCouponMoney,
} from "@/lib/couponPatientUi";

export default function BalanceCreditBanner({
	balanceCouponsCents = 0,
	availableBalanceCoupons = [],
	cartTotalCents = 0,
	checkoutUrl,
	onCheckoutClick,
}) {
	const summary = buildBalanceCreditSummary(
		availableBalanceCoupons,
		cartTotalCents,
		balanceCouponsCents,
	);

	if (!summary.show) return null;

	const { displayCoupon, primaryReason, balanceCents } = summary;

	const handleCheckout = (e) => {
		if (onCheckoutClick) {
			e.preventDefault();
			onCheckoutClick(e);
		}
	};

	return (
		<div className="rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-teal-50 p-4 shadow-sm dark:border-emerald-800/60 dark:from-emerald-950/40 dark:to-teal-950/30">
			<div className="flex gap-3">
				<div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">
					<GiftIcon className="size-5" aria-hidden="true" />
				</div>
				<div className="min-w-0 flex-1 space-y-2">
					<div>
						<Subheading className="text-emerald-900 dark:text-emerald-100">
							Tienes saldo a favor disponible
						</Subheading>
						<Text className="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
							{primaryReason === "applicable" ? (
								<>
									Puedes usar hasta{" "}
									<strong>{formatCouponMoney(balanceCents)}</strong> en tu
									compra al continuar al checkout.
								</>
							) : (
								<>
									Tienes{" "}
									<strong>{formatCouponMoney(balanceCents)}</strong> de saldo a
									favor. Revisa las condiciones para usarlo en esta compra.
								</>
							)}
						</Text>
					</div>

					<BalanceCreditRules
						coupon={displayCoupon}
						cartTotalCents={cartTotalCents}
						primaryReason={primaryReason}
					/>

					{checkoutUrl && (
						<Button
							as={Link}
							href={checkoutUrl}
							color="emerald"
							className="w-full text-sm sm:w-auto"
							onClick={handleCheckout}
						>
							Continuar al checkout
						</Button>
					)}
				</div>
			</div>
		</div>
	);
}
