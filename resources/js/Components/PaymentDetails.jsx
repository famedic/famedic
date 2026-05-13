import React, { useMemo, useState } from "react";
import axios from "axios";
import { Subheading } from "@/Components/Catalyst/heading";
import { Code } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";

function formatMxFromCents(cents) {
	if (cents == null || cents === "") return "—";
	const n = Number(cents);
	if (Number.isNaN(n)) return "—";
	return (n / 100).toLocaleString("es-MX", {
		style: "currency",
		currency: "MXN",
	});
}

export default function PaymentDetails({ transaction, purchase = null }) {
	const couponDiscountCents = Number(
		purchase?.coupon_discount_cents ??
			transaction?.details?.coupon_amount_cents ??
			0,
	);

	const hasCreditApplied =
		couponDiscountCents > 0 ||
		transaction?.payment_method === "coupon_balance";

	const formattedOrderTotal =
		purchase?.formatted_total ??
		(transaction?.details?.original_total_cents != null
			? formatMxFromCents(transaction.details.original_total_cents)
			: null);

	const formattedCouponDiscount =
		purchase?.formatted_coupon_discount ??
		(couponDiscountCents > 0
			? formatMxFromCents(couponDiscountCents)
			: null);

	const [isSearching, setIsSearching] = useState(false);
	const [searchResult, setSearchResult] = useState(null);
	const [searchError, setSearchError] = useState(null);

	const efevooTransactionId = useMemo(() => {
		if (transaction.payment_method !== "efevoopay") {
			return null;
		}

		const candidates = [
			transaction.gateway_transaction_id,
			transaction.provider_transaction_id,
			transaction.reference_id,
		];

		for (const candidate of candidates) {
			if (candidate !== null && candidate !== undefined && candidate !== "") {
				const normalized = Number(candidate);
				if (Number.isFinite(normalized)) {
					return Math.trunc(normalized);
				}
			}
		}

		return null;
	}, [transaction]);

	const handleSearchTransaction = async () => {
		if (!efevooTransactionId || isSearching) {
			return;
		}

		setIsSearching(true);
		setSearchError(null);

		try {
			const { data } = await axios.post("/api/efevoopay/transactions/search", {
				transaction_id: efevooTransactionId,
			});
			setSearchResult(data);
		} catch (error) {
			setSearchResult(error?.response?.data ?? null);
			setSearchError(
				error?.response?.data?.message ??
					error?.message ??
					"No se pudo consultar la transacción en EfevooPay."
			);
		} finally {
			setIsSearching(false);
		}
	};

	return (
		<div>
			<Subheading>Pago</Subheading>

			<DescriptionList>
				<DescriptionTerm>Método de pago</DescriptionTerm>
				<DescriptionDetails>
					<PaymentMethodBadge transaction={transaction} />
				</DescriptionDetails>

				{hasCreditApplied && (
					<>
						<DescriptionTerm>Crédito a favor</DescriptionTerm>
						<DescriptionDetails>
							<div className="space-y-2 rounded-lg border border-orange-200 bg-orange-50/80 p-3 text-sm dark:border-orange-900/60 dark:bg-orange-950/30">
								{formattedOrderTotal && (
									<div className="flex justify-between gap-4 text-zinc-800 dark:text-zinc-200">
										<span>Total del pedido</span>
										<span className="font-medium tabular-nums">
											{formattedOrderTotal}
										</span>
									</div>
								)}
								{formattedCouponDiscount && (
									<div className="flex justify-between gap-4 text-orange-800 dark:text-orange-200">
										<span>Descuento aplicado</span>
										<span className="font-medium tabular-nums">
											−{formattedCouponDiscount}
										</span>
									</div>
								)}
								<div className="flex justify-between gap-4 border-t border-orange-200/80 pt-2 font-medium text-zinc-900 dark:border-orange-900/50 dark:text-zinc-100">
									<span>Total cobrado</span>
									<span className="tabular-nums">
										{transaction.formatted_amount}
									</span>
								</div>
							</div>
						</DescriptionDetails>
					</>
				)}

				{transaction.payment_status && (
					<>
						<DescriptionTerm>Estado del pago</DescriptionTerm>
						<DescriptionDetails>
							<Code>{transaction.payment_status}</Code>
						</DescriptionDetails>
					</>
				)}

				{transaction.payment_method === "paypal" && transaction.provider_order_id && (
					<>
						<DescriptionTerm>PayPal Order ID</DescriptionTerm>
						<DescriptionDetails>
							<Code>{transaction.provider_order_id}</Code>
						</DescriptionDetails>
					</>
				)}

				{transaction.payment_method === "paypal" && transaction.provider_transaction_id && (
					<>
						<DescriptionTerm>Transacción (captura)</DescriptionTerm>
						<DescriptionDetails>
							<Code>{transaction.provider_transaction_id}</Code>
						</DescriptionDetails>
					</>
				)}

				<DescriptionTerm>Referencia</DescriptionTerm>
				<DescriptionDetails>
					<Code>{transaction.reference_id}</Code>
				</DescriptionDetails>

				<DescriptionTerm>Fecha</DescriptionTerm>
				<DescriptionDetails>
					{transaction.formatted_created_at}
				</DescriptionDetails>

				{transaction.payment_method === "efevoopay" && (
					<>
						<DescriptionTerm>Consulta Efevoo</DescriptionTerm>
						<DescriptionDetails>
							<div className="space-y-2">
								<Button
									outline
									onClick={handleSearchTransaction}
									disabled={!efevooTransactionId || isSearching}
								>
									{isSearching
										? "Consultando..."
										: "Consultar transacción en Efevoo"}
								</Button>

								{!efevooTransactionId && (
									<div className="text-sm text-red-600">
										No se encontró un ID numérico de transacción para consultar.
									</div>
								)}

								{searchError && (
									<div className="text-sm text-red-600">{searchError}</div>
								)}

								{searchResult && (
									<pre className="max-h-72 overflow-auto rounded-md bg-slate-950 p-3 text-xs text-slate-100">
										{JSON.stringify(searchResult, null, 2)}
									</pre>
								)}
							</div>
						</DescriptionDetails>
					</>
				)}
			</DescriptionList>
		</div>
	);
}