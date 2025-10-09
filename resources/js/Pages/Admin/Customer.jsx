import AdminLayout from "@/Layouts/AdminLayout";
import React from "react";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Avatar } from "@/Components/Catalyst/avatar";
import Card from "@/Components/Card";
import MedicalAttentionSubscriptionTableRow from "@/Components/MedicalAttentionSubscriptionTableRow";
import {
	Table,
	TableBody,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Text, Strong, Anchor } from "@/Components/Catalyst/text";
import {
	CalendarIcon,
	EnvelopeIcon,
	UserIcon,
} from "@heroicons/react/16/solid";
import PhoneButton from "@/Components/PhoneButton";
import EmptyListCard from "@/Components/EmptyListCard";
import LaboratoryPurchaseTableRow from "@/Components/LaboratoryPurchaseTableRow";
import OnlinePharmacyPurchaseTableRow from "@/Components/OnlinePharmacyPurchaseTableRow";
import MedicalAttentionBadge from "@/Components/MedicalAttentionBadge";
import { convertPaginationData } from "@/Utils/paginationHelpers";
import MultiTablePagination from "@/Components/MultiTablePagination";
import { Navbar, NavbarItem } from "@/Components/Catalyst/navbar";
import { useState } from "react";
import {
	getCustomerFullName,
	getCustomerBirthDate,
	getCustomerPhone,
	getCustomerEmail,
	getCustomerGender,
} from "@/Utils/customerHelpers";
import {
	RegularAccountBadge,
	FamilyAccountBadge,
	FamilyRelationshipBadge,
	OdessaIdentifierBadge,
	OdessaCompanyBadge,
	OdessaPartnerBadge,
} from "@/Components/CustomerAccountBadges";

export default function Customer({
	customer,
	laboratoryPurchases,
	onlinePharmacyPurchases,
	medicalAttentionSubscriptions,
}) {
	const [activeTab, setActiveTab] = useState("laboratory");
	return (
		<AdminLayout title={getCustomerFullName(customer) || "Cliente"}>
			<Header customer={customer} />

			<MedicalAttentionInfo customer={customer} />

			<FamilyMembersList familyMembers={customer.family_members} />

			<PurchaseTabs
				activeTab={activeTab}
				setActiveTab={setActiveTab}
				laboratoryPurchases={laboratoryPurchases}
				onlinePharmacyPurchases={onlinePharmacyPurchases}
				medicalAttentionSubscriptions={medicalAttentionSubscriptions}
			/>
		</AdminLayout>
	);
}

function Header({ customer }) {
	return (
		<div className="space-y-2">
			<Heading>
				{getCustomerFullName(customer) || getCustomerEmail(customer)}
			</Heading>
			<CustomerAccountTypeBadges customer={customer} />
			<div>
				<div className="flex flex-wrap gap-x-10 gap-y-1">
					{getCustomerGender(customer) && (
						<Text className="flex items-center gap-2">
							<UserIcon className="size-4" />
							{getCustomerGender(customer)}
						</Text>
					)}
					{getCustomerBirthDate(customer) && (
						<Text className="flex items-center gap-2">
							<CalendarIcon className="size-4" />
							{getCustomerBirthDate(customer)}
						</Text>
					)}
					{getCustomerPhone(customer) && (
						<PhoneButton
							phone={getCustomerPhone(customer)}
							countryCode={
								customer.user?.phone_country ||
								customer.customerable?.phone_country
							}
						/>
					)}
					{getCustomerEmail(customer) && (
						<Anchor href={`mailto:${getCustomerEmail(customer)}`}>
							<Button outline>
								<EnvelopeIcon />
								{getCustomerEmail(customer)}
							</Button>
						</Anchor>
					)}
				</div>
			</div>
		</div>
	);
}

function OnlinePharmacyPurchasesList({ onlinePharmacyPurchases }) {
	return (
		<div className="space-y-2">
			{onlinePharmacyPurchases.data.length === 0 ? (
				<EmptyListCard />
			) : (
				<>
					<Table className="[--gutter:theme(spacing.6)]">
						<TableHead>
							<TableRow>
								<TableHeader>Detalles</TableHeader>
								<TableHeader>Quien recibe</TableHeader>
								<TableHeader className="text-right">
									Detalles adicionales
								</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{onlinePharmacyPurchases.data.map(
								(onlinePharmacyPurchase) => (
									<OnlinePharmacyPurchaseTableRow
										key={onlinePharmacyPurchase.id}
										onlinePharmacyPurchase={
											onlinePharmacyPurchase
										}
									/>
								),
							)}
						</TableBody>
					</Table>
					<MultiTablePagination
						paginatedModels={convertPaginationData(
							onlinePharmacyPurchases,
							"pharmacy_page",
						)}
						only={["onlinePharmacyPurchases"]}
					/>
				</>
			)}
		</div>
	);
}

function LaboratoryPurchasesList({ laboratoryPurchases }) {
	return (
		<div className="space-y-2">
			{laboratoryPurchases.data.length === 0 ? (
				<EmptyListCard />
			) : (
				<>
					<Table className="[--gutter:theme(spacing.6)]">
						<TableHead>
							<TableRow>
								<TableHeader>Detalles</TableHeader>
								<TableHeader>Paciente</TableHeader>
								<TableHeader>Marca</TableHeader>
								<TableHeader className="text-right">
									Detalles adicionales
								</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{laboratoryPurchases.data.map(
								(laboratoryPurchase) => (
									<LaboratoryPurchaseTableRow
										key={laboratoryPurchase.id}
										laboratoryPurchase={laboratoryPurchase}
										showBrand={true}
									/>
								),
							)}
						</TableBody>
					</Table>
					<MultiTablePagination
						paginatedModels={convertPaginationData(
							laboratoryPurchases,
							"lab_page",
						)}
						only={["laboratoryPurchases"]}
					/>
				</>
			)}
		</div>
	);
}

