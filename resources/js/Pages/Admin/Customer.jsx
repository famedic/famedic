import AdminLayout from "@/Layouts/AdminLayout";
import React from "react";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import Card from "@/Components/Card";
import MedicalAttentionSubscriptionTableRow from "@/Components/MedicalAttentionSubscriptionTableRow";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Text, Strong, Anchor } from "@/Components/Catalyst/text";
import {
	CalendarIcon,
	CreditCardIcon,
	EnvelopeIcon,
	KeyIcon,
	MapPinIcon,
	Cog6ToothIcon,
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
import { useForm } from "@inertiajs/react";
import ManageUserDialog from "@/Components/Admin/ManageUserDialog";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import { Input } from "@/Components/Catalyst/input";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import CustomerMonitorPanel from "@/Components/Admin/CustomerMonitorPanel";

export default function Customer({
	customer,
	genders = [],
	states = {},
	canManageUser = false,
	canUpdatePassword = false,
	canViewTaxProfilesAdmin = false,
	canViewPaymentAttempts = false,
	efevooTokens = [],
	efevooTransactions = [],
	paymentAttempts = [],
	laboratoryNotifications = [],
	unreadLabNotificationsCount = 0,
	monitoringCarts = null,
	canViewCartDetails = false,
	laboratoryPurchases,
	onlinePharmacyPurchases,
	medicalAttentionSubscriptions,
	pendingPurchases = [],
	pendingPurchasesSummary = {},
	pendingActivity = [],
	notificationGroups = [],
	platformAccess = null,
	interactionSummary = {},
	recentActivity = [],
	activeCampaignMirror = null,
	activeCampaignDispatches = [],
	activeCampaignContactUrl = null,
	canViewActiveCampaignHub = false,
	taxRegimes = {},
}) {
	const [manageOpen, setManageOpen] = useState(false);
	const [passwordOpen, setPasswordOpen] = useState(false);
	const user = customer.user;

	return (
		<AdminLayout title={getCustomerFullName(customer) || "Cliente"}>
			<Header
				customer={customer}
				canManageUser={canManageUser}
				canUpdatePassword={canUpdatePassword}
				onManage={() => setManageOpen(true)}
				onPasswordOpen={() => setPasswordOpen(true)}
			/>

			{user && canManageUser && (
				<ManageUserDialog
					open={manageOpen}
					onClose={setManageOpen}
					user={user}
					genders={genders}
					states={states}
				/>
			)}

			{user && canUpdatePassword && (
				<UpdatePasswordDialog
					open={passwordOpen}
					onClose={() => setPasswordOpen(false)}
					user={user}
				/>
			)}

			<MedicalAttentionInfo customer={customer} />

			<FamilyMembersList familyMembers={customer.family_members} />

			<CustomerMonitorPanel
				customer={customer}
				canViewCartDetails={canViewCartDetails}
				canViewTaxProfilesAdmin={canViewTaxProfilesAdmin}
				canViewPaymentAttempts={canViewPaymentAttempts}
				paymentAttempts={paymentAttempts}
				efevooTokens={efevooTokens}
				efevooTransactions={efevooTransactions}
				laboratoryNotifications={laboratoryNotifications}
				unreadLabNotificationsCount={unreadLabNotificationsCount}
				pendingActivity={pendingActivity}
				pendingPurchasesSummary={pendingPurchasesSummary}
				interactionSummary={interactionSummary}
				recentActivity={recentActivity}
				notificationGroups={notificationGroups}
				platformAccess={platformAccess}
				activeCampaignMirror={activeCampaignMirror}
				activeCampaignDispatches={activeCampaignDispatches}
				activeCampaignContactUrl={activeCampaignContactUrl}
				canViewActiveCampaignHub={canViewActiveCampaignHub}
				taxRegimes={taxRegimes}
				laboratoryPurchases={laboratoryPurchases}
				onlinePharmacyPurchases={onlinePharmacyPurchases}
				medicalAttentionSubscriptions={medicalAttentionSubscriptions}
				PaymentAttemptsCard={PaymentAttemptsCard}
				EfevooTokensCard={EfevooTokensCard}
				EfevooTransactionsCard={EfevooTransactionsCard}
				PurchaseTabs={PurchaseTabs}
			/>
		</AdminLayout>
	);
}

function Header({
	customer,
	canManageUser,
	canUpdatePassword,
	onManage,
	onPasswordOpen,
}) {
	return (
		<div className="flex flex-wrap items-start justify-between gap-4">
			<div className="space-y-2">
				<Heading>
					{getCustomerFullName(customer) || getCustomerEmail(customer)}
				</Heading>
				<CustomerAccountTypeBadges customer={customer} />
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
			<div className="flex flex-wrap gap-2">
				{customer.user && (
					<Button
						outline
						href={route("admin.users.show", { user: customer.user.id })}
					>
						<UserIcon />
						Ver usuario
					</Button>
				)}
				{canUpdatePassword && (
					<Button type="button" outline onClick={onPasswordOpen}>
						<KeyIcon />
						Password
					</Button>
				)}
				{canManageUser && (
					<Button type="button" onClick={onManage}>
						<Cog6ToothIcon />
						Gestionar
					</Button>
				)}
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

function AddressesCard({ customer }) {
	const addresses = customer.addresses ?? [];

	return (
		<MonitorCard title="Direcciones" icon={MapPinIcon}>
			{addresses.length === 0 ? (
				<EmptyListCard />
			) : (
				<ul className="space-y-3 text-sm">
					{addresses.map((address) => (
						<li
							key={address.id}
							className="border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800"
						>
							<Text>
								<Strong>{address.alias || "Dirección"}</Strong>
							</Text>
							<Text className="whitespace-pre-line">
								{address.formatted_address || address.full_address || "—"}
							</Text>
						</li>
					))}
				</ul>
			)}
		</MonitorCard>
	);
}

function ContactsCard({ customer }) {
	const contacts = customer.contacts ?? [];

	return (
		<MonitorCard title="Contactos">
			{contacts.length === 0 ? (
				<EmptyListCard />
			) : (
				<ul className="space-y-3 text-sm">
					{contacts.map((contact) => (
						<li
							key={contact.id}
							className="border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800"
						>
							<div className="flex flex-wrap items-center gap-2">
								<Text>
									<Strong>
										{contact.full_name ||
											[
												contact.name,
												contact.paternal_lastname,
												contact.maternal_lastname,
											]
												.filter(Boolean)
												.join(" ") ||
											"Contacto"}
									</Strong>
								</Text>
								{contact.deleted_at && <Badge color="red">Eliminado</Badge>}
							</div>
							<Text>
								{contact.phone_for_display || contact.phone || "Sin teléfono"}
							</Text>
							{(contact.formatted_birth_date || contact.formatted_gender) && (
								<Text className="text-xs text-zinc-500">
									{contact.formatted_birth_date}
									{contact.formatted_birth_date && contact.formatted_gender
										? " · "
										: ""}
									{contact.formatted_gender}
								</Text>
							)}
						</li>
					))}
				</ul>
			)}
		</MonitorCard>
	);
}

function TaxProfilesCard({ customer, canViewTaxProfilesAdmin }) {
	const profiles = customer.tax_profiles ?? customer.taxProfiles ?? [];

	return (
		<MonitorCard
			title="Perfiles fiscales"
			action={
				canViewTaxProfilesAdmin ? (
					<Button
						outline
						size="sm"
						href={route("admin.tax-profiles.show", {
							customer: customer.id,
						})}
					>
						Abrir
					</Button>
				) : null
			}
		>
			{profiles.length === 0 ? (
				<EmptyListCard />
			) : (
				<ul className="space-y-4 text-sm">
					{profiles.map((profile) => (
						<li
							key={profile.id}
							className="border-b border-zinc-100 pb-4 last:border-0 last:pb-0 dark:border-zinc-800"
						>
							<div className="flex flex-wrap items-center gap-2">
								<Text className="font-medium">
									{profile.razon_social || profile.name || "Sin nombre"}
								</Text>
								{profile.deleted_at && <Badge color="red">Eliminado</Badge>}
							</div>
							<Text>RFC: {profile.rfc || "—"}</Text>
							<Text>Código postal: {profile.zipcode || "—"}</Text>
							<Text>
								Régimen: {profile.formatted_tax_regime || profile.tax_regime || "—"}
							</Text>
							<Text>Uso CFDI: {profile.formatted_cfdi_use || profile.cfdi_use || "—"}</Text>
						</li>
					))}
				</ul>
			)}
		</MonitorCard>
	);
}

function PaymentAttemptsCard({ attempts, canViewPaymentAttempts }) {
	const colorFor = (status) => {
		if (status === "approved") return "famedic-lime";
		if (status === "declined" || status === "error") return "red";
		return "slate";
	};

	return (
		<MonitorCard title="Intentos de pago" icon={CreditCardIcon}>
			{attempts.length === 0 ? (
				<EmptyListCard />
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Referencia</TableHeader>
							<TableHeader>Monto</TableHeader>
							<TableHeader>Estatus</TableHeader>
							<TableHeader>Fecha</TableHeader>
							<TableHeader></TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{attempts.map((attempt) => (
							<TableRow key={attempt.id}>
								<TableCell>{attempt.reference || `#${attempt.id}`}</TableCell>
								<TableCell>
									${((attempt.amount_cents || 0) / 100).toFixed(2)}
								</TableCell>
								<TableCell>
									<Badge color={colorFor(attempt.status)}>
										{attempt.status || "N/D"}
									</Badge>
								</TableCell>
								<TableCell>
									{formatDateTime(attempt.processed_at || attempt.created_at)}
								</TableCell>
								<TableCell>
									{canViewPaymentAttempts && (
										<Button
											outline
											size="sm"
											href={route("admin.payment-attempts.show", {
												payment_attempt: attempt.id,
											})}
										>
											Ver
										</Button>
									)}
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</MonitorCard>
	);
}

function EfevooTokensCard({ tokens }) {
	return (
		<MonitorCard title="Tokens Efevoo" icon={CreditCardIcon}>
			{tokens.length === 0 ? (
				<EmptyListCard />
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Tarjeta</TableHeader>
							<TableHeader>Entorno</TableHeader>
							<TableHeader>Estatus</TableHeader>
							<TableHeader>Transacciones</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{tokens.map((token) => (
							<TableRow key={token.id}>
								<TableCell>
									{token.alias || token.card_brand || "Tarjeta"} ••••{" "}
									{token.card_last_four || "—"}
								</TableCell>
								<TableCell>{token.environment || "—"}</TableCell>
								<TableCell>
									<Badge color={token.is_active ? "famedic-lime" : "slate"}>
										{token.is_active ? "Activo" : "Inactivo"}
									</Badge>
								</TableCell>
								<TableCell>{token.transactions_count || 0}</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</MonitorCard>
	);
}

function EfevooTransactionsCard({ transactions }) {
	return (
		<MonitorCard title="Transacciones Efevoo recientes">
			{transactions.length === 0 ? (
				<EmptyListCard />
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Referencia</TableHeader>
							<TableHeader>Monto</TableHeader>
							<TableHeader>Estatus</TableHeader>
							<TableHeader>Fecha</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{transactions.map((tx) => (
							<TableRow key={tx.id}>
								<TableCell>{tx.reference || `#${tx.id}`}</TableCell>
								<TableCell>
									{tx.amount} {tx.currency}
								</TableCell>
								<TableCell>{tx.status || "—"}</TableCell>
								<TableCell>{formatDateTime(tx.processed_at || tx.created_at)}</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</MonitorCard>
	);
}

function MonitorCard({ title, icon: Icon, action, children }) {
	return (
		<div className="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex items-center justify-between gap-3">
				<div className="flex items-center gap-2">
					{Icon && <Icon className="size-5 text-zinc-500" />}
					<Subheading>{title}</Subheading>
				</div>
				{action}
			</div>
			{children}
		</div>
	);
}

function formatDateTime(value) {
	if (!value) return "—";
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) return String(value);

	return date.toLocaleString("es-MX", {
		dateStyle: "medium",
		timeStyle: "short",
	});
}

function PurchaseTabs({
	laboratoryPurchases,
	onlinePharmacyPurchases,
	medicalAttentionSubscriptions,
}) {
	const [activeTab, setActiveTab] = useState("laboratory");

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

function UpdatePasswordDialog({ open, onClose, user }) {
	const { data, setData, post, errors, processing, reset, clearErrors } =
		useForm({
			password: "",
			password_confirmation: "",
		});

	const submit = (event) => {
		event.preventDefault();

		if (!processing) {
			post(route("admin.users.update-password", user.id), {
				preserveScroll: true,
				onSuccess: () => {
					reset();
					onClose();
				},
			});
		}
	};

	const handleClose = () => {
		reset();
		clearErrors();
		onClose();
	};

	return (
		<Dialog open={open} onClose={handleClose} size="lg">
			<form onSubmit={submit}>
				<DialogTitle>Actualizar contraseña</DialogTitle>
				<DialogDescription>
					Establece una nueva contraseña para{" "}
					<Strong>{user.full_name || user.email}</Strong>.
				</DialogDescription>

				<DialogBody className="space-y-4">
					<Field>
						<Label>Nueva contraseña</Label>
						<Input
							type="password"
							required
							autoComplete="new-password"
							value={data.password}
							onChange={(event) => setData("password", event.target.value)}
						/>
						{errors.password && (
							<ErrorMessage>{errors.password}</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Confirmar contraseña</Label>
						<Input
							type="password"
							required
							autoComplete="new-password"
							value={data.password_confirmation}
							onChange={(event) =>
								setData("password_confirmation", event.target.value)
							}
						/>
						{errors.password_confirmation && (
							<ErrorMessage>{errors.password_confirmation}</ErrorMessage>
						)}
					</Field>
				</DialogBody>

				<DialogActions>
					<Button type="button" plain onClick={handleClose}>
						Cancelar
					</Button>
					<Button type="submit" disabled={processing}>
						Actualizar contraseña
					</Button>
				</DialogActions>
			</form>
		</Dialog>
	);
}
