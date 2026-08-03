import { useCallback, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import BillingNav from "@/Components/Admin/LaboratoryBilling/BillingNav";
import BillingDateRangeFilter from "@/Components/Admin/LaboratoryBilling/BillingDateRangeFilter";
import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import BillingTrendChart from "@/Components/Admin/LaboratoryBilling/BillingTrendChart";
import BillingComplianceChart from "@/Components/Admin/LaboratoryBilling/BillingComplianceChart";
import BillingStatusBadge from "@/Components/Admin/LaboratoryBilling/BillingStatusBadge";
import BillingPanel from "@/Components/Admin/LaboratoryBilling/BillingPanel";
import BillingPurchaseLink from "@/Components/Admin/LaboratoryBilling/BillingPurchaseLink";
import BillingLoadingBlock from "@/Components/Admin/LaboratoryBilling/BillingLoadingBlock";
import {
	billingChartGridClass,
	billingChartUiClass,
	billingMutedTextClass,
	billingSecondaryTextClass,
} from "@/Components/Admin/LaboratoryBilling/billingUi";
import {
	ResponsiveContainer,
	PieChart,
	Pie,
	Cell,
	Tooltip,
	Legend,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	CartesianGrid,
} from "recharts";

const PIE_COLORS = ["#65a30d", "#0ea5e9", "#f59e0b", "#ef4444", "#a1a1aa"];

function ChartTooltip({ active, payload, label }) {
	if (!active || !payload?.length) return null;
	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-zinc-950/10 dark:bg-zinc-900 dark:ring-white/10">
			{label ? (
				<p className="font-semibold text-zinc-900 dark:text-zinc-50">{label}</p>
			) : null}
			{payload.map((entry) => (
				<p
					key={entry.name || entry.dataKey}
					className="text-zinc-600 dark:text-zinc-300"
				>
					{entry.name}: <Strong>{entry.value}</Strong>
				</p>
			))}
		</div>
	);
}

function SimplePie({ title, data }) {
	const hasData = (data || []).some((item) => Number(item.value || 0) > 0);

	return (
		<BillingPanel>
			<Subheading>{title}</Subheading>
			{!hasData ? (
				<Text className={`py-10 text-center ${billingMutedTextClass}`}>
					Sin datos suficientes.
				</Text>
			) : (
				<div className={`mt-4 h-64 ${billingChartUiClass}`}>
					<ResponsiveContainer width="100%" height="100%">
						<PieChart>
							<Pie
								data={data}
								dataKey="value"
								nameKey="label"
								outerRadius={90}
							>
								{(data || []).map((entry, index) => (
									<Cell
										key={entry.key || entry.label}
										fill={PIE_COLORS[index % PIE_COLORS.length]}
									/>
								))}
							</Pie>
							<Tooltip content={<ChartTooltip />} />
							<Legend />
						</PieChart>
					</ResponsiveContainer>
				</div>
			)}
		</BillingPanel>
	);
}

