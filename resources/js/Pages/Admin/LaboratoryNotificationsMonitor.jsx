import { Fragment, useEffect, useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from "@headlessui/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { BadgeButton } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import SearchInput from "@/Components/Admin/SearchInput";
import UpdateButton from "@/Components/Admin/UpdateButton";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	ResponsiveContainer,
	LineChart,
	Line,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
} from "recharts";
import { Divider } from "@/Components/Catalyst/divider";
import LaboratoryNotificationResultsPdfActions, {
	pdfLocationBadge,
} from "@/Components/Admin/LaboratoryNotificationResultsPdfActions";
import LaboratoryGdaConsultConsole from "@/Components/Admin/LaboratoryGdaConsultConsole";

function isGabineteOrder(gdaOrderId) {
	if (!gdaOrderId) return false;
	return /[a-zA-Z]/.test(String(gdaOrderId));
}

function formatDiff(minutes) {
	if (minutes == null) return "—";
	if (minutes < 60) return `${minutes} min`;
	const h = Math.floor(minutes / 60);
	const m = minutes % 60;
	return `${h}h ${m}m`;
}

function formatDiffHours(minutes) {
	if (minutes == null) return "—";
	const hours = Math.round(minutes / 60);
	return `${hours}h`;
}

function formatDateTime(value) {
	if (!value) return "—";
	return new Date(value).toLocaleString("es-MX");
}

function statusBadgeColor(status) {
	if (status === "error") return "red";
	if (status === "processed") return "famedic-lime";
	return "slate";
}

