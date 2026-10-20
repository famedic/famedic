import { useCallback, useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
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
import BillingPurchaseLink from "@/Components/Admin/LaboratoryBilling/BillingPurchaseLink";
import BillingLoadingBlock from "@/Components/Admin/LaboratoryBilling/BillingLoadingBlock";
import { WorkflowStatusBadge } from "@/Components/Admin/LaboratoryBilling/BillingStatusBadge";
import { billingMutedTextClass, billingSecondaryTextClass } from "@/Components/Admin/LaboratoryBilling/billingUi";

export default function AwaitingSample({
	requests,
	filters = {},
	brandOptions = [],
	canManageAutomaticReports = false,
	navCounts = {},
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
		brand: filters.brand || "",
	});

	const apply = (overrides = {}) => {
		const payload = Object.fromEntries(
			Object.entries({ ...form.data, ...overrides }).filter(
				([, value]) => value !== null && value !== undefined && value !== "",
			),
		);
		form.get(route("admin.laboratory-billing.awaiting-sample", payload), {
			preserveState: true,
			preserveScroll: true,
		});
	};

	const processing = form.processing || rangeProcessing;

	return (
		<AdminLayout title="Facturación · Esperando toma">
			<div className="space-y-8">
				<div>
					<Heading>Facturación · Esperando toma</Heading>
					<Text className={`mt-1 ${billingMutedTextClass}`}>
						Solicitudes registradas que aún esperan toma de muestra o resultado antes de
						ingresar a facturación.
					</Text>
				</div>

				<BillingNav
					active="awaiting-sample"
					query={filters}
					canManageAutomaticReports={canManageAutomaticReports}
					navCounts={navCounts}
				/>

				<BillingDateRangeFilter
					filters={filters}
					routeName="admin.laboratory-billing.awaiting-sample"
					extraParams={{
						search: form.data.search,
						brand: form.data.brand,
					}}
					showFiltersToggle
					onProcessingChange={onProcessingChange}
				>
					<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
						<Field>
							<Label>Búsqueda</Label>
							<Input
								value={form.data.search}
								onChange={(e) => form.setData("search", e.target.value)}
								placeholder="Paciente, email, pedido, RFC…"
							/>
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
							heading="Sin solicitudes en espera"
							message="No hay solicitudes esperando toma o resultado con los filtros actuales."
						/>
					) : (
						<PaginatedTable paginatedData={requests}>
							<Table bleed>
								<TableHead>
									<TableRow>
										<TableHeader>Solicitud</TableHeader>
										<TableHeader>Compra</TableHeader>
										<TableHeader>Paciente</TableHeader>
										<TableHeader>Fecha de solicitud</TableHeader>
										<TableHeader>Estudios</TableHeader>
										<TableHeader>Estado de muestra</TableHeader>
										<TableHeader>Resultado</TableHeader>
										<TableHeader>Última actividad</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{requests.data.map((row) => (
										<TableRow key={row.id}>
											<TableCell>
												<div className="space-y-1">
													<p className="font-medium !text-zinc-950 dark:!text-white">
														#{row.id}
													</p>
													<WorkflowStatusBadge status="awaiting_sample_collection" />
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
											<TableCell>{row.formatted_requested_at || "—"}</TableCell>
											<TableCell>{row.studies_count ?? "—"}</TableCell>
											<TableCell>
												{row.sample_collection?.label || "—"}
											</TableCell>
											<TableCell>
												{row.result_availability?.label || "—"}
											</TableCell>
											<TableCell>{row.last_activity_at || "—"}</TableCell>
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
