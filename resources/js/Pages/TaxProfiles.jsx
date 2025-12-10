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
} from "@heroicons/react/16/solid";
import { PencilIcon, TrashIcon } from "@heroicons/react/24/outline";
import TaxProfileForm from "@/Pages/TaxProfiles/TaxProfileForm";
import TaxProfileDeleteConfirmation from "@/Pages/TaxProfiles/TaxProfileDeleteConfirmation";
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
			<div className="flex flex-wrap items-center justify-between gap-4">
				<GradientHeading noDivider>
					Mis perfiles fiscales
				</GradientHeading>
				<span className="text-zinc-500">	
					Aquí puedes administrar tus perfiles fiscales 
					Sube tu constancia de situación fiscal para emición de facturas correctamente.
					<br></br>
					*Solo se aceptan archivos en formato PDF.
					<br></br>
					**Recuerda que puedes tener varios perfiles fiscales.
					<br></br>
					***Solo se aceptan RFC tipo Persona Física.
					<br></br>
					Para más información sobre cómo obtener tu constancia de situación fiscal, visita el sitio web del SAT.
				</span>
				<Button
					dusk="createTaxProfile"
					preserveState
					preserveScroll
					href={route("tax-profiles.create")}
				>
					<PlusIcon />
					Agregar perfil
				</Button>
			</div>

			<Divider className="my-10 mt-6" />

			<TaxProfilesList
				taxProfiles={taxProfiles}
				setTaxProfileToDelete={setTaxProfileToDelete}
			/>

			{invoices.data.length > 0 && (
				<>
					<Divider className="my-10" />

					<Subheading>Facturas recientes</Subheading>

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
		</SettingsLayout>
	);
}

function TaxProfilesList({ taxProfiles, setTaxProfileToDelete }) {
	return (
		<ul className="flex flex-wrap gap-8">
			{taxProfiles.map((taxProfile) => (
				<SettingsCard
					key={taxProfile.id}
					actions={
						<>
							<Button
								dusk={`deleteTaxProfile-${taxProfile.id}`}
								onClick={() =>
									setTaxProfileToDelete(taxProfile)
								}
								outline
							>
								<TrashIcon className="stroke-red-400" />
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
							>
								<PencilIcon />
								Editar
							</Button>
						</>
					}
				>
					<Subheading>{taxProfile.name}</Subheading>
					<Code>{taxProfile.rfc}</Code>
					<Text className="mb-3">CP {taxProfile.zipcode}</Text>
					<Badge color="slate" className="mb-1 max-w-60">
						<span className="line-clamp-1">
							{taxProfile.formatted_tax_regime}
						</span>
					</Badge>
					<br />
					<Badge color="slate" className="max-w-60">
						{taxProfile.formatted_cfdi_use}
					</Badge>
					<br />
					<a
						href={route("tax-profiles.fiscal-certificate", {
							tax_profile: taxProfile,
						})}
						target="_blank"
					>
						<Button className="my-4" type="button" outline>
							<DocumentTextIcon />
							Ver constancia
						</Button>
					</a>
				</SettingsCard>
			))}

			{taxProfiles.length === 0 && (
				<SettingsCard>
					<Subheading className="mb-2">
						Sin perfiles fiscales
					</Subheading>
					<Text>Aún no has agregado ningun perfil fiscal.</Text>
				</SettingsCard>
			)}
		</ul>
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