export default function LaboratoryNotificationsMonitor({
	filters,
	dailyChart,
	orders,
}) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date,
		end_date: filters.end_date,
		search: filters.search || "",
	});

	const showUpdateButton = useMemo(
		() =>
			data.start_date !== filters.start_date ||
			data.end_date !== filters.end_date ||
			(data.search || "") !== (filters.search || ""),
		[data, filters],
	);

	const [orderDetail, setOrderDetail] = useState(null);
	const [detailLoading, setDetailLoading] = useState(false);
	const [detailError, setDetailError] = useState(null);
	const [detailOpen, setDetailOpen] = useState(false);

	const update = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.laboratory-notifications-monitor.index"), {
				preserveState: true,
			});
		}
	};

	const openOrderDetail = async (orderKey) => {
		setDetailOpen(true);
		setDetailLoading(true);
		setDetailError(null);
		setOrderDetail(null);

		try {
			const response = await fetch(
				route("admin.laboratory-notifications-monitor.order-details", {
					orderKey,
				}),
				{
					headers: {
						Accept: "application/json",
						"X-Requested-With": "XMLHttpRequest",
					},
					credentials: "same-origin",
				},
			);

			if (!response.ok) {
				throw new Error("No se pudo cargar el detalle de la orden.");
			}

			const json = await response.json();
			setOrderDetail(json);
		} catch (error) {
			setDetailError(
				error instanceof Error
					? error.message
					: "No se pudo cargar el detalle de la orden.",
			);
		} finally {
			setDetailLoading(false);
		}
	};

	const closeOrderDetail = () => {
		setDetailOpen(false);
		setOrderDetail(null);
		setDetailError(null);
	};

	return (
		<AdminLayout title="Monitor notificaciones laboratorio">
			<div className="space-y-6">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<Heading>Monitor notificaciones de laboratorio</Heading>
				</div>

				<form onSubmit={update} className="space-y-4">
					<div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por orden, consecutivo GDA, gda_order_id o propietario..."
						/>
						<div className="flex flex-wrap gap-2 items-end">
							<div className="space-y-1">
								<Text className="text-xs text-zinc-500">Inicio</Text>
								<input
									type="date"
									value={data.start_date}
									onChange={(e) =>
										setData("start_date", e.target.value)
									}
									className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</div>
							<div className="space-y-1">
								<Text className="text-xs text-zinc-500">Fin</Text>
								<input
									type="date"
									value={data.end_date}
									onChange={(e) => setData("end_date", e.target.value)}
									className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</div>
						</div>
					</div>

					{showUpdateButton && (
						<div className="flex justify-center md:justify-end">
							<UpdateButton type="submit" processing={processing} />
						</div>
					)}
				</form>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<div className="flex flex-wrap justify-end gap-x-4 gap-y-2">
						<div className="flex items-center gap-1">
							<Text>{dailyChart.averagePerDay}</Text>
							<Badge color="slate">promedio por día</Badge>
						</div>
						<div className="flex items-center gap-1">
							<Text>{dailyChart.total}</Text>
							<Badge color="slate">total</Badge>
						</div>
					</div>

					<ResponsiveContainer height={320} className="mt-4">
						<LineChart
							data={dailyChart.dataPoints}
							className="[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-slate-700 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-white"
						>
							<CartesianGrid vertical={false} />
							<XAxis
								tickLine={false}
								axisLine={false}
								dataKey="date"
								className="text-xs"
							/>
							<YAxis
								tickLine={false}
								axisLine={false}
								className="text-xs"
								width={60}
							/>
							<Tooltip
								content={<DailyTooltip />}
								cursor={{ strokeWidth: 1.5, strokeDasharray: "10 3" }}
							/>
							<Line
								dot={false}
								type="monotone"
								dataKey="sample"
								stroke="#0ea5e9"
								strokeWidth={2}
							/>
							<Line
								dot={false}
								type="monotone"
								dataKey="results"
								stroke="#22c55e"
								strokeWidth={2}
							/>
						</LineChart>
					</ResponsiveContainer>

					<div className="mt-3 flex flex-wrap gap-2 text-xs text-zinc-500">
						<span className="inline-flex items-center gap-2">
							<span className="h-2 w-2 rounded-full bg-sky-500" /> Toma de
							muestra
						</span>
						<span className="inline-flex items-center gap-2">
							<span className="h-2 w-2 rounded-full bg-green-500" /> Resultados
						</span>
					</div>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Órdenes (agrupado por consecutivo GDA)</Subheading>
					<Divider className="my-4" />

					<PaginatedTable paginatedData={orders}>
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Tipo</TableHeader>
									<TableHeader>Paciente</TableHeader>
									<TableHeader>Toma de muestra</TableHeader>
									<TableHeader>Orden</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{orders.data.map((o) => {
									const isGabinete = isGabineteOrder(
										o.folio || o.gda_order_id,
									);

									return (
										<TableRow key={o.order_key}>
											<TableCell className="align-top">
												<div className="space-y-1.5 min-w-0 max-w-[18rem]">
													<div className="flex flex-wrap items-center gap-1.5">
														<Badge
															color={isGabinete ? "amber" : "sky"}
														>
															{isGabinete
																? "Gabinete"
																: "Laboratorio"}
														</Badge>
														{o.brand ? (
															<Badge color="violet">{o.brand}</Badge>
														) : null}
													</div>
													<button
														type="button"
														onClick={() =>
															openOrderDetail(o.order_key)
														}
														className="text-left text-famedic-600 hover:underline dark:text-famedic-400"
													>
														<Strong>
															{o.gda_consecutivo ??
																o.folio ??
																o.gda_order_id}
														</Strong>
													</button>
													<Text className="text-xs font-mono text-zinc-600 dark:text-zinc-300 break-all">
														{o.folio || o.gda_order_id || "—"}
													</Text>
													{o.folio &&
														o.gda_order_id &&
														o.folio !== o.gda_order_id && (
															<Text className="text-[10px] text-zinc-400">
																SR id: {o.gda_order_id}
															</Text>
														)}
													<div className="flex flex-wrap gap-1.5 pt-0.5">
														<Badge color="sky">
															M: {o.sample_notifications}
														</Badge>
														<Badge color="emerald">
															R: {o.results_notifications}
														</Badge>
													</div>
												</div>
											</TableCell>
											<TableCell className="align-top">
												<div className="space-y-1 min-w-0 max-w-[16rem]">
													{o.patient_name ? (
														<Text className="text-sm">
															<Strong>{o.patient_name}</Strong>
														</Text>
													) : (
														<Text className="text-xs text-zinc-400">
															—
														</Text>
													)}
													{o.owner ? (
														<div>
															<Text className="text-xs text-zinc-600 dark:text-zinc-300">
																{o.owner.full_name}
															</Text>
															<Text className="text-xs text-zinc-500 break-all">
																{o.owner.email}
															</Text>
														</div>
													) : (
														<Text className="text-xs text-zinc-400">
															Sin propietario
														</Text>
													)}
													{o.formatted_total ? (
														<Text className="text-xs pt-0.5">
															<Strong>{o.formatted_total}</Strong>
														</Text>
													) : (
														<Text className="text-xs text-zinc-400">
															—
														</Text>
													)}
												</div>
											</TableCell>
											<TableCell className="align-top">
												<div className="space-y-1.5 min-w-0">
													<div>
														<Text className="text-[10px] uppercase tracking-wide text-zinc-400">
															Muestra
														</Text>
														<Text className="text-xs">
															{formatDateTime(o.sample_at)}
														</Text>
													</div>
													<div>
														<Text className="text-[10px] uppercase tracking-wide text-zinc-400">
															Resultados
														</Text>
														<Text className="text-xs">
															{formatDateTime(o.results_at)}
														</Text>
													</div>
													<Badge color="slate">
														{formatDiffHours(o.diff_minutes)}
													</Badge>
												</div>
											</TableCell>
											<TableCell className="align-top">
												<div className="space-y-1.5 min-w-0 max-w-[16rem]">
													<div>
														<Text className="text-[10px] uppercase tracking-wide text-zinc-400">
															Folio
														</Text>
														<Text className="text-xs font-mono">
															{o.folio || o.gda_order_id || "—"}
														</Text>
														{o.purchase_id ? (
															<Text className="text-[10px] text-zinc-400">
																Pedido #{o.purchase_id}
															</Text>
														) : null}
													</div>
													<div>
														<Text className="text-[10px] uppercase tracking-wide text-zinc-400">
															Estudios
															{o.studies_count != null
																? ` (${o.studies_count})`
																: ""}
														</Text>
														{o.studies?.length > 0 ? (
															<ul className="mt-0.5 space-y-0.5">
																{o.studies.map((study) => (
																	<li key={study.id}>
																		<Text className="text-xs leading-snug">
																			{study.name}
																			{study.gda_id
																				? ` · ${study.gda_id}`
																				: ""}
																		</Text>
																	</li>
																))}
															</ul>
														) : (
															<Text className="text-xs text-zinc-400">
																—
															</Text>
														)}
													</div>
												</div>
											</TableCell>
										</TableRow>
									);
								})}
							</TableBody>
						</Table>
					</PaginatedTable>
				</div>
			</div>

			<OrderDetailDialog
				open={detailOpen}
				onClose={closeOrderDetail}
				loading={detailLoading}
				error={detailError}
				detail={orderDetail}
				onDetailUpdated={setOrderDetail}
			/>
		</AdminLayout>
	);
}

