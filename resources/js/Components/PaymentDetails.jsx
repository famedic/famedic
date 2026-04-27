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

export default function PaymentDetails({ transaction }) {
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