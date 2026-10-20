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
import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import TaxProfileStatusBadge from "@/Components/Admin/LaboratoryBilling/TaxProfileStatusBadge";
import BillingLoadingBlock from "@/Components/Admin/LaboratoryBilling/BillingLoadingBlock";
import {
	billingMutedTextClass,
	billingSecondaryTextClass,
} from "@/Components/Admin/LaboratoryBilling/billingUi";

function splitDateTime(value) {
	if (!value || value === "—") {
		return { date: "—", time: null };
	}

	const match = String(value).match(/^(.+?\d{4})\s+(.+)$/);

	return match
		? { date: match[1], time: match[2] }
		: { date: value, time: null };
}

function DateTimeStack({ value }) {
	const { date, time } = splitDateTime(value);

	return (
		<div className="leading-5">
			<p>{date}</p>
			{time && <p className={billingSecondaryTextClass}>{time}</p>}
		</div>
	);
}

export default function TaxProfiles({
	taxProfiles,
	filters = {},
	metrics = {},
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
		status: filters.status || "",
		usage: filters.usage || "",
		include_deleted: filters.include_deleted || "",
		created_in_range: filters.created_in_range || "",
	});

	const apply = () => {
		const payload = Object.fromEntries(
			Object.entries(form.data).filter(
				([, value]) =>
					value !== null && value !== undefined && value !== "",
			),
		);
		form.get(
			route("admin.laboratory-billing.tax-profiles.index", payload),
			{
				preserveState: true,
				preserveScroll: true,
			},
		);
	};

	const exportHref = route("admin.laboratory-billing.export.tax-profiles", {
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
		<AdminLayout title="Facturación · Perfiles fiscales">
			<div className="space-y-8">
				<div>
					<Heading>Facturación · Perfiles fiscales</Heading>
					<Text className={`mt-1 ${billingMutedTextClass}`}>
						Perfiles fiscales vinculados a solicitudes de
						laboratorio
					</Text>
				</div>

				<BillingNav
					active="tax-profiles"
					query={filters}
					canManageAutomaticReports={canManageAutomaticReports}
					navCounts={navCounts}
				/>

				<section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
					<BillingMetricCard
						label="Total"
						value={metrics.total ?? 0}
					/>
					<BillingMetricCard
						label="Nuevos en el periodo"
						value={metrics.new_in_period ?? 0}
						tone="sky"
					/>
					<BillingMetricCard
						label="Activos"
						value={metrics.active ?? 0}
						tone="lime"
					/>
					<BillingMetricCard
						label="Sin uso"
						value={metrics.unused ?? 0}
						tone="amber"
					/>
				</section>

				<BillingDateRangeFilter
					filters={filters}
					routeName="admin.laboratory-billing.tax-profiles.index"
					extraParams={{
						search: form.data.search,
						status: form.data.status,
						usage: form.data.usage,
						include_deleted: form.data.include_deleted,
						created_in_range: form.data.created_in_range,
					}}
					exportHref={exportHref}
					onProcessingChange={onProcessingChange}
				>
					<div className="grid gap-4 md:grid-cols-3">
						<Field>
							<Label>Búsqueda</Label>
							<Input
								value={form.data.search}
								onChange={(e) =>
									form.setData("search", e.target.value)
								}
								placeholder="RFC, razón social, paciente…"
							/>
						</Field>
						<Field>
							<Label>Estado</Label>
							<Select
								value={form.data.status}
								onChange={(e) =>
									form.setData("status", e.target.value)
								}
							>
								<option value="">Todos</option>
								<option value="active">Activos</option>
								<option value="deleted">Eliminados</option>
							</Select>
						</Field>
						<Field>
							<Label>Uso</Label>
							<Select
								value={form.data.usage}
								onChange={(e) =>
									form.setData("usage", e.target.value)
								}
							>
								<option value="">Todos</option>
								<option value="used">Con uso</option>
								<option value="unused">Sin uso</option>
							</Select>
						</Field>
						<div className="flex items-end md:col-span-3">
							<UpdateButton
								type="button"
								processing={form.processing}
								onClick={apply}
							/>
						</div>
					</div>
				</BillingDateRangeFilter>

				<BillingLoadingBlock processing={processing}>
					{(taxProfiles?.data || []).length === 0 ? (
						<EmptyListCard
							heading="Sin perfiles"
							message="No hay perfiles fiscales con los filtros actuales."
						/>
					) : (
						<PaginatedTable paginatedData={taxProfiles}>
							<Table bleed dense wrap>
								<TableHead>
									<TableRow>
										<TableHeader>Persona / RFC</TableHeader>
										<TableHeader>Paciente</TableHeader>
										<TableHeader>Actualización</TableHeader>
										<TableHeader>Último uso</TableHeader>
										<TableHeader>Solicitudes</TableHeader>
										<TableHeader>Estado</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{taxProfiles.data.map((row) => (
										<TableRow
											key={row.id}
											href={row.show_url || undefined}
											title={`Perfil fiscal ${row.rfc || row.id}`}
											className={
												row.show_url
													? "cursor-pointer"
													: ""
											}
										>
											<TableCell className="!text-zinc-950 dark:!text-white">
												<div className="min-w-0">
													<p className="font-medium">
														{row.razon_social ||
															row.name ||
															"—"}
													</p>
													<p
														className={`mt-1 font-mono text-xs ${billingSecondaryTextClass}`}
													>
														{row.rfc || "—"}
													</p>
												</div>
											</TableCell>
											<TableCell>
												<div>
													<p>
														{row.customer?.name ||
															"—"}
													</p>
													<p
														className={
															billingSecondaryTextClass
														}
													>
														{row.customer?.email ||
															""}
													</p>
												</div>
											</TableCell>
											<TableCell>
												<DateTimeStack
													value={
														row.formatted_updated_at
													}
												/>
											</TableCell>
											<TableCell>
												<DateTimeStack
													value={
														row.formatted_last_used_at
													}
												/>
											</TableCell>
											<TableCell>
												{row.invoice_requests_count ??
													0}
											</TableCell>
											<TableCell>
												<TaxProfileStatusBadge
													isActive={row.is_active}
													isDefault={row.is_default}
													usageStatus={
														row.usage_status
													}
												/>
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