function OrderDetailDialog({ open, onClose, loading, error, detail, onDetailUpdated }) {
	const [tabIndex, setTabIndex] = useState(0);
	const orderLabel =
		detail?.gdaConsecutivo ?? detail?.gdaOrderId ?? detail?.orderKey;

	useEffect(() => {
		if (open) {
			setTabIndex(0);
		}
	}, [open, detail?.orderKey]);

	return (
		<Dialog open={open} onClose={onClose} size="5xl">
			<div className="flex items-start gap-4">
				{detail?.brand?.image_src ? (
					<img
						src={detail.brand.image_src}
						alt={detail.brand.label || "Marca"}
						className="h-12 w-auto object-contain"
					/>
				) : null}
				<div className="min-w-0 flex-1">
					<DialogTitle>Orden {orderLabel ?? "—"}</DialogTitle>
					{detail?.brand?.label ? (
						<div className="mt-2">
							<Badge color="violet">{detail.brand.label}</Badge>
						</div>
					) : null}
					<DialogDescription>
						{detail?.owner
							? `${detail.owner.full_name} · ${detail.owner.email}`
							: "Propietario no identificado"}
					</DialogDescription>
				</div>
			</div>

			<DialogBody className="max-h-[70vh] overflow-y-auto">
				{loading && (
					<Text className="text-sm text-zinc-500">Cargando detalle...</Text>
				)}

				{error && (
					<Text className="text-sm text-red-600 dark:text-red-400">
						{error}
					</Text>
				)}

				{detail && !loading && !error && (
					<TabGroup selectedIndex={tabIndex} onChange={setTabIndex}>
						<TabList className="flex flex-wrap gap-2">
							<OrderTab label="Resumen" />
							<OrderTab
								label={`Toma de muestra (${detail.summary.sample_notifications})`}
							/>
							<OrderTab
								label={`Resultados (${detail.summary.results_notifications})`}
							/>
							<OrderTab label="Probar GDA" />
						</TabList>

						<Divider className="my-4" />

						<TabPanels>
							<TabPanel>
								<OrderSummaryTab
									detail={detail}
									onResultsPdfUpdated={(resultsPdf) =>
										onDetailUpdated?.({
											...detail,
											summary: {
												...detail.summary,
												results_pdf: resultsPdf,
											},
										})
									}
								/>
							</TabPanel>
							<TabPanel>
								<SampleNotificationsPanel
									notifications={detail.sampleNotifications}
								/>
							</TabPanel>
							<TabPanel>
								<ResultsNotificationsPanel
									notifications={detail.resultsNotifications}
								/>
							</TabPanel>
							<TabPanel>
								<LaboratoryGdaConsultConsole detail={detail} />
							</TabPanel>
						</TabPanels>
					</TabGroup>
				)}
			</DialogBody>

			<DialogActions>
				<Button outline onClick={onClose}>
					Cerrar
				</Button>
			</DialogActions>
		</Dialog>
	);
}

