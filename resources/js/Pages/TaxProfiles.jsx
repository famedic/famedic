import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Code } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import {
	DocumentTextIcon,
	PlusIcon,
	QrCodeIcon,
	CheckCircleIcon,
	ClockIcon,
	EyeIcon,
	DocumentDuplicateIcon,
	EllipsisHorizontalIcon,
	CheckIcon,
} from "@heroicons/react/24/outline";
import { PencilIcon, TrashIcon, StarIcon } from "@heroicons/react/24/outline";
import TaxProfileForm from "@/Pages/TaxProfiles/TaxProfileForm";
import TaxProfileDeleteConfirmation from "@/Pages/TaxProfiles/TaxProfileDeleteConfirmation";
import TaxProfileViewModal from "@/Pages/TaxProfiles/TaxProfileViewModal";
import TaxProfilesInfoPanel from "@/Pages/TaxProfiles/TaxProfilesInfoPanel";
import { useEffect, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import SettingsCard from "@/Components/SettingsCard";
import {
	Dropdown,
	DropdownButton,
	DropdownItem,
	DropdownLabel,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableRow,
	TableHeader,
} from "@/Components/Catalyst/table";
import {
	Pagination,
	PaginationGap,
	PaginationList,
	PaginationNext,
	PaginationPage,
	PaginationPrevious,
} from "@/Components/Catalyst/pagination";

export default function TaxProfiles({ taxProfiles, invoices }) {
	const taxProfileFormIsOpen =
		route().current("tax-profiles.create") ||
		route().current("tax-profiles.edit");

	const [taxProfileToDelete, setTaxProfileToDelete] = useState(null);
	const [taxProfileToView, setTaxProfileToView] = useState(null);

	return (
		<SettingsLayout title="Mis perfiles fiscales">
			<div className="space-y-6">
				<div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
					<div className="min-w-0 space-y-1">
						<div className="flex flex-wrap items-center gap-2">
							<GradientHeading noDivider className="mb-0">
								Mis perfiles fiscales
							</GradientHeading>
							{taxProfiles.length > 0 && (
								<Badge color="blue" className="whitespace-nowrap">
									{taxProfiles.length} perfil
									{taxProfiles.length !== 1 ? "es" : ""}
								</Badge>
							)}
						</div>
						<p className="max-w-xl text-sm text-slate-500 dark:text-slate-400">
							Administra los datos que utilizas para solicitar tus facturas.
						</p>
					</div>

					<Button
						dusk="createTaxProfile"
						preserveState
						preserveScroll
						href={route("tax-profiles.create")}
						className="w-full shrink-0 justify-center sm:w-auto"
					>
						<PlusIcon className="h-5 w-5" />
						Agregar perfil fiscal
					</Button>
				</div>

				<TaxProfilesInfoPanel />

				<Divider className="my-2" />

				<TaxProfilesList
					taxProfiles={taxProfiles}
					setTaxProfileToDelete={setTaxProfileToDelete}
					setTaxProfileToView={setTaxProfileToView}
				/>

				{invoices.data.length > 0 && (
					<>
						<Divider className="my-10" />

						<Subheading className="flex items-center gap-2">
							<DocumentTextIcon className="h-5 w-5" />
							Facturas recientes
						</Subheading>

						<Table className="my-4 [--gutter:theme(spacing.4)] lg:whitespace-normal">
							<TableHead>
								<TableRow>
									<TableHeader>Pedido</TableHeader>
									<TableHeader>Total</TableHeader>
									<TableHeader>Fecha</TableHeader>
									<TableHeader className="text-right">
										Factura
									</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{invoices.data.map((invoice) => {
									const isLabPurchase =
										invoice.invoiceable_type ===
										"App\\Models\\LaboratoryPurchase";

									return (
										<TableRow
											key={invoice.id}
											title={`Factura #${invoice.id}`}
										>
											<TableCell className="text-zinc-500">
												<Badge
													color="sky"
													className="mb-1 flex w-min items-center gap-1"
												>
													<QrCodeIcon className="size-4" />
													{
														invoice.invoiceable[
															isLabPurchase
																? "gda_order_id"
																: "vitau_order_id"
														]
													}
												</Badge>
												<br />

												{isLabPurchase
													? "Laboratorio"
													: "Farmacia"}
												<br />
											</TableCell>
											<TableCell>
												{
													invoice.invoiceable
														.formatted_total
												}
												<br />
												<span className="text-zinc-500">
													{isLabPurchase
														? invoice.invoiceable
																.laboratory_purchase_items
																.length +
															" estudios"
														: invoice.invoiceable
																.online_pharmacy_purchase_items
																.length +
															" productos"}
												</span>
											</TableCell>

											<TableCell>
												{
													invoice.invoiceable
														.formatted_created_at
												}
											</TableCell>

											<TableCell className="text-right">
												<div className="inline-flex flex-wrap justify-end gap-2">
													<a
														href={
															invoice.invoice_url ||
															route("invoice", {
																invoice: invoice,
															})
														}
														target="_blank"
													>
														<Button
															className="hidden dark:inline-flex"
															type="button"
															color="dark"
														>
															<DocumentTextIcon />
															Ver PDF
														</Button>
														<Button
															className="dark:hidden"
															type="button"
															color="white"
														>
															<DocumentTextIcon />
															Ver PDF
														</Button>
													</a>
													{(invoice.has_invoice_xml ||
														invoice.invoice_xml_url) && (
														<a
															href={
																invoice.invoice_xml_url ||
																route(
																	"invoice.xml",
																	{
																		invoice:
																			invoice,
																	},
																)
															}
															target="_blank"
														>
															<Button
																className="hidden dark:inline-flex"
																type="button"
																color="dark"
																outline
															>
																<DocumentTextIcon />
																Ver XML
															</Button>
															<Button
																className="dark:hidden"
																type="button"
																color="white"
																outline
															>
																<DocumentTextIcon />
																Ver XML
															</Button>
														</a>
													)}
												</div>
											</TableCell>
										</TableRow>
									);
								})}
							</TableBody>
						</Table>

						<InvoicesPagination invoices={invoices} />
					</>
				)}

				<TaxProfileForm isOpen={taxProfileFormIsOpen} />

				<TaxProfileDeleteConfirmation
					isOpen={!!taxProfileToDelete}
					close={() => setTaxProfileToDelete(null)}
					taxProfile={taxProfileToDelete}
				/>

				<TaxProfileViewModal
					isOpen={!!taxProfileToView}
					close={() => setTaxProfileToView(null)}
					taxProfile={taxProfileToView}
				/>
			</div>
		</SettingsLayout>
	);
}

function TaxProfilesList({
	taxProfiles,
	setTaxProfileToDelete,
	setTaxProfileToView,
}) {
	if (taxProfiles.length === 0) {
		return <TaxProfilesEmptyState />;
	}

	return (
		<div className="grid grid-cols-1 gap-4 md:grid-cols-2 md:gap-5">
			{taxProfiles.map((taxProfile) => (
				<TaxProfileCard
					key={taxProfile.id}
					taxProfile={taxProfile}
					onDelete={() => setTaxProfileToDelete(taxProfile)}
					onView={() => setTaxProfileToView(taxProfile)}
				/>
			))}
		</div>
	);
}

function TaxProfilesEmptyState() {
	return (
		<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50/60 px-6 py-12 text-center dark:border-slate-700 dark:bg-slate-900/40">
			<div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-300">
				<DocumentTextIcon className="h-7 w-7" aria-hidden />
			</div>
			<h3 className="text-base font-semibold text-slate-900 dark:text-white">
				Aún no tienes perfiles fiscales
			</h3>
			<p className="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
				Agrega tus datos fiscales para utilizarlos cuando solicites una factura.
			</p>
			<Button
				dusk="createFirstTaxProfile"
				preserveState
				preserveScroll
				href={route("tax-profiles.create")}
				className="mt-6"
			>
				<PlusIcon className="h-5 w-5" />
				Agregar perfil fiscal
			</Button>
		</div>
	);
}

function TaxProfileCard({ taxProfile, onDelete, onView }) {
	const isUsed = taxProfile.is_used === true;
	const isDefault = taxProfile.is_default === true;
	const certificateUrl = route("tax-profiles.fiscal-certificate", {
		tax_profile: taxProfile,
	});

	return (
		<SettingsCard className="h-full !max-w-none rounded-xl border border-slate-200/80 shadow-sm transition-shadow hover:shadow-md dark:border-slate-700/80">
			<div className="flex h-full min-h-0 flex-col gap-4">
				<div className="space-y-2">
					<div className="flex flex-wrap gap-2">
						{isDefault && <Badge color="emerald">Predeterminado</Badge>}
						{isUsed && <Badge color="zinc">Utilizado en facturación</Badge>}
					</div>

					<h3 className="line-clamp-2 break-words text-base font-semibold leading-snug text-slate-900 dark:text-white">
						{taxProfile.name}
					</h3>

					<div className="flex flex-wrap items-center gap-2">
						<span className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
							RFC
						</span>
						<Code className="text-sm">{taxProfile.rfc}</Code>
						<CopyRfcButton rfc={taxProfile.rfc} profileName={taxProfile.name} />
					</div>

					{isUsed && (
						<p
							id={`tax-profile-used-help-${taxProfile.id}`}
							className="text-xs text-zinc-600 dark:text-slate-400"
						>
							Este perfil fiscal ya fue utilizado en una solicitud de factura y
							no puede editarse.
						</p>
					)}
				</div>

				<dl className="space-y-2.5 border-t border-slate-100 pt-3 dark:border-slate-800">
					<TaxProfileDetailRow
						label="Código postal"
						value={taxProfile.zipcode ? `CP ${taxProfile.zipcode}` : null}
					/>
					<TaxProfileDetailRow
						label="Régimen fiscal"
						value={taxProfile.formatted_tax_regime}
					/>
					{taxProfile.formatted_cfdi_use && (
						<TaxProfileDetailRow
							label="Uso de CFDI"
							value={taxProfile.formatted_cfdi_use}
						/>
					)}
					{taxProfile.formatted_activity_label && (
						<TaxProfileDetailRow
							label="Actualización"
							value={taxProfile.formatted_activity_label}
							icon={ClockIcon}
						/>
					)}
				</dl>

				{taxProfile.verificado_automaticamente && (
					<div className="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
						<CheckCircleIcon className="h-4 w-4 shrink-0" aria-hidden />
						<span className="text-xs font-medium">
							Verificado automáticamente
						</span>
					</div>
				)}

				<div className="mt-auto flex flex-col gap-2 border-t border-slate-100 pt-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
					<div className="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center">
						{!isDefault && (
							<SetDefaultTaxProfileButton taxProfile={taxProfile} />
						)}
						{isUsed ? (
							<Button
								outline
								dusk={`viewTaxProfile-${taxProfile.id}`}
								type="button"
								onClick={onView}
								className="justify-center sm:w-auto"
								aria-label={`Ver datos del perfil fiscal ${taxProfile.name}`}
							>
								<EyeIcon className="h-4 w-4" />
								Ver datos
							</Button>
						) : (
							<Button
								outline
								type="button"
								dusk={`editTaxProfile-${taxProfile.id}`}
								className="justify-center sm:w-auto"
								aria-label={`Editar perfil fiscal ${taxProfile.name}`}
								onClick={() =>
									router.visit(
										route("tax-profiles.edit", {
											tax_profile: taxProfile.id,
										}),
										{
											preserveState: true,
											preserveScroll: true,
										},
									)
								}
							>
								<PencilIcon className="h-4 w-4" />
								Editar
							</Button>
						)}
					</div>

					<Dropdown>
						<DropdownButton
							plain
							aria-label={`Más acciones para ${taxProfile.name}`}
							className="justify-center sm:justify-start"
						>
							<EllipsisHorizontalIcon data-slot="icon" />
							<span className="sm:sr-only">Más acciones</span>
						</DropdownButton>
						<DropdownMenu anchor="bottom end">
							<DropdownItem href={certificateUrl} target="_blank" rel="noreferrer">
								<DocumentTextIcon data-slot="icon" />
								<DropdownLabel>Ver constancia</DropdownLabel>
							</DropdownItem>
							<DropdownItem
								dusk={`deactivateTaxProfile-${taxProfile.id}`}
								onClick={onDelete}
							>
								<TrashIcon data-slot="icon" className="stroke-red-500" />
								<DropdownLabel>Desactivar</DropdownLabel>
							</DropdownItem>
						</DropdownMenu>
					</Dropdown>
				</div>
			</div>
		</SettingsCard>
	);
}

function TaxProfileDetailRow({ label, value, icon: Icon }) {
	if (!value) {
		return null;
	}

	return (
		<div className="flex items-start justify-between gap-3 text-sm">
			<dt className="shrink-0 text-slate-500 dark:text-slate-400">{label}</dt>
			<dd className="min-w-0 text-right font-medium text-slate-900 dark:text-white">
				{Icon ? (
					<span className="inline-flex items-start justify-end gap-1.5">
						<Icon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />
						<span className="break-words">{value}</span>
					</span>
				) : (
					<span className="break-words">{value}</span>
				)}
			</dd>
		</div>
	);
}

function CopyRfcButton({ rfc, profileName }) {
	const [copied, setCopied] = useState(false);

	useEffect(() => {
		if (!copied) return undefined;
		const timer = setTimeout(() => setCopied(false), 2000);
		return () => clearTimeout(timer);
	}, [copied]);

	const handleCopy = async () => {
		if (!rfc) return;
		try {
			await navigator.clipboard.writeText(rfc);
			setCopied(true);
		} catch {
			setCopied(false);
		}
	};

	return (
		<span className="inline-flex items-center gap-1.5">
			<button
				type="button"
				onClick={handleCopy}
				aria-label={`Copiar RFC de ${profileName}`}
				className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:hover:bg-slate-800 dark:hover:text-slate-200"
			>
				{copied ? (
					<CheckIcon className="h-4 w-4 text-emerald-500" aria-hidden />
				) : (
					<DocumentDuplicateIcon className="h-4 w-4" aria-hidden />
				)}
			</button>
			<span className="sr-only" role="status" aria-live="polite">
				{copied ? "RFC copiado" : ""}
			</span>
			{copied && (
				<span className="text-xs font-medium text-emerald-600 dark:text-emerald-400" aria-hidden>
					Copiado
				</span>
			)}
		</span>
	);
}

function SetDefaultTaxProfileButton({ taxProfile }) {
	const { patch, processing } = useForm({});

	const handleSetDefault = () => {
		if (processing || taxProfile.is_default === true) {
			return;
		}

		patch(
			route("tax-profiles.set-default", {
				tax_profile: taxProfile.id,
			}),
			{
				preserveScroll: true,
			},
		);
	};

	return (
		<Button
			type="button"
			dusk={`setDefaultTaxProfile-${taxProfile.id}`}
			onClick={handleSetDefault}
			disabled={processing}
			aria-busy={processing}
			className="justify-center sm:w-auto"
			aria-label={`Usar ${taxProfile.name} como perfil predeterminado`}
		>
			<StarIcon className="h-4 w-4" />
			{processing ? "Actualizando…" : "Usar como predeterminado"}
		</Button>
	);
}

function InvoicesPagination({ invoices }) {
	return (
		<Pagination className="mt-4">
			<PaginationPrevious href={invoices.prev_page_url} />
			{invoices.links.length > 1 && (
				<PaginationList>
					{invoices.links.map((link, index) =>
						link.label === "..." ? (
							<PaginationGap key={`gap-${index}`} />
						) : (
							link.label !== "&laquo; Anterior" &&
							link.label !== "Siguiente &raquo;" && (
								<PaginationPage
									current={link.active}
									key={link.label}
									href={link.url}
								>
									{link.label}
								</PaginationPage>
							)
						),
					)}
				</PaginationList>
			)}
			<PaginationNext href={invoices.next_page_url} />
		</Pagination>
	);
}
