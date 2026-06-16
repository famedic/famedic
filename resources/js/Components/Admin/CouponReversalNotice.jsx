import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

function reversalReasonLabel(reason) {
	if (!reason) return null;
	if (reason === "laboratory_purchase_cancelled") {
		return "Cancelación de pedido de laboratorio";
	}
	return String(reason).replaceAll("_", " ");
}

export default function CouponReversalNotice({ couponReversal }) {
	if (!couponReversal) {
		return null;
	}

	const actor =
		couponReversal.reversed_by_user?.full_name ||
		couponReversal.reversed_by_user?.email;

	return (
		<section className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/30">
			<Subheading className="text-emerald-900 dark:text-emerald-100">
				Saldo a favor restaurado
			</Subheading>
			<div className="mt-2 space-y-1 text-sm text-emerald-900 dark:text-emerald-100">
				<Text>
					El crédito aplicado a este pedido fue devuelto al cupón del cliente.
				</Text>
				{couponReversal.formatted_amount_restored ? (
					<Text>
						Monto restaurado:{" "}
						<span className="font-semibold">
							{couponReversal.formatted_amount_restored}
						</span>
					</Text>
				) : null}
				{couponReversal.formatted_reversed_at ? (
					<Text>Fecha de reverso: {couponReversal.formatted_reversed_at}</Text>
				) : null}
				{reversalReasonLabel(couponReversal.reversal_reason) ? (
					<Text>
						Motivo: {reversalReasonLabel(couponReversal.reversal_reason)}
					</Text>
				) : null}
				{actor ? <Text>Restaurado por: {actor}</Text> : null}
			</div>
		</section>
	);
}
