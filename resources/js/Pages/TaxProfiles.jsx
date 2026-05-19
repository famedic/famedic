import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Code, Text } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import {
	DocumentTextIcon,
	PlusIcon,
	QrCodeIcon,
	CheckCircleIcon,
	UserIcon,
	ClockIcon,
} from "@heroicons/react/24/outline";
import { PencilIcon, TrashIcon } from "@heroicons/react/24/outline";
import TaxProfileForm from "@/Pages/TaxProfiles/TaxProfileForm";
import TaxProfileDeleteConfirmation from "@/Pages/TaxProfiles/TaxProfileDeleteConfirmation";
import TaxProfilesInfoPanel from "@/Pages/TaxProfiles/TaxProfilesInfoPanel";
import { useState } from "react";
import SettingsCard from "@/Components/SettingsCard";
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

	return (
		<SettingsLayout title="Mis perfiles fiscales">
			<div className="space-y-6">
				<div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
					<div className="flex items-center gap-3">
						<GradientHeading noDivider className="mb-0">
							Mis perfiles fiscales
						</GradientHeading>
						<Badge color="blue" className="whitespace-nowrap">
							{taxProfiles.length} perfil{taxProfiles.length !== 1 ? 'es' : ''}
						</Badge>
					</div>
					
					<div className="flex items-center gap-3">
						<Button
							dusk="createTaxProfile"
							preserveState
							preserveScroll
							href={route("tax-profiles.create")}
							className="flex items-center gap-2 whitespace-nowrap"
						>
							<PlusIcon className="h-5 w-5" />
							Nuevo perfil
						</Button>
					</div>
				</div>

				<TaxProfilesInfoPanel />

				{/* Botón de agregar perfil - Ahora después de las recomendaciones */}
				<div className="flex justify-center my-4">
					<Button
						dusk="createTaxProfileMain"
						preserveState
						preserveScroll
						href={route("tax-profiles.create")}
						className="flex items-center gap-2 px-6 py-3 text-base"
					>
						<PlusIcon className="h-5 w-5" />
						Agregar nuevo perfil fiscal
					</Button>
				</div>

				<Divider className="my-6" />

				{/* Lista de perfiles fiscales */}
				<div className="mb-8">
					<Subheading className="mb-4 flex items-center gap-2">
						<UserIcon className="h-5 w-5" />
						Tus perfiles fiscales
					</Subheading>
					
					<TaxProfilesList
						taxProfiles={taxProfiles}
						setTaxProfileToDelete={setTaxProfileToDelete}
					/>
				</div>

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
												<a
													href={route("invoice", {
														invoice: invoice,
													})}
													target="_blank"
												>
													<Button
														className="hidden dark:inline-flex"
														type="button"
														color="dark"
													>
														<DocumentTextIcon />
														Ver factura
													</Button>
													<Button
														className="dark:hidden"
														type="button"
														color="white"
													>
														<DocumentTextIcon />
														Ver factura
													</Button>
												</a>
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
			</div>
		</SettingsLayout>
	);
}

function TaxProfilesList({ taxProfiles, setTaxProfileToDelete }) {
	return (
		<div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
			{taxProfiles.map((taxProfile) => (
					<SettingsCard
						key={taxProfile.id}
						className="h-full !max-w-none rounded-xl shadow-sm transition-shadow hover:shadow-md"
						actions={
							<div className="flex gap-2 mt-4">
								<Button
									dusk={`deleteTaxProfile-${taxProfile.id}`}
									onClick={() =>
										setTaxProfileToDelete(taxProfile)
									}
									outline
									className="flex-1 justify-center"
								>
									<TrashIcon className="h-4 w-4 stroke-red-400 mr-2" />
									Eliminar
								</Button>
								<Button
									outline
									dusk={`editTaxProfile-${taxProfile.id}`}
									preserveState
									preserveScroll
									href={route("tax-profiles.edit", {
										tax_profile: taxProfile,
									})}
									className="flex-1 justify-center"
								>
									<PencilIcon className="h-4 w-4 mr-2" />
									Editar
								</Button>
							</div>
						}
					>
						<div className="space-y-4">
							<div>
								<Subheading className="mb-1 line-clamp-1">
									{taxProfile.name}
								</Subheading>
								<Code className="text-sm">{taxProfile.rfc}</Code>
								{taxProfile.formatted_activity_label && (
									<p className="mt-2 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
										<ClockIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
										<span>{taxProfile.formatted_activity_label}</span>
									</p>
								)}
							</div>
							
							<div className="space-y-2">
								<div className="flex items-center justify-between">
									<span className="text-sm text-gray-600 dark:text-slate-400">Código Postal:</span>
									<span className="font-medium text-zinc-900 dark:text-white">
										CP {taxProfile.zipcode}
									</span>
								</div>
								
								<div className="flex items-center justify-between">
									<span className="text-sm text-gray-600">Tipo Persona:</span>
									{taxProfile.tipo_persona === 'fisica' ? (
										<Badge color="green" className="text-xs">
											Persona Física
										</Badge>
									) : taxProfile.tipo_persona === 'moral' ? (
										<Badge color="red" className="text-xs">
											Persona Moral
										</Badge>
									) : (
										<Badge color="gray" className="text-xs">
											No especificado
										</Badge>
									)}
								</div>
								
								<div className="pt-2 border-t border-gray-100">
									<p className="text-sm text-gray-600 mb-1">Régimen Fiscal:</p>
									<Badge color="slate" className="w-full justify-center text-sm py-1.5">
										{taxProfile.formatted_tax_regime}
									</Badge>
								</div>
								
								<div className="pt-2">
									<p className="text-sm text-gray-600 mb-1">Uso CFDI:</p>
									<Badge color="slate" className="w-full justify-center text-sm py-1.5">
										{taxProfile.formatted_cfdi_use}
									</Badge>
								</div>
								
								{taxProfile.verificado_automaticamente && (
									<div className="pt-3 mt-3 border-t border-green-100">
										<div className="flex items-center gap-2 text-green-600">
											<CheckCircleIcon className="h-4 w-4" />
											<span className="text-xs font-medium">
												Verificado automáticamente
											</span>
										</div>
									</div>
								)}
							</div>
							
							<div className="pt-4">
								<a
									href={route("tax-profiles.fiscal-certificate", {
										tax_profile: taxProfile,
									})}
									target="_blank"
									className="block"
								>
									<Button type="button" outline className="w-full justify-center">
										<DocumentTextIcon className="h-4 w-4 mr-2" />
										Ver constancia fiscal
									</Button>
								</a>
							</div>
						</div>
					</SettingsCard>
			))}

			{taxProfiles.length === 0 && (
				<div className="col-span-1 md:col-span-2 lg:col-span-3">
					<SettingsCard>
						<div className="text-center py-8">
							<div className="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-4">
								<UserIcon className="h-8 w-8 text-gray-400" />
							</div>
							<Subheading className="mb-2">
								Sin perfiles fiscales
							</Subheading>
							<Text className="text-gray-500 mb-6">
								Aún no has agregado ningún perfil fiscal. 
								Agrega tu primer perfil para poder facturar tus compras.
							</Text>
							<Button
								dusk="createFirstTaxProfile"
								preserveState
								preserveScroll
								href={route("tax-profiles.create")}
								className="mx-auto"
							>
								<PlusIcon className="h-5 w-5 mr-2" />
								Agregar mi primer perfil
							</Button>
						</div>
					</SettingsCard>
				</div>
			)}
		</div>
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