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