export default function Reports({
	filters,
	thresholdDays,
	summary,
	compliance,
	requestsVsInvoices,
	newTaxProfiles,
	profilesByTipoPersona,
	profilesByStatus,
	onTimeVsLate,
	topOverdue,
	unusedOldest,
	topPatients,
}) {
	const [processing, setProcessing] = useState(false);
	const onProcessingChange = useCallback((value) => setProcessing(value), []);

	return (
		<AdminLayout title="Facturación · Reportes">
			<div className="space-y-8">
				<div>
					<Heading>Facturación · Reportes</Heading>
					<Text className={`mt-1 ${billingMutedTextClass}`}>
						Umbral de atraso: {thresholdDays} días naturales
					</Text>
				</div>

				<BillingNav active="reports" query={filters} />

				<BillingDateRangeFilter
					filters={filters}
					routeName="admin.laboratory-billing.reports"
					onProcessingChange={onProcessingChange}
				/>

				<BillingLoadingBlock processing={processing}>
					<section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
						<BillingMetricCard
							label="Solicitudes recibidas"
							value={summary?.received ?? 0}
							help="Solicitudes con fecha de creación dentro del rango."
						/>
						<BillingMetricCard
							label="Facturas completadas"
							value={summary?.completed ?? 0}
							tone="lime"
							help="De la cohorte de solicitudes del rango, cuántas ya tienen completed_at."
						/>
						<BillingMetricCard
							label="Cumplimiento"
							value={`${summary?.compliance_percent ?? 0}%`}
							tone="sky"
							help={summary?.compliance_definition}
						/>
						<BillingMetricCard
							label="Atrasadas"
							value={summary?.overdue ?? 0}
							tone="red"
						/>
						<BillingMetricCard
							label="Tiempo promedio"
							value={
								summary?.average_response_hours != null
									? `${summary.average_response_hours} h`
									: "—"
							}
							help={summary?.response_time_definition}
						/>
						<BillingMetricCard
							label="Mediana de respuesta"
							value={
								summary?.median_response_hours != null
									? `${summary.median_response_hours} h`
									: "—"
							}
							help={summary?.response_time_definition}
						/>
						<BillingMetricCard
							label="Nuevos perfiles"
							value={summary?.new_tax_profiles ?? 0}
						/>
						<BillingMetricCard
							label="Perfiles activos / sin uso"
							value={`${summary?.active_tax_profiles ?? 0} / ${summary?.unused_tax_profiles ?? 0}`}
						/>
					</section>

					<div className="mt-8 grid gap-4 xl:grid-cols-2">
						<BillingTrendChart
							title="Solicitudes vs. facturas completadas"
							description={requestsVsInvoices?.definition}
							points={requestsVsInvoices?.points || []}
						/>
						<BillingComplianceChart
							compliance={
								compliance || {
									completed: summary?.completed,
									not_completed: Math.max(
										0,
										(summary?.received || 0) - (summary?.completed || 0),
									),
									percent: summary?.compliance_percent,
									received: summary?.received,
									definition: summary?.compliance_definition,
								}
							}
						/>
						<BillingTrendChart
							title="Nuevos perfiles fiscales"
							points={(newTaxProfiles?.points || []).map((point) => ({
								...point,
								requests: point.value,
								invoices_completed: 0,
							}))}
							series={[
								{ key: "requests", name: "Nuevos perfiles", color: "#6366f1" },
							]}
						/>
						<SimplePie
							title="Perfiles por tipo de persona"
							data={profilesByTipoPersona}
						/>
						<SimplePie title="Perfiles por estado" data={profilesByStatus} />
						<SimplePie title="Dentro / fuera de plazo" data={onTimeVsLate} />
						<BillingPanel>
							<Subheading>Tiempo promedio de respuesta</Subheading>
							<Text className={`mt-1 ${billingSecondaryTextClass}`}>
								{summary?.response_time_definition}
							</Text>
							<div
								className={`mt-4 h-64 ${billingChartUiClass} ${billingChartGridClass}`}
							>
								<ResponsiveContainer width="100%" height="100%">
									<BarChart
										data={[
											{
												label: "Promedio",
												hours: summary?.average_response_hours ?? 0,
											},
											{
												label: "Mediana",
												hours: summary?.median_response_hours ?? 0,
											},
										]}
									>
										<CartesianGrid strokeDasharray="3 3" vertical={false} />
										<XAxis dataKey="label" tick={{ fontSize: 11 }} />
										<YAxis tick={{ fontSize: 11 }} />
										<Tooltip content={<ChartTooltip />} />
										<Bar dataKey="hours" name="Horas" fill="#0ea5e9" />
									</BarChart>
								</ResponsiveContainer>
							</div>
						</BillingPanel>
					</div>

					<div className="mt-8 grid gap-4 xl:grid-cols-3">
						<BillingPanel>
							<Subheading>Mayor atraso</Subheading>
							{(topOverdue || []).length === 0 ? (
								<Text className={`mt-3 ${billingMutedTextClass}`}>Sin datos.</Text>
							) : (
								<ul className="mt-3 space-y-2 text-sm text-zinc-900 dark:text-zinc-100">
									{topOverdue.map((row) => (
										<li key={row.id} className="flex justify-between gap-2">
											<span>{row.patient_name}</span>
											<span
												className={`tabular-nums ${billingSecondaryTextClass}`}
											>
												{row.billing?.days_overdue ?? 0} d
											</span>
										</li>
									))}
								</ul>
							)}
						</BillingPanel>

						<BillingPanel>
							<Subheading>Perfiles sin uso más antiguos</Subheading>
							{(unusedOldest || []).length === 0 ? (
								<Text className={`mt-3 ${billingMutedTextClass}`}>Sin datos.</Text>
							) : (
								<ul className="mt-3 space-y-2 text-sm">
									{unusedOldest.map((row) => (
										<li key={row.id}>
											<p className="font-medium text-zinc-900 dark:text-zinc-50">
												{row.razon_social || row.name}
											</p>
											<p className={billingSecondaryTextClass}>
												{row.rfc} · {row.formatted_created_at || "—"}
											</p>
										</li>
									))}
								</ul>
							)}
						</BillingPanel>

						<BillingPanel>
							<Subheading>Pacientes con más solicitudes</Subheading>
							{(topPatients || []).length === 0 ? (
								<Text className={`mt-3 ${billingMutedTextClass}`}>Sin datos.</Text>
							) : (
								<ul className="mt-3 space-y-2 text-sm text-zinc-900 dark:text-zinc-100">
									{topPatients.map((row) => (
										<li
											key={row.purchase_id}
											className="flex justify-between gap-2"
										>
											<span>{row.patient_name}</span>
											<span
												className={`tabular-nums ${billingSecondaryTextClass}`}
											>
												{row.total_requests}
											</span>
										</li>
									))}
								</ul>
							)}
						</BillingPanel>
					</div>

					<div className="mt-8">
						<BillingPanel>
							<Subheading>Detalle de atrasos</Subheading>
							{(topOverdue || []).length === 0 ? (
								<Text className={`mt-3 ${billingMutedTextClass}`}>
									Sin atrasos.
								</Text>
							) : (
								<Table dense className="mt-3">
									<TableHead>
										<TableRow>
											<TableHeader>Paciente</TableHeader>
											<TableHeader>Pedido</TableHeader>
											<TableHeader>Atraso</TableHeader>
											<TableHeader>Estado</TableHeader>
											<TableHeader>Acción</TableHeader>
										</TableRow>
									</TableHead>
									<TableBody>
										{topOverdue.map((row) => (
											<TableRow key={`detail-${row.id}`}>
												<TableCell className="!text-zinc-950 dark:!text-white">
													{row.patient_name}
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
													{row.billing?.days_overdue ?? "—"} d
												</TableCell>
												<TableCell>
													<BillingStatusBadge
														status={row.billing?.status}
														label={row.billing?.status_label}
														color={row.billing?.status_color}
													/>
												</TableCell>
												<TableCell>
													{row.detail_url ? (
														<Button href={row.detail_url} plain>
															Ver
														</Button>
													) : (
														"—"
													)}
												</TableCell>
											</TableRow>
										))}
									</TableBody>
								</Table>
							)}
						</BillingPanel>
					</div>
				</BillingLoadingBlock>
			</div>
		</AdminLayout>
	);
}
