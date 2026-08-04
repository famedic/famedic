import { useCallback, useState } from "react";
import { Link, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
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
import BillingStatusBadge, {
	BillingDocumentStatus,
} from "@/Components/Admin/LaboratoryBilling/BillingStatusBadge";
import BillingPurchaseLink from "@/Components/Admin/LaboratoryBilling/BillingPurchaseLink";
import BillingLoadingBlock from "@/Components/Admin/LaboratoryBilling/BillingLoadingBlock";
import { billingMutedTextClass, billingSecondaryTextClass } from "@/Components/Admin/LaboratoryBilling/billingUi";

const STATUS_TABS = [
	{ key: "", label: "Todas", countKey: "all" },
	{ key: "pending", label: "Pendientes", countKey: "pending" },
	{ key: "in_progress", label: "En proceso", countKey: "in_progress" },
	{ key: "completed", label: "Completadas", countKey: "completed" },
	{ key: "overdue", label: "Atrasadas", countKey: "overdue" },
];

export default function Requests({
	requests,
	filters = {},
	statusCounts = {},
	brandOptions = [],
	thresholdDays,
}) {
	const [rangeProcessing, setRangeProcessing] = useState(false);
	const onProcessingChange = useCallback(
		(value) => setRangeProcessing(value),
		[],
	);

	const form = useForm({
		from: filters.from || "",
		to: filters.to || "",
		search: filters.search || "",
		status: filters.status || "",
		document: filters.document || "",
		brand: filters.brand || "",
	});

	const apply = (overrides = {}) => {
		const payload = Object.fromEntries(
			Object.entries({ ...form.data, ...overrides }).filter(
				([, value]) => value !== null && value !== undefined && value !== "",
			),
		);
		form.get(route("admin.laboratory-billing.requests", payload), {
			preserveState: true,
			preserveScroll: true,
		});
	};

	const exportHref = route("admin.laboratory-billing.export.requests", {
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
		<AdminLayout title="Facturación · Solicitudes">
			<div className="space-y-8">
				<div>
					<Heading>Facturación · Solicitudes</Heading>
					<Text className={`mt-1 ${billingMutedTextClass}`}>
						Umbral de atraso: {thresholdDays} días naturales
					</Text>
				</div>

				<BillingNav active="requests" query={filters} />

				<div className="flex flex-wrap gap-2">
					{STATUS_TABS.map((tab) => {
						const active = (filters.status || "") === tab.key;
						return (
							<Button
								key={tab.key || "all"}
								type="button"
								outline={!active}
								disabled={processing}
								onClick={() => {
									form.setData("status", tab.key);
									apply({ status: tab.key });
								}}
							>
								{tab.label}
								<Badge className="ml-2" color={active ? "lime" : "zinc"}>
									{statusCounts[tab.countKey] ?? 0}
								</Badge>
							</Button>
						);
					})}
				</div>

				<BillingDateRangeFilter
					filters={filters}
					routeName="admin.laboratory-billing.requests"
					extraParams={{
						search: form.data.search,
						status: form.data.status,
						document: form.data.document,
						brand: form.data.brand,
					}}
					showFiltersToggle
					exportHref={exportHref}
					onProcessingChange={onProcessingChange}
				>
					<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
						<Field>
							<Label>Búsqueda</Label>
							<Input
								value={form.data.search}
								onChange={(e) => form.setData("search", e.target.value)}
								placeholder="Paciente, email, pedido, RFC…"
							/>
						</Field>
						<Field>
							<Label>Documentos</Label>
							<Select
								value={form.data.document}
								onChange={(e) => form.setData("document", e.target.value)}
							>
								<option value="">Todos</option>
								<option value="complete">Factura completa</option>
								<option value="incomplete">Factura incompleta</option>
								<option value="with_pdf">Con PDF</option>
								<option value="without_pdf">Sin PDF</option>
								<option value="with_xml">Con XML</option>
								<option value="without_xml">Sin XML</option>
							</Select>
						</Field>
						<Field>
							<Label>Marca</Label>
							<Select
								value={form.data.brand}
								onChange={(e) => form.setData("brand", e.target.value)}
							>
								<option value="">Todas</option>
								{brandOptions.map((brand) => (
									<option key={brand.value} value={brand.value}>
										{brand.label}
									</option>
								))}
							</Select>
						</Field>
						<div className="flex items-end">
							<UpdateButton
								type="button"
								processing={form.processing}
								onClick={() => apply()}
							/>
						</div>
					</div>
				</BillingDateRangeFilter>

				<BillingLoadingBlock processing={processing}>
					{(requests?.data || []).length === 0 ? (
						<EmptyListCard
							heading="Sin solicitudes"
							message="No hay solicitudes con los filtros actuales."
						/>
					) : (
						<PaginatedTable paginatedData={requests}>
							<Table bleed>
								<TableHead>
									<TableRow>
										<TableHeader>Paciente</TableHeader>
										<TableHeader>Pedido</TableHeader>
										<TableHeader>Solicitud</TableHeader>
										<TableHeader>Días</TableHeader>
										<TableHeader>Atraso</TableHeader>
										<TableHeader>Estado</TableHeader>
										<TableHeader>Total</TableHeader>
										<TableHeader>Perfil fiscal</TableHeader>
										<TableHeader>Docs</TableHeader>
										<TableHeader>Acciones</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{requests.data.map((row) => (
										<TableRow key={row.id}>
											<TableCell>
												<div>
													<p className="font-medium !text-zinc-950 dark:!text-white">
														{row.patient_name || "—"}
													</p>
													<p className={billingSecondaryTextClass}>
														{row.customer_email || "—"}
													</p>
												</div>
											</TableCell>
											<TableCell>
												<BillingPurchaseLink
													href={row.detail_url || row.purchase?.show_url}
													label={
														row.purchase?.folio || row.purchase?.id || null
													}
												/>
											</TableCell>
											<TableCell>{row.formatted_requested_at || "—"}</TableCell>
											<TableCell>{row.billing?.days_elapsed ?? "—"}</TableCell>
											<TableCell>{row.billing?.days_overdue ?? "—"}</TableCell>
											<TableCell>
												<BillingStatusBadge
													status={row.billing?.status}
													label={row.billing?.status_label}
													color={row.billing?.status_color}
												/>
											</TableCell>
											<TableCell>
												{row.purchase?.formatted_total || "—"}
											</TableCell>
											<TableCell>
												{row.tax_profile ? (
													<Link
														href={row.tax_profile.show_url}
														className="text-sm text-sky-700 hover:underline dark:text-sky-400"
													>
														{row.tax_profile.rfc || row.tax_profile.name}
													</Link>
												) : (
													"—"
												)}
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
												{row.detail_url ? (
													<Button href={row.detail_url} plain>
														Ver detalle
													</Button>
												) : (
													"—"
												)}
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
