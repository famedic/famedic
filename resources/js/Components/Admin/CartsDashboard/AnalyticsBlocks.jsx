import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import SalesVsAbandoned from "@/Components/Admin/CartsDashboard/SalesVsAbandoned";
import {
	SalesTrendChart,
	AbandonedTrendChart,
	RevenueTrendChart,
	AbandonedRevenueTrendChart,
} from "@/Components/Admin/CartsDashboard/SalesTrendChart";
import { ChartCard, DASHBOARD_COLORS, CHART_UI } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import TopLaboratories from "@/Components/Admin/CartsDashboard/TopLaboratories";
import TopStudies from "@/Components/Admin/CartsDashboard/TopStudies";
import {
	ResponsiveContainer,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
} from "recharts";

function mapDailyRows(daily) {
	const rows = daily?.rows || [];

	return rows.map((row) => ({
		...row,
		sold_amount: row.completed_amount,
	}));
}

function FunnelBars({ title, description, stages }) {
	const data = (stages || []).filter((stage) => stage.count > 0);

	return (
		<ChartCard title={title} description={description}>
			{data.length === 0 ? (
				<Text className="text-sm text-zinc-500">Sin datos para el periodo.</Text>
			) : (
				<ResponsiveContainer height={280}>
					<BarChart data={data} className={CHART_UI} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
						<CartesianGrid strokeDasharray="3 3" vertical={false} />
						<XAxis dataKey="label" tickLine={false} axisLine={false} className="text-xs" />
						<YAxis allowDecimals={false} tickLine={false} axisLine={false} />
						<Tooltip />
						<Bar dataKey="count" name="Carritos" fill={DASHBOARD_COLORS.blue} radius={[4, 4, 0, 0]} />
					</BarChart>
				</ResponsiveContainer>
			)}
		</ChartCard>
	);
}

export function DailyTrends({ daily }) {
	const rows = daily?.rows || [];

	return (
		<div className="space-y-4">
			<SalesVsAbandoned
				data={{
					daily: mapDailyRows(daily),
					totals: {
						sold_amount: daily?.totals?.completed_amount,
						abandoned_amount: daily?.totals?.abandoned_amount,
					},
				}}
			/>
			<div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
				<SalesTrendChart
					data={rows.map((row) => ({
						label: row.label,
						value: row.completed_count,
					}))}
				/>
				<AbandonedTrendChart
					data={rows.map((row) => ({
						label: row.label,
						value: row.abandoned_count,
					}))}
				/>
				<RevenueTrendChart
					data={rows.map((row) => ({
						label: row.label,
						value: row.completed_amount,
					}))}
				/>
				<AbandonedRevenueTrendChart
					data={rows.map((row) => ({
						label: row.label,
						value: row.abandoned_amount,
					}))}
				/>
			</div>
		</div>
	);
}

export function FunnelAndStages({ funnel }) {
	const byFlow = funnel?.by_flow;
	const abandonment = funnel?.abandonment_by_stage || [];

	return (
		<div className="space-y-4">
			<div className="grid gap-4 xl:grid-cols-2">
				<FunnelBars
					title="Embudo general (hitos)"
					description="Conteo acumulado por hito alcanzado en el periodo."
					stages={funnel?.stages}
				/>
				<ChartCard
					title="Abandono por etapa"
					description="Distribución de carritos abandonados según último hito."
				>
					{abandonment.length === 0 ? (
						<Text className="text-sm text-zinc-500">Sin abandonos en el periodo.</Text>
					) : (
						<ul className="space-y-2 text-sm">
							{abandonment.map((row) => (
								<li
									key={row.key}
									className="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
								>
									<span>{row.label}</span>
									<span className="tabular-nums text-zinc-600 dark:text-zinc-300">
										<Strong>{row.count}</Strong> ({row.percent}%)
									</span>
								</li>
							))}
						</ul>
					)}
				</ChartCard>
			</div>

			{byFlow ? (
				<div className="space-y-3">
					<div className="flex flex-wrap items-center gap-2">
						<Text className="text-xs text-zinc-500">Distribución de flujo:</Text>
						{(byFlow.distribution || []).map((row) => (
							<Badge key={row.key} color="zinc">
								{row.label}: {row.count}
							</Badge>
						))}
					</div>
					<div className="grid gap-4 xl:grid-cols-2">
						<FunnelBars
							title="Embudo cita primero"
							description={byFlow.milestone_notes?.appointment_first}
							stages={byFlow.appointment_first}
						/>
						<FunnelBars
							title="Embudo estándar"
							description={byFlow.milestone_notes?.standard}
							stages={byFlow.standard}
						/>
					</div>
					{byFlow.milestone_notes?.abandonment ? (
						<Text className="text-xs text-zinc-500">{byFlow.milestone_notes.abandonment}</Text>
					) : null}
				</div>
			) : null}

			{funnel?.confidence?.length ? (
				<div className="rounded-lg border border-dashed border-zinc-300 p-3 text-xs text-zinc-500 dark:border-zinc-600">
					<ul className="list-disc space-y-1 pl-4">
						{funnel.confidence.map((note) => (
							<li key={note}>{note}</li>
						))}
					</ul>
				</div>
			) : null}
		</div>
	);
}

