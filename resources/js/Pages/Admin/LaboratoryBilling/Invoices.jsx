import { useCallback, useState } from "react";
import { Link, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import EmptyListCard from "@/Components/EmptyListCard";
import UpdateButton from "@/Components/Admin/UpdateButton";
import BillingNav from "@/Components/Admin/LaboratoryBilling/BillingNav";
import BillingDateRangeFilter from "@/Components/Admin/LaboratoryBilling/BillingDateRangeFilter";
import { BillingDocumentStatus } from "@/Components/Admin/LaboratoryBilling/BillingStatusBadge";
import BillingLoadingBlock from "@/Components/Admin/LaboratoryBilling/BillingLoadingBlock";
import { billingMutedTextClass, billingSecondaryTextClass } from "@/Components/Admin/LaboratoryBilling/billingUi";

export default function Invoices({ invoices, filters = {} }) {
	const [rangeProcessing, setRangeProcessing] = useState(false);
	const onProcessingChange = useCallback(
		(value) => setRangeProcessing(value),
		[],
	);

	const form = useForm({
		from: filters.from || "",
		to: filters.to || "",
		search: filters.search || "",
		document: filters.document || "",
	});

	const apply = () => {
		const payload = Object.fromEntries(
			Object.entries(form.data).filter(
				([, value]) => value !== null && value !== undefined && value !== "",
			),
		);
		form.get(route("admin.laboratory-billing.invoices", payload), {
			preserveState: true,
			preserveScroll: true,
		});
	};

	const exportHref = route("admin.laboratory-billing.export.invoices", {
		...Object.fromEntries(
			Object.entries(filters).filter(
				([key, value]) =>
					!["formatted_from", "formatted_to"].includes(key) &&
					value !== null &&
					value !== "",
			),
		),
	});

	const processing = form.processing || rangeProcessing;

	return (
		<AdminLayout title="Facturación · Facturas">
			<div className="space-y-8">
				<div>
					<Heading>Facturación · Facturas</Heading>
					<Text className={`mt-1 ${billingMutedTextClass}`}>
						Finalización = primera vez con PDF + XML. La última actualización
						refleja reemplazos posteriores.
					</Text>
				</div>

				<BillingNav active="invoices" query={filters} />

				<BillingDateRangeFilter
					filters={filters}
					routeName="admin.laboratory-billing.invoices"
					extraParams={{
						search: form.data.search,
						document: form.data.document,
					}}
					exportHref={exportHref}
					onProcessingChange={onProcessingChange}
				>
					<div className="grid gap-4 md:grid-cols-3">
						<Field>
							<Label>Búsqueda</Label>
							<Input
								value={form.data.search}
								onChange={(e) => form.setData("search", e.target.value)}
								placeholder="Pedido, paciente, RFC…"
							/>
						</Field>
						<Field>
							<Label>Estado documental</Label>
							<Select
								value={form.data.document}
								onChange={(e) => form.setData("document", e.target.value)}
							>
								<option value="">Todos</option>
								<option value="complete">Completa</option>
								<option value="missing_pdf">Falta PDF</option>
								<option value="missing_xml">Falta XML</option>
								<option value="no_documents">Sin documentos</option>
							</Select>
						</Field>
						<div className="flex items-end">
							<UpdateButton
								type="button"
								processing={form.processing}
								onClick={apply}
							/>
						</div>
					</div>
				</BillingDateRangeFilter>

				<BillingLoadingBlock processing={processing}>
					{(invoices?.data || []).length === 0 ? (
						<EmptyListCard
							heading="Sin facturas"
							message="No hay documentos con los filtros actuales."
						/>
					) : (
						<PaginatedTable paginatedData={invoices}>
							<Table bleed>
								<TableHead>
									<TableRow>
										<TableHeader>Pedido</TableHeader>
										<TableHeader>Paciente</TableHeader>
										<TableHeader>RFC</TableHeader>
										<TableHeader>Razón social</TableHeader>
										<TableHeader>Solicitud</TableHeader>
										<TableHeader>Finalización</TableHeader>
										<TableHeader>Última actualización</TableHeader>
										<TableHeader>Respuesta</TableHeader>
										<TableHeader>Documentos</TableHeader>
										<TableHeader>Acciones</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{invoices.data.map((row) => (
										<TableRow key={row.id}>
											<TableCell>
												{row.purchase?.folio || row.purchase?.id || "—"}
											</TableCell>
											<TableCell className="!text-zinc-950 dark:!text-white">
												{row.patient_name || "—"}
											</TableCell>
											<TableCell>{row.snapshot?.rfc || "—"}</TableCell>
											<TableCell>{row.snapshot?.name || "—"}</TableCell>
											<TableCell>{row.formatted_requested_at || "—"}</TableCell>
											<TableCell>
												{row.invoice?.formatted_completed_at || "—"}
											</TableCell>
											<TableCell className={billingSecondaryTextClass}>
												{row.invoice?.formatted_updated_at || "—"}
											</TableCell>
											<TableCell>
												{row.billing?.response_time_hours != null
													? `${row.billing.response_time_hours} h`
													: "—"}
											</TableCell>
											<TableCell>
												<BillingDocumentStatus
													status={row.billing?.document_status}
													label={row.billing?.document_status_label}
													color={row.billing?.document_status_color}
													hasPdf={row.billing?.has_pdf}
													hasXml={row.billing?.has_xml}
												/>
											</TableCell>
											<TableCell>
												<div className="flex flex-col gap-1">
													{row.detail_url ? (
														<Button href={row.detail_url} plain>
															Ver pedido
														</Button>
													) : null}
													{row.invoice?.pdf_url ? (
														<Button href={row.invoice.pdf_url} plain>
															PDF
														</Button>
													) : null}
													{row.invoice?.xml_url ? (
														<Button href={row.invoice.xml_url} plain>
															XML
														</Button>
													) : null}
													{row.tax_profile?.show_url ? (
														<Link
															href={row.tax_profile.show_url}
															className="text-sm text-sky-700 hover:underline dark:text-sky-400"
														>
															Perfil fiscal
														</Link>
													) : null}
												</div>
											</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						</PaginatedTable>
					)}
				</BillingLoadingBlock>
			</div>
		</AdminLayout>
	);
}
