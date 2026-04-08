import React from "react";
import { Subheading } from "@/Components/Catalyst/heading";
import { Code } from "@/Components/Catalyst/text";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";

export default function PaymentDetails({ transaction }) {
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
			</DescriptionList>
		</div>
	);
}