function FamilyMembersList({ familyMembers }) {
	if (!familyMembers || familyMembers.length === 0) {
		return null;
	}

	return (
		<div className="space-y-2">
			<Subheading>Miembros de la familia</Subheading>
			<div className="flex flex-wrap gap-4">
				{familyMembers.map((familyMember) => (
					<div key={familyMember.id} className="w-full sm:w-48">
						<FamilyMemberCard familyMember={familyMember} />
					</div>
				))}
			</div>
		</div>
	);
}

function FamilyMemberCard({ familyMember }) {
	return (
		<Card className="h-full p-4">
			<div className="flex flex-col items-center space-y-2 text-center">
				<Avatar
					src={familyMember.profile_photo_url}
					className="size-12"
				/>

				<div className="space-y-2">
					<Text>
						<Strong>{familyMember.full_name}</Strong>
					</Text>

					{familyMember.customer?.medical_attention_identifier && (
						<MedicalAttentionBadge
							isActive={
								familyMember.customer
									?.medical_attention_subscription_is_active
							}
						>
							{familyMember.customer.medical_attention_identifier}
						</MedicalAttentionBadge>
					)}

					<Text className="!text-xs">
						{familyMember.formatted_kinship || "Familiar"}
					</Text>
				</div>

				<div className="space-y-1 text-sm text-zinc-600">
					{familyMember.formatted_birth_date && (
						<div className="flex items-center justify-center gap-2">
							<CalendarIcon className="size-4 shrink-0" />
							<span>{familyMember.formatted_birth_date}</span>
						</div>
					)}
					{familyMember.formatted_gender && (
						<div className="text-center">
							<span>{familyMember.formatted_gender}</span>
						</div>
					)}
				</div>
			</div>
		</Card>
	);
}

function MedicalAttentionInfo({ customer }) {
	return (
		<div className="space-y-2">
			<Subheading>Atención médica</Subheading>
			<div className="flex flex-wrap items-center gap-4">
				{customer.medical_attention_identifier && (
					<MedicalAttentionBadge
						isActive={
							customer.medical_attention_subscription_is_active
						}
					>
						{customer.medical_attention_identifier}
					</MedicalAttentionBadge>
				)}
				{customer.medical_attention_subscription_is_active &&
					customer.formatted_medical_attention_subscription_expires_at && (
						<Text>
							Expira el{" "}
							{
								customer.formatted_medical_attention_subscription_expires_at
							}
						</Text>
					)}
			</div>
		</div>
	);
}

function PurchaseTabs({
	activeTab,
	setActiveTab,
	laboratoryPurchases,
	onlinePharmacyPurchases,
	medicalAttentionSubscriptions,
}) {
	return (
		<div className="space-y-4">
			<Navbar className="-mt-2">
				<NavbarItem
					onClick={() => setActiveTab("laboratory")}
					current={activeTab === "laboratory"}
				>
					Pedidos de laboratorio
				</NavbarItem>
				<NavbarItem
					onClick={() => setActiveTab("pharmacy")}
					current={activeTab === "pharmacy"}
				>
					Pedidos de farmacia
				</NavbarItem>
				<NavbarItem
					onClick={() => setActiveTab("medical")}
					current={activeTab === "medical"}
				>
					Suscripciones médicas
				</NavbarItem>
			</Navbar>

			{activeTab === "laboratory" && (
				<LaboratoryPurchasesList
					laboratoryPurchases={laboratoryPurchases}
				/>
			)}

			{activeTab === "pharmacy" && (
				<OnlinePharmacyPurchasesList
					onlinePharmacyPurchases={onlinePharmacyPurchases}
				/>
			)}

			{activeTab === "medical" && (
				<MedicalAttentionSubscriptionsList
					medicalAttentionSubscriptions={
						medicalAttentionSubscriptions
					}
				/>
			)}
		</div>
	);
}

function MedicalAttentionSubscriptionsList({ medicalAttentionSubscriptions }) {
	if (
		!medicalAttentionSubscriptions ||
		medicalAttentionSubscriptions.data.length === 0
	) {
		return (
			<div className="space-y-2">
				<EmptyListCard />
			</div>
		);
	}

	return (
		<div className="space-y-2">
			<Table className="[--gutter:theme(spacing.6)]">
				<TableHead>
					<TableRow>
						<TableHeader>Detalles</TableHeader>
						<TableHeader className="text-right">
							Vigencia
						</TableHeader>
					</TableRow>
				</TableHead>
				<TableBody>
					{medicalAttentionSubscriptions.data.map((subscription) => (
						<MedicalAttentionSubscriptionTableRow
							key={subscription.id}
							subscription={subscription}
						/>
					))}
				</TableBody>
			</Table>
			<MultiTablePagination
				paginatedModels={convertPaginationData(
					medicalAttentionSubscriptions,
					"medical_attention_subscriptions_page",
				)}
				only={["medicalAttentionSubscriptions"]}
			/>
		</div>
	);
}

function CustomerAccountTypeBadges({ customer }) {
	return (
		<div className="mt-2 flex flex-wrap items-center gap-2">
			{customer.customerable_type === "App\\Models\\RegularAccount" && (
				<RegularAccountBadge />
			)}

			{customer.customerable_type === "App\\Models\\FamilyAccount" && (
				<>
					<FamilyAccountBadge customer={customer} />
					<FamilyRelationshipBadge customer={customer} />
				</>
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
	);
}
