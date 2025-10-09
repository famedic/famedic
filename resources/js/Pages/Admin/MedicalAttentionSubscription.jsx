import React from "react";
import {
	PhoneIcon,
	CheckCircleIcon,
	XCircleIcon,
	EnvelopeIcon,
} from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Code, Anchor } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";
import PhoneButton from "@/Components/PhoneButton";
import CustomerLink from "@/Components/CustomerLink";
import MedicalAttentionBadge from "@/Components/MedicalAttentionBadge";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";
import {
	RegularAccountBadge,
	FamilyAccountBadge,
	OdessaIdentifierBadge,
	OdessaCompanyBadge,
	OdessaPartnerBadge,
} from "@/Components/CustomerAccountBadges";
import FamilyMembersList from "@/Components/FamilyMembersList";
import PaymentDetails from "@/Components/PaymentDetails";
import {
	getCustomerFullName,
	getCustomerPhone,
	getCustomerEmail,
} from "@/Utils/customerHelpers";

export default function MedicalAttentionSubscription({ subscription }) {
	return (
		<AdminLayout title="Membresía médica">
			<Header subscription={subscription} />

			<SubscriptionDetails subscription={subscription} />

			<Customer subscription={subscription} />

			{subscription.customer?.family_members?.length > 0 && (
				<FamilyMembers subscription={subscription} />
			)}

			{subscription.transactions && subscription.transactions.length > 0 && (
				<PaymentDetails transaction={subscription.transactions[0]} />
			)}
		</AdminLayout>
	);
}

function Header({ subscription }) {
	return (
		<div className="space-y-2">
			<Heading>Membresía médica</Heading>

			<MedicalAttentionBadge isActive={subscription.is_active}>
				{subscription.customer.medical_attention_identifier}
			</MedicalAttentionBadge>
		</div>
	);
}

function SubscriptionDetails({ subscription }) {
	return (
		<div>
			<Subheading>Detalles de la membresía</Subheading>
			<DescriptionList>
				<DescriptionTerm>Fecha de inicio</DescriptionTerm>
				<DescriptionDetails>
					{subscription.formatted_start_date}
				</DescriptionDetails>

				<DescriptionTerm>Fecha de expiración</DescriptionTerm>
				<DescriptionDetails>
					{subscription.formatted_end_date}
				</DescriptionDetails>

				<DescriptionTerm>Precio</DescriptionTerm>
				<DescriptionDetails>
					{subscription.formatted_price}
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}

function Customer({ subscription }) {
	const customer = subscription.customer;
	if (!customer) return null;

	const displayName =
		getCustomerFullName(customer) || getCustomerEmail(customer);
	const phone = getCustomerPhone(customer);
	const email = getCustomerEmail(customer);

	return (
		<div>
			<Subheading>Cliente</Subheading>

			<DescriptionList>
				<DescriptionTerm>Nombre</DescriptionTerm>
				<DescriptionDetails>
					<CustomerLink
						href={route("admin.customers.show", customer.id)}
					>
						{displayName}
					</CustomerLink>
				</DescriptionDetails>

				<DescriptionTerm>Tipo de cuenta</DescriptionTerm>
				<DescriptionDetails>
					<div className="flex flex-wrap items-center gap-2">
						{customer.customerable_type ===
							"App\\Models\\RegularAccount" && (
							<RegularAccountBadge />
						)}
						{customer.customerable_type ===
							"App\\Models\\FamilyAccount" && (
							<FamilyAccountBadge customer={customer} />
						)}
						{customer.customerable_type ===
							"App\\Models\\OdessaAfiliateAccount" && (
							<>
								<OdessaIdentifierBadge customer={customer} />
								<OdessaCompanyBadge customer={customer} />
								<OdessaPartnerBadge customer={customer} />
							</>
						)}
					</div>
				</DescriptionDetails>

				<DescriptionTerm>Teléfono</DescriptionTerm>
				<DescriptionDetails>
					<PhoneButton
						phone={phone}
						countryCode={
							customer.user?.phone_country ||
							customer.customerable?.phone_country
						}
					/>
				</DescriptionDetails>

				{email && (
					<>
						<DescriptionTerm>Correo electrónico</DescriptionTerm>
						<DescriptionDetails>
							<Anchor href={`mailto:${email}`}>
								<Button outline>
									<EnvelopeIcon />
									{email}
								</Button>
							</Anchor>
						</DescriptionDetails>
					</>
				)}
			</DescriptionList>
		</div>
	);
}

function FamilyMembers({ subscription }) {
	const familyMembers = subscription.customer.family_members;

	return (
		<div>
			<Subheading>Miembros de familia cubiertos</Subheading>

			<FamilyMembersList
				familyMembers={familyMembers}
				showMedicalId={true}
			/>
		</div>
	);
}

