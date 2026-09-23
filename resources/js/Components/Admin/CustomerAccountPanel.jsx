import { useMemo, useState } from "react";
import { router } from "@inertiajs/react";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Navbar, NavbarItem } from "@/Components/Catalyst/navbar";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import TaxProfileForm from "@/Pages/TaxProfiles/TaxProfileForm";
import {
	MapPinIcon,
	UserGroupIcon,
	DocumentTextIcon,
	PlusIcon,
	ArrowTopRightOnSquareIcon,
	PencilSquareIcon,
} from "@heroicons/react/16/solid";

function formatDateTime(value) {
	if (!value) return "—";
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) return String(value);
	return date.toLocaleString("es-MX", {
		dateStyle: "medium",
		timeStyle: "short",
	});
}

function profileIsUsed(profile) {
	return Boolean(profile?.is_used || profile?.used_invoice_requests_exist);
}

function splitLabel(value) {
	if (!value || value === "—") {
		return { code: "—", label: null };
	}

	const [code, ...rest] = String(value).split(" - ");

	if (rest.length === 0) {
		return { code: value, label: null };
	}

	return { code, label: rest.join(" - ") };
}

function TruncatedText({ value, className = "" }) {
	if (!value || value === "—") {
		return <span className="text-zinc-400">—</span>;
	}

	return (
		<span className={`block truncate ${className}`} title={value}>
			{value}
		</span>
	);
}

export default function CustomerAccountPanel({
	customer,
	canManageTaxProfiles = false,
	taxRegimes = {},
}) {
	const [activeTab, setActiveTab] = useState("tax-profiles");
	const [showDeletedProfiles, setShowDeletedProfiles] = useState(false);
	const [taxProfileForm, setTaxProfileForm] = useState(null);

	const profiles = customer?.tax_profiles ?? customer?.taxProfiles ?? [];
	const addresses = customer?.addresses ?? [];
	const contacts = customer?.contacts ?? [];

	const activeProfiles = useMemo(
		() => profiles.filter((profile) => !profile.deleted_at),
		[profiles],
	);
	const deletedProfiles = useMemo(
		() => profiles.filter((profile) => profile.deleted_at),
		[profiles],
	);
	const visibleProfiles = showDeletedProfiles ? profiles : activeProfiles;

	const handleTaxProfileSaved = () => {
		setTaxProfileForm(null);
		router.reload({ only: ["customer"], preserveScroll: true });
	};

	const openCreateForm = () => {
		setTaxProfileForm({ mode: "create" });
	};

	const openEditForm = (profile) => {
		setTaxProfileForm({ mode: "edit", profile });
	};

	return (
		<div className="space-y-4">
			<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Subheading>Cuenta del paciente</Subheading>
				<Text className="mt-1 text-sm text-zinc-500">
					Consulta y auxilia al paciente con direcciones, contactos y perfiles
					fiscales desde un solo lugar.
				</Text>

				<Navbar className="mt-4">
					<NavbarItem
						onClick={() => setActiveTab("tax-profiles")}
						current={activeTab === "tax-profiles"}
					>
						Perfiles fiscales ({activeProfiles.length})
					</NavbarItem>
					<NavbarItem
						onClick={() => setActiveTab("addresses")}
						current={activeTab === "addresses"}
					>
						Direcciones ({addresses.length})
					</NavbarItem>
					<NavbarItem
						onClick={() => setActiveTab("contacts")}
						current={activeTab === "contacts"}
					>
						Contactos ({contacts.length})
					</NavbarItem>
				</Navbar>
			</div>

			{activeTab === "tax-profiles" && (
				<TaxProfilesTab
					customer={customer}
					profiles={visibleProfiles}
					activeCount={activeProfiles.length}
					deletedCount={deletedProfiles.length}
					showDeletedProfiles={showDeletedProfiles}
					onToggleDeleted={() => setShowDeletedProfiles((v) => !v)}
					canManageTaxProfiles={canManageTaxProfiles}
					onCreate={openCreateForm}
					onEdit={openEditForm}
				/>
			)}

			{activeTab === "addresses" && (
				<AddressesTab addresses={addresses} />
			)}

			{activeTab === "contacts" && (
				<ContactsTab contacts={contacts} />
			)}

			{canManageTaxProfiles && customer?.id && taxProfileForm && (
				<TaxProfileForm
					isOpen
					adminMode
					taxProfile={
						taxProfileForm.mode === "edit" ? taxProfileForm.profile : null
					}
					taxRegimes={taxRegimes}
					extractUrl={route("admin.customers.tax-profiles.extract-data", {
						customer: customer.id,
					})}
					storeUrl={route("admin.customers.tax-profiles.store", {
						customer: customer.id,
					})}
					updateUrl={
						taxProfileForm.mode === "edit"
							? route("admin.customers.tax-profiles.update", {
									customer: customer.id,
									tax_profile: taxProfileForm.profile.id,
								})
							: null
					}
					onCreated={handleTaxProfileSaved}
					onClose={() => setTaxProfileForm(null)}
					dialogTitle={
						taxProfileForm.mode === "edit"
							? "Editar perfil fiscal del paciente"
							: "Crear perfil fiscal para el paciente"
					}
					dialogDescription={
						taxProfileForm.mode === "edit"
							? "Actualiza los datos fiscales del paciente. Si el perfil ya fue usado en facturación, no podrá modificarse."
							: "Sube la constancia del SAT o captura los datos manualmente. El perfil quedará asociado a la cuenta del paciente."
					}
					successMessage={
						taxProfileForm.mode === "edit"
							? "Perfil fiscal actualizado para el paciente."
							: "Perfil fiscal creado para el paciente."
					}
				/>
			)}
		</div>
	);
}

