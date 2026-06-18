import { Text } from "@/Components/Catalyst/text";
import {
	formatCouponMoney,
	formatCouponDate,
	formatCouponDateLong,
	getAmountMissingForMinimum,
} from "@/lib/couponPatientUi";

export default function BalanceCreditRules({
	coupon,
	cartTotalCents,
	primaryReason,
	selectedCoupon = null,
	className = "",
}) {
	if (!coupon) return null;

	const reason = primaryReason;
	const displaySelected = selectedCoupon ?? coupon;
	const shortfall = getAmountMissingForMinimum(displaySelected, cartTotalCents);

	return (
		<div className={["space-y-1.5 text-xs text-zinc-700 dark:text-zinc-300", className].join(" ")}>
			{coupon.expires_at && (
				<Text>
					Disponible hasta: {formatCouponDate(coupon.expires_at)}
				</Text>
			)}
			{coupon.formatted_min_purchase && (
				<Text>Compra mínima: {coupon.formatted_min_purchase}</Text>
			)}

			{reason === "scheduled" && coupon.valid_from && (
				<Text className="text-amber-800 dark:text-amber-200">
					Tienes un crédito programado disponible a partir del{" "}
					{formatCouponDateLong(coupon.valid_from)}.
				</Text>
			)}

			{reason === "below_minimum" && coupon.formatted_min_purchase && (
				<Text className="text-amber-800 dark:text-amber-200">
					Tienes saldo a favor, pero esta compra no alcanza el mínimo requerido.
					Compra mínima: {coupon.formatted_min_purchase}.
					{shortfall > 0 && (
						<>
							{" "}
							Te faltan {formatCouponMoney(shortfall)} para poder usarlo.
						</>
					)}
				</Text>
			)}

			{reason === "balance_too_large" && (
				<Text className="text-amber-800 dark:text-amber-200">
					Tu saldo a favor es mayor que el total de esta compra. Por ahora solo
					puede usarse en compras iguales o mayores al saldo disponible.
				</Text>
			)}

			{reason === "applicable" && (
				<Text className="text-emerald-800 dark:text-emerald-200">
					Puedes aplicarlo a esta compra.
				</Text>
			)}
		</div>
	);
}
