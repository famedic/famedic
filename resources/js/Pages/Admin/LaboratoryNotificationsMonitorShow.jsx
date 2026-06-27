import { Fragment, useState } from "react";
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from "@headlessui/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { BadgeButton } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Divider } from "@/Components/Catalyst/divider";
import LaboratoryNotificationResultsPdfActions, {
	pdfLocationBadge,
} from "@/Components/Admin/LaboratoryNotificationResultsPdfActions";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

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

export default function LaboratoryNotificationsMonitorShow({
	orderKey,
	gdaOrderId,
	gdaConsecutivo,
	owner,
	summary,
	sampleNotifications,
	resultsNotifications,
}) {
	const [tabIndex, setTabIndex] = useState(0);
	const [resultsPdf, setResultsPdf] = useState(summary.results_pdf);
	const orderLabel = gdaConsecutivo ?? gdaOrderId ?? orderKey;

	return (
		<AdminLayout title={`Orden ${orderLabel} - Notificaciones`}>
			<div className="space-y-6">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div className="space-y-1">
						<Heading>Orden {orderLabel}</Heading>
						{owner ? (
							<Text className="text-sm text-zinc-600 dark:text-zinc-300">
								<Strong>{owner.full_name}</Strong> · {owner.email}
							</Text>
						) : (
							<Text className="text-sm text-zinc-500">Propietario: —</Text>
						)}
					</div>
					<Button
						outline
						href={route("admin.laboratory-notifications-monitor.index")}
					>
						Volver
					</Button>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<TabGroup selectedIndex={tabIndex} onChange={setTabIndex}>
						<TabList className="flex flex-wrap gap-2">
							<OrderTab label="Resumen" />
							<OrderTab
								label={`Toma de muestra (${summary.sample_notifications})`}
							/>
							<OrderTab
								label={`Resultados (${summary.results_notifications})`}
							/>
						</TabList>

						<Divider className="my-4" />

						<TabPanels>
							<TabPanel>
								<OrderSummaryTab
									summary={summary}
									gdaConsecutivo={gdaConsecutivo}
									gdaOrderId={gdaOrderId}
									orderKey={orderKey}
									resultsPdf={resultsPdf}
									onResultsPdfUpdated={setResultsPdf}
								/>
							</TabPanel>
							<TabPanel>
								<NotificationTable
									notifications={sampleNotifications}
									emptyMessage="Sin notificaciones de toma de muestra."
									showPdfColumn={false}
								/>
							</TabPanel>
							<TabPanel>
								<NotificationTable
									notifications={resultsNotifications}
									emptyMessage="Sin notificaciones de resultados."
									showPdfColumn={true}
								/>
							</TabPanel>
						</TabPanels>
					</TabGroup>
				</div>
			</div>
		</AdminLayout>
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

function OrderSummaryTab({
	summary,
	gdaConsecutivo,
	gdaOrderId,
	orderKey,
	resultsPdf,
	onResultsPdfUpdated,
}) {
	const emails = summary.emails;

	return (
		<div className="space-y-6">
			<div className="flex flex-wrap gap-2 text-xs text-zinc-500">
				{gdaConsecutivo && <span>Consecutivo: {gdaConsecutivo}</span>}
				{gdaOrderId && <span>gda_order_id: {gdaOrderId}</span>}
			</div>

			<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<Card
					title="Notificaciones toma de muestra"
					value={String(summary.sample_notifications)}
				/>
				<Card
					title="Notificaciones resultados"
					value={String(summary.results_notifications)}
				/>
				<Card
					title="Primera toma de muestra"
					value={formatDateTime(summary.sample_at)}
				/>
				<Card
					title="Primeros resultados"
					value={formatDateTime(summary.results_at)}
				/>
			</div>

			<Card
				title="Tiempo muestra → resultados"
				value={formatDiff(summary.diff_minutes)}
			/>

			<div className="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
				<Subheading>Ubicación del PDF de resultados</Subheading>
				<LaboratoryNotificationResultsPdfActions
					orderKey={orderKey}
					resultsPdf={resultsPdf}
					onResultsPdfUpdated={onResultsPdfUpdated}
				/>
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

function Card({ title, value }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
			<Text className="text-xs text-zinc-500">{title}</Text>
			<Text className="mt-1 text-sm">
				<Strong>{value}</Strong>
			</Text>
		</div>
	);
}
