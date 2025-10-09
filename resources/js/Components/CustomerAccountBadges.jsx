import { Badge } from "@/Components/Catalyst/badge";
import { Strong } from "@/Components/Catalyst/text";
import {
	GlobeAltIcon,
	UserGroupIcon,
} from "@heroicons/react/16/solid";
import OdessaBadge from "@/Components/OdessaBadge";

export function RegularAccountBadge() {
	return (
		<Badge color="slate">
			<GlobeAltIcon className="size-4 shrink-0" data-slot="icon" />
			Regular
		</Badge>
	);
}

export function FamilyAccountBadge({ customer }) {
	return (
		<Badge color="slate">
			<UserGroupIcon className="size-4 shrink-0" data-slot="icon" />
			Familiar
		</Badge>
	);
}

export function FamilyRelationshipBadge({ customer }) {
	if (!customer.customerable?.parentCustomer) {
		return null;
	}

	return (
		<Badge color="slate">
			{customer.customerable?.formatted_kinship || "Familiar"} de{" "}
			<Strong>
				{customer.customerable?.parentCustomer?.user?.full_name ||
					customer.customerable?.parentCustomer?.user?.email ||
					"N/A"}
			</Strong>
		</Badge>
	);
}

export function OdessaIdentifierBadge({ customer }) {
	if (!customer.customerable?.odessa_identifier) {
		return null;
	}

	return (
		<OdessaBadge>
			ODESSA <Strong>{customer.customerable.odessa_identifier}</Strong>
		</OdessaBadge>
	);
}

export function OdessaCompanyBadge({ customer }) {
	const companyName = customer.customerable?.odessa_afiliated_company?.name ||
		customer.customerable?.odessa_afiliated_company_id;

	if (!companyName) {
		return null;
	}

	return (
		<Badge color="slate">
			Empresa <Strong>{companyName}</Strong>
		</Badge>
	);
}

export function OdessaPartnerBadge({ customer }) {
	if (!customer.customerable?.partner_identifier) {
		return null;
	}

	return (
		<Badge color="slate">
			Socio <Strong>{customer.customerable.partner_identifier}</Strong>
		</Badge>
	);
}