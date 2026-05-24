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

function formatDiff(minutes) {
	if (minutes == null) return "—";
	if (minutes < 60) return `${minutes} min`;
	const h = Math.floor(minutes / 60);
	const m = minutes % 60;
	return `${h}h ${m}m`;
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

function pdfLocationBadge(pdf) {
	if (!pdf) return { color: "slate", label: "—" };
	switch (pdf.location) {
		case "db_base64":
			return { color: "famedic-lime", label: pdf.label };
		case "gda_provider":
			return { color: "sky", label: pdf.label };
		default:
			return { color: "slate", label: pdf.label };
	}
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
									<TableHeader>Orden</TableHeader>
									<TableHeader>Propietario</TableHeader>
									<TableHeader>Toma de muestra</TableHeader>
									<TableHeader>Resultados</TableHeader>
									<TableHeader>Tiempo</TableHeader>
									<TableHeader>Notifs</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{orders.data.map((o) => (
									<TableRow key={o.order_key}>
										<TableCell>
											<button
												type="button"
												onClick={() => openOrderDetail(o.order_key)}
												className="text-left text-famedic-600 hover:underline dark:text-famedic-400"
											>
												<Strong>
													{o.gda_consecutivo ?? o.gda_order_id}
												</Strong>
											</button>
											{o.gda_order_id &&
												String(o.gda_order_id) !==
													String(o.gda_consecutivo ?? o.order_key) && (
													<Text className="text-xs text-zinc-500">
														gda_order_id: {o.gda_order_id}
													</Text>
												)}
										</TableCell>
										<TableCell>
											{o.owner ? (
												<div className="space-y-1">
													<Text className="text-sm">
														<Strong>{o.owner.full_name}</Strong>
													</Text>
													<Text className="text-xs text-zinc-500">
														{o.owner.email}
													</Text>
												</div>
											) : (
												<Text className="text-xs text-zinc-400">—</Text>
											)}
										</TableCell>
										<TableCell>
											<Text className="text-xs">
												{formatDateTime(o.sample_at)}
											</Text>
										</TableCell>
										<TableCell>
											<Text className="text-xs">
												{formatDateTime(o.results_at)}
											</Text>
										</TableCell>
										<TableCell>
											<Badge color="slate">
												{formatDiff(o.diff_minutes)}
											</Badge>
										</TableCell>
										<TableCell>
											<div className="flex gap-2">
												<Badge color="sky">
													M: {o.sample_notifications}
												</Badge>
												<Badge color="emerald">
													R: {o.results_notifications}
												</Badge>
											</div>
										</TableCell>
									</TableRow>
								))}
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
			/>
		</AdminLayout>
	);
}

function OrderDetailDialog({ open, onClose, loading, error, detail }) {
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
			<DialogTitle>Orden {orderLabel ?? "—"}</DialogTitle>
			<DialogDescription>
				{detail?.owner
					? `${detail.owner.full_name} · ${detail.owner.email}`
					: "Propietario no identificado"}
			</DialogDescription>

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
						</TabList>

						<Divider className="my-4" />

						<TabPanels>
							<TabPanel>
								<OrderSummaryTab detail={detail} />
							</TabPanel>
							<TabPanel>
								<NotificationTable
									notifications={detail.sampleNotifications}
									emptyMessage="Sin notificaciones de toma de muestra."
									showPdfColumn={false}
								/>
							</TabPanel>
							<TabPanel>
								<NotificationTable
									notifications={detail.resultsNotifications}
									emptyMessage="Sin notificaciones de resultados."
									showPdfColumn={true}
								/>
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

function OrderSummaryTab({ detail }) {
	const pdfBadge = pdfLocationBadge(detail.summary.results_pdf);
	const emails = detail.summary.emails;

	return (
		<div className="space-y-6">
			<div className="flex flex-wrap gap-2 text-xs text-zinc-500">
				{detail.gdaConsecutivo && (
					<span>Consecutivo: {detail.gdaConsecutivo}</span>
				)}
				{detail.gdaOrderId && <span>gda_order_id: {detail.gdaOrderId}</span>}
			</div>

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
				<Badge color={pdfBadge.color}>{pdfBadge.label}</Badge>
				<div className="flex flex-wrap gap-2">
					<Badge color={detail.summary.results_pdf?.has_pdf_in_db ? "famedic-lime" : "slate"}>
						En BD (base64):{" "}
						{detail.summary.results_pdf?.has_pdf_in_db ? "Sí" : "No"}
					</Badge>
					<Badge color={detail.summary.results_pdf?.available_at_gda ? "sky" : "slate"}>
						En proveedor GDA:{" "}
						{detail.summary.results_pdf?.available_at_gda ? "Sí" : "No"}
					</Badge>
				</div>
				{detail.summary.results_pdf?.notification_id && (
					<Text className="text-xs text-zinc-500">
						Notificación de referencia: #{detail.summary.results_pdf.notification_id}
					</Text>
				)}
			</div>

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
						location: n.pdf_location,
						label:
							n.pdf_location === "db_base64"
								? "En BD"
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
