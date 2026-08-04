import { useCallback, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
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
import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import BillingDateRangeFilter from "@/Components/Admin/LaboratoryBilling/BillingDateRangeFilter";
import BillingTrendChart from "@/Components/Admin/LaboratoryBilling/BillingTrendChart";
import BillingComplianceChart from "@/Components/Admin/LaboratoryBilling/BillingComplianceChart";
import BillingStatusBadge from "@/Components/Admin/LaboratoryBilling/BillingStatusBadge";
import BillingPanel from "@/Components/Admin/LaboratoryBilling/BillingPanel";
import BillingPurchaseLink from "@/Components/Admin/LaboratoryBilling/BillingPurchaseLink";
import BillingLoadingBlock, {
	BillingMetricSkeleton,
} from "@/Components/Admin/LaboratoryBilling/BillingLoadingBlock";
import { billingMutedTextClass, billingSecondaryTextClass } from "@/Components/Admin/LaboratoryBilling/billingUi";
import {
	ClockIcon,
	DocumentCheckIcon,
	ExclamationTriangleIcon,
	QueueListIcon,
	UserPlusIcon,
	UsersIcon,
} from "@heroicons/react/16/solid";

export default function Dashboard({
	filters,
	thresholdDays,
	requestMetrics,
	taxProfileMetrics,
	compliance,
	requestsVsInvoices,
	newTaxProfiles,
	topOverdue,
	recentActivity,
}) {
	const [processing, setProcessing] = useState(false);
	const onProcessingChange = useCallback((value) => setProcessing(value), []);

	return (
		<AdminLayout title="Facturación">
			<div className="space-y-8">
				<div>
					<Heading>Facturación · Resumen</Heading>
					<Text className={`mt-1 ${billingMutedTextClass}`}>
						Umbral de atraso: {thresholdDays} días naturales
					</Text>
				</div>

				<BillingNav active="dashboard" query={filters} />

				<BillingDateRangeFilter
					filters={filters}
					routeName="admin.laboratory-billing.dashboard"
					onProcessingChange={onProcessingChange}
				/>

				<BillingLoadingBlock processing={processing}>
					<section aria-label="Solicitudes" className="space-y-3">
						<Subheading>Solicitudes</Subheading>
						{processing ? (
							<BillingMetricSkeleton count={4} />
						) : (
							<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
								<BillingMetricCard
									label="Pendientes"
									value={requestMetrics?.pending?.value ?? 0}
									deltaPercent={requestMetrics?.pending?.delta_percent}
									tone="amber"
									icon={QueueListIcon}
									help="Solicitudes sin factura y dentro del plazo."
								/>
								<BillingMetricCard
									label="En proceso"
									value={requestMetrics?.in_progress?.value ?? 0}
									deltaPercent={requestMetrics?.in_progress?.delta_percent}
									tone="sky"
									icon={ClockIcon}
									help="Factura incompleta (falta PDF o XML) y dentro del plazo."
								/>
								<BillingMetricCard
									label="Atrasadas"
									value={requestMetrics?.overdue?.value ?? 0}
									deltaPercent={requestMetrics?.overdue?.delta_percent}
									tone="red"
									icon={ExclamationTriangleIcon}
									help={`Sin factura completa después de ${thresholdDays} días naturales desde la solicitud.`}
								/>
								<BillingMetricCard
									label="Completadas"
									value={requestMetrics?.completed?.value ?? 0}
									deltaPercent={requestMetrics?.completed?.delta_percent}
									tone="lime"
									icon={DocumentCheckIcon}
									help="Solicitudes del periodo con completed_at (PDF + XML por primera vez)."
								/>
							</div>
						)}
					</section>

					<section aria-label="Perfiles fiscales" className="mt-8 space-y-3">
						<Subheading>Perfiles fiscales</Subheading>
						<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
							<BillingMetricCard
								label="Total"
								value={taxProfileMetrics?.total ?? 0}
								icon={UsersIcon}
							/>
							<BillingMetricCard
								label="Nuevos en el periodo"
								value={taxProfileMetrics?.new_in_period ?? 0}
								tone="sky"
								icon={UserPlusIcon}
							/>
							<BillingMetricCard
								label="Activos"
								value={taxProfileMetrics?.active ?? 0}
								tone="lime"
							/>
							<BillingMetricCard
								label="Sin uso"
								value={taxProfileMetrics?.unused ?? 0}
								tone="amber"
								help="Perfiles activos sin solicitudes de factura vinculadas."
							/>
						</div>
					</section>

					<div className="mt-8 grid gap-4 xl:grid-cols-2">
						<BillingTrendChart
							title="Solicitudes vs. facturas completadas"
							description={requestsVsInvoices?.definition}
							points={requestsVsInvoices?.points || []}
						/>
						<BillingComplianceChart compliance={compliance} />
					</div>

					<div className="mt-8">
						<BillingTrendChart
							title="Nuevos perfiles fiscales"
							description="Creación de perfiles en el periodo seleccionado."
							points={(newTaxProfiles?.points || []).map((point) => ({
								...point,
								requests: point.value,
								invoices_completed: 0,
							}))}
							series={[
								{ key: "requests", name: "Nuevos perfiles", color: "#6366f1" },
							]}
						/>
					</div>

					<div className="mt-8 grid gap-4 xl:grid-cols-2">
						<BillingPanel>
							<div className="mb-3 flex items-center justify-between gap-2">
								<Subheading>Top solicitudes con mayor retraso</Subheading>
								<Button
									href={route("admin.laboratory-billing.requests", {
										...filters,
										status: "overdue",
									})}
									plain
								>
									Ver todas
								</Button>
							</div>
							{(topOverdue || []).length === 0 ? (
								<Text className={`py-8 text-center ${billingMutedTextClass}`}>
									No hay solicitudes atrasadas en el periodo.
								</Text>
							) : (
								<Table dense>
									<TableHead>
										<TableRow>
											<TableHeader>Paciente</TableHeader>
											<TableHeader>Pedido</TableHeader>
											<TableHeader>Atraso</TableHeader>
											<TableHeader>Estado</TableHeader>
											<TableHeader>Total</TableHeader>
											<TableHeader>Acción</TableHeader>
										</TableRow>
									</TableHead>
									<TableBody>
										{topOverdue.map((row) => (
											<TableRow key={row.id}>
												<TableCell className="!text-zinc-950 dark:!text-white">
													{row.patient_name || "—"}
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
													{row.purchase?.formatted_total || "—"}
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

						<BillingPanel>
							<Subheading>Actividad reciente</Subheading>
							{(recentActivity || []).length === 0 ? (
								<Text className={`py-8 text-center ${billingMutedTextClass}`}>
									Sin actividad confiable en el periodo.
								</Text>
							) : (
								<ul className="mt-3 space-y-3">
									{recentActivity.map((event, index) => (
										<li
											key={`${event.type}-${event.at}-${index}`}
											className="border-b border-zinc-100 pb-3 last:border-0 dark:border-zinc-700/60"
										>
											<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
												{event.label}
											</p>
											<p className={billingSecondaryTextClass}>
												{event.formatted_at || "—"}
												{event.meta?.rfc ? ` · ${event.meta.rfc}` : ""}
												{event.meta?.name ? ` · ${event.meta.name}` : ""}
											</p>
										</li>
									))}
								</ul>
							)}
						</BillingPanel>
					</div>
				</BillingLoadingBlock>
			</div>
		</AdminLayout>
	);
}