function TaxProfilesTab({
	customer,
	profiles,
	activeCount,
	deletedCount,
	showDeletedProfiles,
	onToggleDeleted,
	canManageTaxProfiles,
	onCreate,
	onEdit,
}) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="mb-4 flex flex-wrap items-start justify-between gap-3">
				<div>
					<div className="flex items-center gap-2">
						<DocumentTextIcon className="size-5 text-zinc-400" />
						<Subheading>Perfiles fiscales</Subheading>
					</div>
					<Text className="mt-1 text-sm text-zinc-500">
						{activeCount} activo{activeCount === 1 ? "" : "s"}
						{deletedCount > 0 ? ` · ${deletedCount} eliminado${deletedCount === 1 ? "" : "s"}` : ""}
					</Text>
				</div>
				<div className="flex flex-wrap items-center gap-2">
					{deletedCount > 0 && (
						<Button outline size="sm" onClick={onToggleDeleted}>
							{showDeletedProfiles ? "Ocultar eliminados" : "Ver eliminados"}
						</Button>
					)}
					{canManageTaxProfiles && (
						<Button size="sm" onClick={onCreate}>
							<PlusIcon />
							Crear perfil fiscal
						</Button>
					)}
					{canManageTaxProfiles && (
						<Button
							outline
							size="sm"
							href={route("admin.tax-profiles.show", {
								customer: customer.id,
							})}
						>
							<ArrowTopRightOnSquareIcon />
							Vista completa
						</Button>
					)}
				</div>
			</div>

			{canManageTaxProfiles && (
				<div className="mb-4 rounded-lg border border-sky-200 bg-sky-50/70 px-3 py-2 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
					Puedes crear y editar perfiles fiscales en nombre del paciente cuando
					tenga dificultades con el alta en su cuenta.
				</div>
			)}

			{profiles.length === 0 ? (
				<EmptyListCard />
			) : (
				<div className="overflow-x-auto">
					<Table dense className="min-w-[1080px] table-fixed">
						<colgroup>
							<col className="w-[22%]" />
							<col className="w-[11%]" />
							<col className="w-[6%]" />
							<col className="w-[18%]" />
							<col className="w-[14%]" />
							<col className="w-[11%]" />
							<col className="w-[10%]" />
							<col className="w-[8%]" />
						</colgroup>
						<TableHead>
							<TableRow>
								<TableHeader>Nombre / razón social</TableHeader>
								<TableHeader>RFC</TableHeader>
								<TableHeader>CP</TableHeader>
								<TableHeader>Régimen</TableHeader>
								<TableHeader>Uso CFDI</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader>Registrado</TableHeader>
								<TableHeader className="text-right">Acciones</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{profiles.map((profile) => {
								const regime = splitLabel(
									profile.formatted_tax_regime || profile.tax_regime,
								);
								const cfdi = splitLabel(
									profile.formatted_cfdi_use || profile.cfdi_use,
								);
								const isUsed = profileIsUsed(profile);
								const canEdit =
									canManageTaxProfiles &&
									!profile.deleted_at &&
									!isUsed;

								return (
									<TableRow key={profile.id}>
										<TableCell className="align-top">
											<TruncatedText
												value={
													profile.razon_social ||
													profile.name ||
													"Sin nombre"
												}
												className="font-medium"
											/>
											{profile.is_default && (
												<Badge color="famedic-lime" className="mt-1">
													Predeterminado
												</Badge>
											)}
										</TableCell>
										<TableCell className="align-top font-mono text-xs">
											<TruncatedText value={profile.rfc || "—"} />
										</TableCell>
										<TableCell className="align-top text-xs">
											{profile.zipcode || "—"}
										</TableCell>
										<TableCell className="align-top">
											<div className="min-w-0">
												<TruncatedText
													value={regime.code}
													className="text-xs font-medium"
												/>
												{regime.label && (
													<TruncatedText
														value={regime.label}
														className="text-xs text-zinc-500"
													/>
												)}
											</div>
										</TableCell>
										<TableCell className="align-top">
											<div className="min-w-0">
												<TruncatedText
													value={cfdi.code}
													className="text-xs font-medium"
												/>
												{cfdi.label && (
													<TruncatedText
														value={cfdi.label}
														className="text-xs text-zinc-500"
													/>
												)}
											</div>
										</TableCell>
										<TableCell className="align-top">
											<div className="flex flex-col gap-1">
												{profile.deleted_at ? (
													<Badge color="red">Eliminado</Badge>
												) : (
													<Badge color="famedic-lime">Activo</Badge>
												)}
												{profile.verificado_automaticamente && (
													<Badge color="sky">Auto-verificado</Badge>
												)}
												{isUsed && (
													<Badge color="amber">Usado en factura</Badge>
												)}
											</div>
										</TableCell>
										<TableCell className="align-top whitespace-nowrap text-xs text-zinc-500">
											{formatDateTime(profile.created_at)}
										</TableCell>
										<TableCell className="align-top text-right">
											<div className="flex flex-col items-end gap-1">
												{canEdit && (
													<Button
														outline
														size="sm"
														onClick={() => onEdit(profile)}
													>
														<PencilSquareIcon />
														Editar
													</Button>
												)}
												{canManageTaxProfiles &&
													profile.fiscal_certificate && (
														<Button
															outline
															size="sm"
															href={route(
																"admin.tax-profiles.fiscal-certificate",
																{
																	tax_profile: profile.id,
																},
															)}
														>
															Constancia
														</Button>
													)}
											</div>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</div>
			)}
		</div>
	);
}

function AddressesTab({ addresses }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="mb-4 flex items-center gap-2">
				<MapPinIcon className="size-5 text-zinc-400" />
				<Subheading>Direcciones</Subheading>
			</div>
			{addresses.length === 0 ? (
				<EmptyListCard />
			) : (
				<div className="grid gap-3 md:grid-cols-2">
					{addresses.map((address) => (
						<div
							key={address.id}
							className="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
						>
							<Text className="font-medium">
								{address.alias || "Dirección"}
							</Text>
							<Text className="mt-1 whitespace-pre-line text-sm text-zinc-600">
								{address.formatted_address || address.full_address || "—"}
							</Text>
						</div>
					))}
				</div>
			)}
		</div>
	);
}

function ContactsTab({ contacts }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="mb-4 flex items-center gap-2">
				<UserGroupIcon className="size-5 text-zinc-400" />
				<Subheading>Contactos</Subheading>
			</div>
			{contacts.length === 0 ? (
				<EmptyListCard />
			) : (
				<div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
					{contacts.map((contact) => (
						<div
							key={contact.id}
							className="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
						>
							<div className="flex flex-wrap items-center gap-2">
								<Text className="font-medium">
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
							<Text className="mt-2 text-sm text-zinc-600">
								{contact.phone_for_display || contact.phone || "Sin teléfono"}
							</Text>
							{(contact.formatted_birth_date || contact.formatted_gender) && (
								<Text className="mt-1 text-xs text-zinc-500">
									{contact.formatted_birth_date}
									{contact.formatted_birth_date && contact.formatted_gender
										? " · "
										: ""}
									{contact.formatted_gender}
								</Text>
							)}
						</div>
					))}
				</div>
			)}
		</div>
	);
}
