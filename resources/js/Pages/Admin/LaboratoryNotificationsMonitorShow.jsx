import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
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

export default function LaboratoryNotificationsMonitorShow({
	gdaOrderId,
	owner,
	summary,
	notifications,
}) {
	return (
		<AdminLayout title={`Orden ${gdaOrderId} - Notificaciones`}>
			<div className="space-y-6">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div className="space-y-1">
						<Heading>Orden {gdaOrderId}</Heading>
						{owner ? (
							<Text className="text-sm text-zinc-600 dark:text-zinc-300">
								<Strong>{owner.full_name}</Strong> · {owner.email}
							</Text>
						) : (
							<Text className="text-sm text-zinc-500">Propietario: —</Text>
						)}
					</div>
					<Button outline href={route("admin.laboratory-notifications-monitor.index")}>
						Volver
					</Button>
				</div>

				<div className="grid gap-4 md:grid-cols-3">
					<Card title="Toma de muestra" value={summary.sample_at ? new Date(summary.sample_at).toLocaleString("es-MX") : "—"} />
					<Card title="Resultados" value={summary.results_at ? new Date(summary.results_at).toLocaleString("es-MX") : "—"} />
					<Card title="Tiempo (muestra → resultados)" value={formatDiff(summary.diff_minutes)} />
				</div>

				<div className="flex flex-wrap gap-2">
					<Badge color="sky">Muestra: {summary.sample_notifications}</Badge>
					<Badge color="emerald">Resultados: {summary.results_notifications}</Badge>
					<Badge color="slate">Total: {summary.total_notifications}</Badge>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Notificaciones recibidas</Subheading>

					<Table className="mt-4">
						<TableHead>
							<TableRow>
								<TableHeader>ID</TableHeader>
								<TableHeader>Tipo</TableHeader>
								<TableHeader>Estatus</TableHeader>
								<TableHeader>GDA</TableHeader>
								<TableHeader>Creada</TableHeader>
								<TableHeader>Recibida resultados</TableHeader>
								<TableHeader>Email</TableHeader>
								<TableHeader>Error email</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{notifications.map((n) => (
								<TableRow key={n.id}>
									<TableCell>{n.id}</TableCell>
									<TableCell>
										<Text className="text-xs">{n.notification_type}</Text>
									</TableCell>
									<TableCell>
										<Badge color={n.status === "error" ? "red" : n.status === "processed" ? "famedic-lime" : "slate"}>
											{n.status}
										</Badge>
									</TableCell>
									<TableCell>
										<Text className="text-xs">{n.gda_status || "—"}</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">{n.created_at ? new Date(n.created_at).toLocaleString("es-MX") : "—"}</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">{n.results_received_at ? new Date(n.results_received_at).toLocaleString("es-MX") : "—"}</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">{n.email_sent_at ? new Date(n.email_sent_at).toLocaleString("es-MX") : "—"}</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs text-red-600 dark:text-red-400">
											{n.email_error || "—"}
										</Text>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</div>
			</div>
		</AdminLayout>
	);
}

function Card({ title, value }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Text className="text-xs text-zinc-500">{title}</Text>
			<Text className="mt-1 text-sm">
				<Strong>{value}</Strong>
			</Text>
		</div>
	);
}

