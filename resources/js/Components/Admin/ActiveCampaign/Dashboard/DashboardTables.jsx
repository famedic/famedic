import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import SectionHeading, { QuietLink } from "./SectionHeading";

const STATUS_COLOR = {
	synced: "emerald",
	failed: "red",
	pending: "amber",
	processing: "sky",
	skipped: "zinc",
};

function DispatchTable({ title, description, rows, emptyLabel, columns }) {
	return (
		<ChartCard title={title} description={description}>
			{!rows?.length ? (
				<Text className="text-sm text-zinc-500">{emptyLabel}</Text>
			) : (
				<div className="overflow-x-auto">
					<table className="min-w-full text-left text-sm">
						<thead className="text-xs uppercase tracking-wide text-zinc-500">
							<tr>
								{columns.map((col) => (
									<th key={col.key} className="pb-2 pr-3 font-medium">
										{col.label}
									</th>
								))}
							</tr>
						</thead>
						<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
							{rows.map((row) => (
								<tr key={row.id}>
									{columns.map((col) => (
										<td
											key={col.key}
											className={`py-2.5 pr-3 align-top ${col.className || ""}`}
										>
											{col.render ? col.render(row) : row[col.key]}
										</td>
									))}
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}
		</ChartCard>
	);
}

function StatusBadge({ status }) {
	return (
		<Badge color={STATUS_COLOR[status] || "zinc"} className="capitalize">
			{status}
		</Badge>
	);
}

export default function DashboardTables({ tables = {}, logsUrl }) {
	const activity = tables.recent_activity || [];
	const errors = tables.recent_errors || [];
	const inFlight = tables.in_flight || [];

	return (
		<section className="space-y-4">
			<SectionHeading
				eyebrow="Operaciones"
				title="Actividad reciente"
				description="Auditoría de dispatches, errores y cola in-flight."
				action={
					logsUrl ? <QuietLink href={logsUrl}>Abrir Logs</QuietLink> : null
				}
			/>

			<div className="grid gap-4 xl:grid-cols-3">
				<DispatchTable
					title="Actividad"
					description="Últimos dispatches creados."
					emptyLabel="Sin actividad reciente."
					rows={activity}
					columns={[
						{
							key: "event_type",
							label: "Evento",
							render: (row) => (
								<span className="font-medium text-zinc-900 dark:text-zinc-50">
									{row.event_type}
								</span>
							),
						},
						{
							key: "status",
							label: "Estado",
							render: (row) => <StatusBadge status={row.status} />,
						},
						{
							key: "when",
							label: "Cuándo",
							className: "tabular-nums text-xs text-zinc-500 whitespace-nowrap",
						},
					]}
				/>

				<DispatchTable
					title="Errores"
					description="Últimos failed (por updated_at)."
					emptyLabel="Sin errores recientes."
					rows={errors}
					columns={[
						{
							key: "event_type",
							label: "Evento",
							render: (row) => (
								<div>
									<p className="font-medium text-zinc-900 dark:text-zinc-50">
										{row.event_type}
									</p>
									<p className="mt-0.5 line-clamp-2 text-[11px] text-rose-600/90 dark:text-rose-400">
										{row.last_error}
									</p>
								</div>
							),
						},
						{
							key: "attempts",
							label: "Int.",
							className: "tabular-nums",
						},
						{
							key: "when",
							label: "Cuándo",
							className: "tabular-nums text-xs text-zinc-500 whitespace-nowrap",
						},
					]}
				/>

				<DispatchTable
					title="Pendientes"
					description="Cola pending + processing."
					emptyLabel="Cola vacía."
					rows={inFlight}
					columns={[
						{
							key: "event_type",
							label: "Evento",
							render: (row) => (
								<span className="font-medium text-zinc-900 dark:text-zinc-50">
									{row.event_type}
								</span>
							),
						},
						{
							key: "status",
							label: "Estado",
							render: (row) => <StatusBadge status={row.status} />,
						},
						{
							key: "email",
							label: "Email",
							className: "max-w-[9rem] truncate text-xs text-zinc-500",
						},
					]}
				/>
			</div>
		</section>
	);
}