export function PaymentsBlock({ payments }) {
	const rows = payments?.status_breakdown || [];

	return (
		<div className="grid gap-4 md:grid-cols-3">
			{rows.map((row) => (
				<ChartCard key={row.key} title={row.label}>
					<p className="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
						{row.count}
					</p>
				</ChartCard>
			))}
		</div>
	);
}

export function AppointmentsContactBlock({ appointments, contact }) {
	const appointmentRows = appointments?.status_breakdown || [];
	const contactRows = contact?.summary || [];

	return (
		<div className="grid gap-4 lg:grid-cols-2">
			<ChartCard title="Estado de citas">
				<ul className="space-y-2 text-sm">
					{appointmentRows.map((row) => (
						<li key={row.key} className="flex justify-between">
							<span>{row.label}</span>
							<Strong>{row.count}</Strong>
						</li>
					))}
				</ul>
			</ChartCard>
			<ChartCard title="Señales de contacto">
				<ul className="space-y-2 text-sm">
					{contactRows.map((row) => (
						<li key={row.key} className="flex justify-between">
							<span>{row.label}</span>
							<Strong>{row.count}</Strong>
						</li>
					))}
				</ul>
			</ChartCard>
		</div>
	);
}

export function LaboratoriesCustomersBlock({ laboratories, customerProfile }) {
	return (
		<div className="space-y-4">
			<TopLaboratories data={laboratories} />
			{customerProfile?.segments?.length ? (
				<ChartCard title="Perfil de clientes (abandono)">
					<ul className="space-y-2 text-sm">
						{customerProfile.segments.map((segment) => (
							<li key={segment.key} className="flex justify-between gap-4">
								<span>{segment.label}</span>
								<span className="tabular-nums text-zinc-600 dark:text-zinc-300">
									{segment.abandoned_count} abandonos · {segment.conversion_percent ?? "—"}% conv.
								</span>
							</li>
						))}
					</ul>
				</ChartCard>
			) : null}
		</div>
	);
}

export function TicketAndStudiesBlock({ ticketAverages, topStudies }) {
	return (
		<div className="space-y-4">
			<div className="grid gap-4 md:grid-cols-3">
				<ChartCard title="Ticket promedio creado">
					<p className="text-2xl font-semibold">
						{ticketAverages?.avg_ticket_created != null
							? `$${Number(ticketAverages.avg_ticket_created).toLocaleString("es-MX")}`
							: "—"}
					</p>
				</ChartCard>
				<ChartCard title="Ticket promedio abandonado">
					<p className="text-2xl font-semibold">
						{ticketAverages?.avg_ticket_abandoned != null
							? `$${Number(ticketAverages.avg_ticket_abandoned).toLocaleString("es-MX")}`
							: "—"}
					</p>
				</ChartCard>
				<ChartCard title="Ticket promedio comprado">
					<p className="text-2xl font-semibold">
						{ticketAverages?.avg_ticket_completed != null
							? `$${Number(ticketAverages.avg_ticket_completed).toLocaleString("es-MX")}`
							: "—"}
					</p>
				</ChartCard>
			</div>
			<TopStudies data={topStudies} />
		</div>
	);
}
