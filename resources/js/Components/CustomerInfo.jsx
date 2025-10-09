import { Text, Strong } from "@/Components/Catalyst/text";
import { getCustomerFullName, getCustomerEmail } from "@/Utils/customerHelpers";
import {
	RegularAccountBadge,
	FamilyAccountBadge,
	OdessaIdentifierBadge,
	OdessaCompanyBadge,
	OdessaPartnerBadge,
} from "@/Components/CustomerAccountBadges";

export default function CustomerInfo({ customer }) {
	const customerName = getCustomerFullName(customer);
	const customerEmail = getCustomerEmail(customer);

	return (
		<div className="space-y-1">
			{customerName && (
				<Text>
					<Strong>{customerName}</Strong>
				</Text>
			)}
			{customerEmail && (
				<Text>{customerEmail}</Text>
			)}
			<div className="flex flex-wrap items-center gap-1">
				{customer.customerable_type === "App\\Models\\RegularAccount" && (
					<RegularAccountBadge />
				)}
				{customer.customerable_type === "App\\Models\\FamilyAccount" && (
					<FamilyAccountBadge customer={customer} />
				)}
				{customer.customerable_type === "App\\Models\\OdessaAfiliateAccount" && (
					<>
						<OdessaIdentifierBadge customer={customer} />
						<OdessaCompanyBadge customer={customer} />
						<OdessaPartnerBadge customer={customer} />
					</>
				)}
			</div>
		</div>
	);
}