function OrderTab({ label }) {
	return (
		<Tab as={Fragment}>
			{({ selected }) => (
				<BadgeButton color={selected ? "famedic" : "slate"}>{label}</BadgeButton>
			)}
		</Tab>
	);
}

function OrderSummaryTab({ detail, onResultsPdfUpdated }) {
	const emails = detail.summary.emails;
	const isGabinete = isGabineteOrder(detail.folio || detail.gdaOrderId);

	return (
		<div className="space-y-6">
			<div className="flex flex-wrap items-center gap-3 text-xs text-zinc-500">
				{detail.brand?.image_src ? (
					<img
						src={detail.brand.image_src}
						alt={detail.brand.label || "Marca"}
						className="h-8 w-auto object-contain"
					/>
				) : null}
				<Badge color={isGabinete ? "amber" : "sky"}>
					{isGabinete ? "Gabinete" : "Laboratorio"}
				</Badge>
				{detail.brand?.label && (
					<Badge color="violet">{detail.brand.label}</Badge>
				)}
				{detail.gdaConsecutivo && (
					<span>Consecutivo GDA: {detail.gdaConsecutivo}</span>
				)}
				{(detail.folio || detail.gdaOrderId) && (
					<span>Folio / etiqueta GDA: {detail.folio || detail.gdaOrderId}</span>
				)}
			</div>

			{isGabinete && (
				<Text className="text-xs text-zinc-400">
					El consecutivo corto proviene de infogda_orden; el folio completo es la
					etiqueta GDA (p. ej. GZ0L…).
				</Text>
			)}

			{!isGabinete && detail.gdaConsecutivo && (
				<Text className="text-xs text-zinc-400">
					El consecutivo corresponde a ServiceRequest.id.
				</Text>
			)}

			<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<SummaryCard
					title="Notificaciones toma de muestra"
					value={String(detail.summary.sample_notifications)}
				/>
				<SummaryCard
					title="Notificaciones resultados"
					value={String(detail.summary.results_notifications)}
				/>
				<SummaryCard
					title="Primera toma de muestra"
					value={formatDateTime(detail.summary.sample_at)}
				/>
				<SummaryCard
					title="Primeros resultados"
					value={formatDateTime(detail.summary.results_at)}
				/>
			</div>

			<SummaryCard
				title="Tiempo muestra → resultados"
				value={formatDiff(detail.summary.diff_minutes)}
			/>

			<div className="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
				<Subheading>Ubicación del PDF de resultados</Subheading>
				<LaboratoryNotificationResultsPdfActions
					orderKey={detail.orderKey}
					resultsPdf={detail.summary.results_pdf}
					onResultsPdfUpdated={onResultsPdfUpdated}
				/>
			</div>

			{detail.summary.sync_logs?.length > 0 && (
				<div className="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
					<Subheading>Logs de sincronización GDA</Subheading>
					<SyncLogsTable logs={detail.summary.sync_logs} />
				</div>
			)}

			<div className="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
				<Subheading>Emails al paciente</Subheading>
				<div className="flex flex-wrap gap-2">
					<Badge color="sky">
						Muestra enviados: {emails?.sample_sent_count ?? 0}
					</Badge>
					<Badge color="emerald">
						Resultados enviados: {emails?.results_sent_count ?? 0}
					</Badge>
				</div>

				{emails?.order_state && (
					<div className="grid gap-2 text-sm sm:grid-cols-2">
						<Text>
							Estado orden · muestra:{" "}
							<Strong>
								{formatDateTime(emails.order_state.sample_email_sent_at)}
							</Strong>
						</Text>
						<Text>
							Estado orden · resultados:{" "}
							<Strong>
								{formatDateTime(emails.order_state.results_email_sent_at)}
							</Strong>
						</Text>
					</div>
				)}

				{emails?.entries?.length > 0 ? (
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Tipo</TableHeader>
								<TableHeader>Destinatario</TableHeader>
								<TableHeader>Enviado</TableHeader>
								<TableHeader>Intento</TableHeader>
								<TableHeader>Error</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{emails.entries.map((entry) => (
								<TableRow key={entry.notification_id}>
									<TableCell>
										<Text className="text-xs">{entry.type_label}</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">
											{entry.recipient || "—"}
										</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">
											{formatDateTime(entry.sent_at)}
										</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">
											{formatDateTime(entry.attempted_at)}
										</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs text-red-600 dark:text-red-400">
											{entry.error || "—"}
										</Text>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				) : (
					<Text className="text-sm text-zinc-500">
						No hay registros de envío de email para esta orden.
					</Text>
				)}
			</div>
		</div>
	);
}

function SampleNotificationsPanel({ notifications }) {
	if (!notifications?.length) {
		return (
			<Text className="text-sm text-zinc-500">
				Sin notificaciones de toma de muestra.
			</Text>
		);
	}

	return (
		<div className="space-y-6">
			<NotificationTable
				notifications={notifications}
				emptyMessage="Sin notificaciones de toma de muestra."
				showPdfColumn={false}
			/>
			{notifications.map((n) => (
				<NotificationPayloadCard
					key={`sample-payload-${n.id}`}
					notification={n}
					title={`Payload recibido · notificación #${n.id}`}
				/>
			))}
		</div>
	);
}

function ResultsNotificationsPanel({ notifications }) {
	if (!notifications?.length) {
		return (
			<Text className="text-sm text-zinc-500">
				Sin notificaciones de resultados.
			</Text>
		);
	}

	return (
		<div className="space-y-6">
			<NotificationTable
				notifications={notifications}
				emptyMessage="Sin notificaciones de resultados."
				showPdfColumn={true}
			/>
			{notifications.map((n) => (
				<div key={`results-payload-${n.id}`} className="space-y-4">
					<NotificationPayloadCard
						notification={n}
						title={`Payload recibido (webhook) · notificación #${n.id}`}
					/>
					<div className="space-y-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
						<Subheading>
							Payload enviado a GDA para PDF · #{n.id}
						</Subheading>
						{n.consult_preview_error ? (
							<Text className="text-sm text-amber-600 dark:text-amber-400">
								{n.consult_preview_error}
							</Text>
						) : (
							<>
								<div className="flex flex-wrap gap-2 text-xs">
									{n.consult_preview?.resolved_id && (
										<Badge color="sky">
											ID: {n.consult_preview.resolved_id}
										</Badge>
									)}
									{n.consult_preview?.resolved_source && (
										<Badge color="slate">
											fuente: {n.consult_preview.resolved_source}
										</Badge>
									)}
									{n.consult_preview?.url && (
										<Text className="text-xs text-zinc-500 break-all">
											{n.consult_preview.url}
										</Text>
									)}
								</div>
								<JsonBlock
									value={n.consult_preview?.payload}
									emptyMessage="No se pudo preparar el payload de consulta."
								/>
							</>
						)}
					</div>
				</div>
			))}
		</div>
	);
}

function NotificationPayloadCard({ notification, title }) {
	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<Subheading>{title}</Subheading>
				<div className="flex flex-wrap gap-1.5">
					<Badge color={statusBadgeColor(notification.status)}>
						{notification.status}
					</Badge>
					{notification.lineanegocio && (
						<Badge color="slate">{notification.lineanegocio}</Badge>
					)}
				</div>
			</div>
			<Text className="text-xs text-zinc-500">
				Creada: {formatDateTime(notification.created_at)}
				{notification.gda_order_id
					? ` · Folio: ${notification.gda_order_id}`
					: ""}
			</Text>
			<div className="space-y-2">
				<Text className="text-xs font-medium text-zinc-500">
					payload (API recibida)
				</Text>
				<JsonBlock
					value={notification.payload}
					emptyMessage="Sin payload guardado."
				/>
			</div>
			<div className="space-y-2">
				<Text className="text-xs font-medium text-zinc-500">gda_message</Text>
				<JsonBlock
					value={notification.gda_message}
					emptyMessage="Sin gda_message guardado."
				/>
			</div>
		</div>
	);
}

function prettyJson(value) {
	if (value == null) return "";
	try {
		return JSON.stringify(value, null, 2);
	} catch {
		return String(value);
	}
}

function JsonBlock({ value, emptyMessage = "Sin datos." }) {
	const text = prettyJson(value);

	if (!text) {
		return <Text className="text-sm text-zinc-500">{emptyMessage}</Text>;
	}

	return (
		<pre className="max-h-80 overflow-auto rounded-lg bg-zinc-950 p-3 text-[11px] leading-relaxed text-zinc-100">
			{text}
		</pre>
	);
}

function SyncLogsTable({ logs }) {
	if (!logs || logs.length === 0) {
		return <Text className="text-sm text-zinc-500">Sin registros de sincronización.</Text>;
	}

	return (
		<div className="overflow-x-auto">
			<Table>
				<TableHead>
					<TableRow>
						<TableHeader>Notificación</TableHeader>
						<TableHeader>Recibida</TableHeader>
						<TableHeader>Acuse</TableHeader>
						<TableHeader>Fuente</TableHeader>
						<TableHeader>Archivo storage</TableHeader>
						<TableHeader>Estado</TableHeader>
						<TableHeader>Email</TableHeader>
					</TableRow>
				</TableHead>
				<TableBody>
					{logs.map((log) => (
						<TableRow key={log.notification_id}>
							<TableCell>
								<Text className="text-xs">#{log.notification_id}</Text>
								{log.gda_order_id && (
									<Text className="text-xs text-zinc-400">{log.gda_order_id}</Text>
								)}
							</TableCell>
							<TableCell>
								<Text className="text-xs">{formatDateTime(log.results_received_at)}</Text>
							</TableCell>
							<TableCell>
								<Text className="text-xs font-mono">{log.gda_acuse ? log.gda_acuse.substring(0, 8) + "…" : "—"}</Text>
							</TableCell>
							<TableCell>
								<Badge color={log.results_source === "storage" ? "emerald" : log.results_source ? "sky" : "slate"}>
									{log.results_source || "—"}
								</Badge>
							</TableCell>
							<TableCell>
								{log.stored_in_storage ? (
									<Badge color="emerald">Sí</Badge>
								) : log.results_storage_error ? (
									<Badge color="red">Error</Badge>
								) : (
									<Badge color="slate">No</Badge>
								)}
								{log.purchase_results_path && (
									<Text className="text-xs text-zinc-400 mt-0.5 break-all">{log.purchase_results_path}</Text>
								)}
							</TableCell>
							<TableCell>
								<div className="space-y-0.5">
									{log.skipped_manual_result && <Badge color="violet">Manual existente</Badge>}
									{log.skipped_existing_result && <Badge color="zinc">Ya existía</Badge>}
									{log.admin_forced_refresh_at && (
										<Badge color="amber">Forzado</Badge>
									)}
									{log.results_storage_error && (
										<Text className="text-xs text-red-500">{log.results_storage_error}</Text>
									)}
								</div>
							</TableCell>
							<TableCell>
								{log.email_sent_at ? (
									<div>
										<Badge color="emerald">Enviado</Badge>
										<Text className="text-xs text-zinc-400">{log.email_recipient}</Text>
									</div>
								) : log.email_error ? (
									<div>
										<Badge color="red">Error</Badge>
										<Text className="text-xs text-red-400">{log.email_error}</Text>
									</div>
								) : (
									<Text className="text-xs text-zinc-400">—</Text>
								)}
							</TableCell>
						</TableRow>
					))}
				</TableBody>
			</Table>
		</div>
	);
}

function NotificationTable({ notifications, emptyMessage, showPdfColumn }) {
	if (notifications.length === 0) {
		return <Text className="text-sm text-zinc-500">{emptyMessage}</Text>;
	}

	return (
		<Table>
			<TableHead>
				<TableRow>
					<TableHeader>ID</TableHeader>
					<TableHeader>Estatus</TableHeader>
					<TableHeader>GDA</TableHeader>
					<TableHeader>Creada</TableHeader>
					<TableHeader>Recibida resultados</TableHeader>
					<TableHeader>Email enviado</TableHeader>
					<TableHeader>Destinatario</TableHeader>
					{showPdfColumn && <TableHeader>PDF</TableHeader>}
					<TableHeader>Error email</TableHeader>
				</TableRow>
			</TableHead>
			<TableBody>
				{notifications.map((n) => {
					const pdf = pdfLocationBadge({
						location: n.is_stale ? "db_base64_stale" : n.pdf_location,
						label:
							n.pdf_location === "db_base64"
								? n.is_stale
									? "En BD (desactualizado)"
									: "En BD"
								: n.pdf_location === "gda_provider"
									? "En GDA"
									: "Sin PDF",
					});

					return (
						<TableRow key={n.id}>
							<TableCell>{n.id}</TableCell>
							<TableCell>
								<Badge color={statusBadgeColor(n.status)}>
									{n.status}
								</Badge>
							</TableCell>
							<TableCell>
								<Text className="text-xs">{n.gda_status || "—"}</Text>
							</TableCell>
							<TableCell>
								<Text className="text-xs">
									{formatDateTime(n.created_at)}
								</Text>
							</TableCell>
							<TableCell>
								<Text className="text-xs">
									{formatDateTime(n.results_received_at)}
								</Text>
							</TableCell>
							<TableCell>
								<Text className="text-xs">
									{formatDateTime(n.email_sent_at)}
								</Text>
							</TableCell>
							<TableCell>
								<Text className="text-xs">
									{n.email_recipient_email || "—"}
								</Text>
							</TableCell>
							{showPdfColumn && (
								<TableCell>
									<Badge color={pdf.color}>{pdf.label}</Badge>
								</TableCell>
							)}
							<TableCell>
								<Text className="text-xs text-red-600 dark:text-red-400">
									{n.email_error || "—"}
								</Text>
							</TableCell>
						</TableRow>
					);
				})}
			</TableBody>
		</Table>
	);
}

function SummaryCard({ title, value }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
			<Text className="text-xs text-zinc-500">{title}</Text>
			<Text className="mt-1 text-sm">
				<Strong>{value}</Strong>
			</Text>
		</div>
	);
}

function DailyTooltip({ active, payload, label }) {
	if (!active || !payload || payload.length === 0) return null;
	const p = payload[0]?.payload;
	return (
		<div className="rounded-lg bg-white shadow-lg ring-1 ring-slate-950/10 dark:bg-slate-900 dark:ring-white/10">
			<div className="px-4 py-2">
				<Subheading>{label}</Subheading>
			</div>
			<Divider />
			<div className="px-4 py-2 space-y-1">
				<Text className="text-sm">
					Toma de muestra: <Strong>{p.sample}</Strong>
				</Text>
				<Text className="text-sm">
					Resultados: <Strong>{p.results}</Strong>
				</Text>
				<Text className="text-sm">
					Total: <Strong>{p.total}</Strong>
				</Text>
			</div>
		</div>
	);
}
