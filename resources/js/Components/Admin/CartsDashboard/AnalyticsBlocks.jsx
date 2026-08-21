import {
	ResponsiveContainer,
	BarChart,
	Bar,
	AreaChart,
	Area,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Legend,
} from "recharts";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChartCard, CHART_UI, DASHBOARD_COLORS } from "./chartTheme.jsx";

function money(value) {
	return `$${Number(value || 0).toLocaleString("es-MX", {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})} MXN`;
}

function hasValues(rows = [], keys = []) {
	return rows.some((row) => keys.some((key) => Number(row[key] || 0) > 0));
}

function MoneyTooltip({ active, payload, label }) {
	if (!active || !payload?.length) return null;

	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			<p className="mb-1 font-medium text-zinc-700 dark:text-zinc-200">{label}</p>
			{payload.map((entry) => (
				<p key={entry.dataKey} className="text-zinc-600 dark:text-zinc-300">
					{entry.name}: <Strong>{money(entry.value)}</Strong>
				</p>
			))}
		</div>
	);
}

function CountTooltip({ active, payload, label }) {
	if (!active || !payload?.length) return null;

	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			<p className="mb-1 font-medium text-zinc-700 dark:text-zinc-200">{label}</p>
			{payload.map((entry) => (
				<p key={entry.dataKey} className="text-zinc-600 dark:text-zinc-300">
					{entry.name}: <Strong>{entry.value}</Strong>
				</p>
			))}
		</div>
	);
}

export function DailyTrends({ daily }) {
	const rows = daily?.rows || [];

	return (
		<div className="grid gap-4 xl:grid-cols-2">
			<ChartCard
				title="Evolucion de carritos"
				description="Creados, abandonados y comprados por dia."
			>
				{!hasValues(rows, ["created_count", "abandoned_count", "completed_count"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<ResponsiveContainer height={320}>
						<AreaChart data={rows} className={CHART_UI} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tickLine={false} axisLine={false} />
							<YAxis allowDecimals={false} tickLine={false} axisLine={false} />
							<Tooltip content={<CountTooltip />} />
							<Legend />
							<Area type="monotone" dataKey="created_count" name="Creados" stroke={DASHBOARD_COLORS.blue} fill={DASHBOARD_COLORS.blue} fillOpacity={0.12} />
							<Area type="monotone" dataKey="abandoned_count" name="Abandonados" stroke={DASHBOARD_COLORS.red} fill={DASHBOARD_COLORS.red} fillOpacity={0.12} />
							<Area type="monotone" dataKey="completed_count" name="Comprados" stroke={DASHBOARD_COLORS.green} fill={DASHBOARD_COLORS.green} fillOpacity={0.12} />
						</AreaChart>
					</ResponsiveContainer>
				)}
			</ChartCard>

			<ChartCard
				title="Montos por dia"
				description="Monto de carritos creados, abandonados y comprados."
			>
				{!hasValues(rows, ["created_amount", "abandoned_amount", "completed_amount"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<ResponsiveContainer height={320}>
						<BarChart data={rows} className={CHART_UI} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tickLine={false} axisLine={false} />
							<YAxis tickLine={false} axisLine={false} />
							<Tooltip content={<MoneyTooltip />} />
							<Legend />
							<Bar dataKey="created_amount" name="Monto creado" fill={DASHBOARD_COLORS.blue} radius={[4, 4, 0, 0]} />
							<Bar dataKey="abandoned_amount" name="Monto abandonado" fill={DASHBOARD_COLORS.orange} radius={[4, 4, 0, 0]} />
							<Bar dataKey="completed_amount" name="Monto comprado" fill={DASHBOARD_COLORS.green} radius={[4, 4, 0, 0]} />
						</BarChart>
					</ResponsiveContainer>
				)}
			</ChartCard>
		</div>
	);
}

export function FunnelAndStages({ funnel }) {
	const stages = funnel?.stages || [];
	const abandonment = funnel?.abandonment_by_stage || [];

	return (
		<div className="grid gap-4 xl:grid-cols-2">
			<ChartCard title="Embudo del checkout" description="Etapas reconstruibles con datos existentes.">
				{!hasValues(stages, ["count"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<ResponsiveContainer height={300}>
						<BarChart data={stages} layout="vertical" className={CHART_UI} margin={{ left: 24, right: 16, top: 8, bottom: 8 }}>
							<CartesianGrid strokeDasharray="3 3" horizontal={false} />
							<XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} />
							<YAxis type="category" dataKey="label" width={130} tickLine={false} axisLine={false} />
							<Tooltip content={<CountTooltip />} />
							<Bar dataKey="count" name="Carritos" fill={DASHBOARD_COLORS.blue} radius={[0, 4, 4, 0]} />
						</BarChart>
					</ResponsiveContainer>
				)}
				{funnel?.confidence?.length ? (
					<ul className="mt-3 list-disc space-y-1 pl-4 text-xs text-zinc-500 dark:text-zinc-400">
						{funnel.confidence.map((item) => (
							<li key={item}>{item}</li>
						))}
					</ul>
				) : null}
			</ChartCard>

			<ChartCard title="Abandono por etapa" description="Donde se pierde el carrito con clasificacion razonable.">
				{!hasValues(abandonment, ["count"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<div className="space-y-3">
						{abandonment.map((row) => (
							<div key={row.key}>
								<div className="flex items-center justify-between text-sm">
									<span className="font-medium text-zinc-900 dark:text-zinc-50">{row.label}</span>
									<span className="tabular-nums text-zinc-500">{row.count} · {row.percent}%</span>
								</div>
								<div className="mt-1 h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
									<div className="h-2 rounded-full bg-rose-500" style={{ width: `${Math.min(100, row.percent)}%` }} />
								</div>
							</div>
						))}
					</div>
				)}
			</ChartCard>
		</div>
	);
}

export function PaymentsBlock({ payments }) {
	const statusRows = payments?.status_breakdown || [];
	const trend = payments?.trend || [];

	return (
		<div className="grid gap-4 xl:grid-cols-2">
			<ChartCard title="Incidencias de pago" description="Solo pagos Efevoo correlacionados sin ambiguedad.">
				{!hasValues(statusRows, ["count"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<ResponsiveContainer height={260}>
						<BarChart data={statusRows} className={CHART_UI}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tickLine={false} axisLine={false} />
							<YAxis allowDecimals={false} tickLine={false} axisLine={false} />
							<Tooltip content={<CountTooltip />} />
							<Bar dataKey="count" name="Carritos" fill={DASHBOARD_COLORS.red} radius={[4, 4, 0, 0]} />
						</BarChart>
					</ResponsiveContainer>
				)}
				<Text className="mt-2 text-xs text-zinc-500">
					La tasa de incidencia no se muestra porque el denominador historico de carritos que llegaron a pago no siempre es recuperable con precision.
				</Text>
			</ChartCard>

			<ChartCard title="Tendencia de incidencias de pago" description="Declined, error y pending por dia.">
				{!hasValues(trend, ["declined", "error", "pending"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<ResponsiveContainer height={260}>
						<BarChart data={trend} className={CHART_UI}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tickLine={false} axisLine={false} />
							<YAxis allowDecimals={false} tickLine={false} axisLine={false} />
							<Tooltip content={<CountTooltip />} />
							<Legend />
							<Bar dataKey="declined" name="Rechazado" fill={DASHBOARD_COLORS.red} />
							<Bar dataKey="error" name="Error" fill={DASHBOARD_COLORS.orange} />
							<Bar dataKey="pending" name="Pendiente" fill={DASHBOARD_COLORS.purple} />
						</BarChart>
					</ResponsiveContainer>
				)}
			</ChartCard>
		</div>
	);
}

export function AppointmentsContactBlock({ appointments, contact }) {
	const appointmentRows = appointments?.status_breakdown || [];
	const contactRows = contact?.summary || [];
	const contactTrend = contact?.trend || [];

	return (
		<div className="grid gap-4 xl:grid-cols-3">
			<ChartCard title="Estado de citas" description="Relacion de citas con carritos.">
				{!hasValues(appointmentRows, ["count"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<div className="space-y-3">
						{appointmentRows.map((row) => (
							<div key={row.key} className="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800/50">
								<span className="text-sm text-zinc-700 dark:text-zinc-200">{row.label}</span>
								<Badge color="zinc">{row.count}</Badge>
							</div>
						))}
					</div>
				)}
			</ChartCard>

			<ChartCard title="Llamadas / contacto" description="Senales agregadas, sin comentarios de pacientes.">
				{!hasValues(contactRows, ["count"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<div className="space-y-3">
						{contactRows.map((row) => (
							<div key={row.key} className="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800/50">
								<span className="text-sm text-zinc-700 dark:text-zinc-200">{row.label}</span>
								<Badge color="blue">{row.count}</Badge>
							</div>
						))}
					</div>
				)}
			</ChartCard>

			<ChartCard title="Evolucion de contacto" description="Solicitudes e intentos de llamada por dia.">
				{!hasValues(contactTrend, ["callback_requested", "phone_call_intent"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<ResponsiveContainer height={240}>
						<BarChart data={contactTrend} className={CHART_UI}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tickLine={false} axisLine={false} />
							<YAxis allowDecimals={false} tickLine={false} axisLine={false} />
							<Tooltip content={<CountTooltip />} />
							<Legend />
							<Bar dataKey="callback_requested" name="Solicito llamada" fill={DASHBOARD_COLORS.blue} />
							<Bar dataKey="phone_call_intent" name="Intento llamar" fill={DASHBOARD_COLORS.purple} />
						</BarChart>
					</ResponsiveContainer>
				)}
			</ChartCard>
		</div>
	);
}

export function LaboratoriesCustomersBlock({ laboratories = [], customerProfile }) {
	const segments = customerProfile?.segments || [];

	return (
		<div className="grid gap-4 xl:grid-cols-2">
			<ChartCard title="Abandono por laboratorio" description="Marca por cart_items -> laboratory_tests; desconocidas se separan.">
				{!laboratories.length ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full text-left text-sm">
							<thead className="text-xs uppercase tracking-wide text-zinc-500">
								<tr>
									<th className="pb-2 pr-3 font-medium">Marca</th>
									<th className="pb-2 pr-3 font-medium">Carritos</th>
									<th className="pb-2 pr-3 font-medium">Abandonados</th>
									<th className="pb-2 pr-3 font-medium">Comprados</th>
									<th className="pb-2 pr-3 font-medium">Conversion</th>
									<th className="pb-2 font-medium">Monto abandonado</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
								{laboratories.map((row) => (
									<tr key={row.brand}>
										<td className="py-2.5 pr-3 font-medium text-zinc-900 dark:text-zinc-50">{row.brand_label}</td>
										<td className="py-2.5 pr-3 tabular-nums">{row.carts_count}</td>
										<td className="py-2.5 pr-3 tabular-nums text-rose-600">{row.abandoned_count}</td>
										<td className="py-2.5 pr-3 tabular-nums text-emerald-600">{row.completed_count}</td>
										<td className="py-2.5 pr-3">{row.conversion_percent == null ? "--" : `${row.conversion_percent}%`}</td>
										<td className="py-2.5 tabular-nums">{money(row.abandoned_value)}</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				)}
			</ChartCard>

			<ChartCard title="Perfil de abandono" description="Clasificacion nueva, existente y recurrente segun compras previas.">
				{!hasValues(segments, ["abandoned_count"]) ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<div className="space-y-4">
						{segments.map((row) => (
							<div key={row.key} className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
								<div className="flex items-center justify-between">
									<Strong>{row.label}</Strong>
									<Badge color="zinc">{row.abandoned_percent}%</Badge>
								</div>
								<div className="mt-2 grid grid-cols-2 gap-3 text-xs text-zinc-500">
									<p>Abandonados: <Strong>{row.abandoned_count}</Strong></p>
									<p>Monto: <Strong>{money(row.abandoned_value)}</Strong></p>
									<p>Creados: <Strong>{row.created_count}</Strong></p>
									<p>Conversion: <Strong>{row.conversion_percent == null ? "--" : `${row.conversion_percent}%`}</Strong></p>
								</div>
							</div>
						))}
					</div>
				)}
			</ChartCard>
		</div>
	);
}

export function TicketAndStudiesBlock({ ticketAverages, topStudies }) {
	const studies = topStudies?.abandoned || [];

	return (
		<div className="grid gap-4 xl:grid-cols-3">
			<ChartCard title="Ticket promedio" description="Usa carts.total.">
				<div className="space-y-3">
					{[
						["Ticket promedio carrito", ticketAverages?.avg_ticket_created],
						["Ticket promedio abandonado", ticketAverages?.avg_ticket_abandoned],
						["Ticket promedio comprado", ticketAverages?.avg_ticket_completed],
					].map(([label, value]) => (
						<div key={label} className="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
							<p className="text-xs text-zinc-500">{label}</p>
							<p className="mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-50">
								{value == null ? "--" : money(value)}
							</p>
						</div>
					))}
				</div>
			</ChartCard>

			<ChartCard title="Estudios mas presentes en abandonos" description="COUNT(DISTINCT cart_id) y SUM(quantity)." className="xl:col-span-2">
				{!studies.length ? (
					<Text className="text-sm text-zinc-500">No hay datos para este periodo.</Text>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full text-left text-sm">
							<thead className="text-xs uppercase tracking-wide text-zinc-500">
								<tr>
									<th className="pb-2 pr-2 font-medium">#</th>
									<th className="pb-2 pr-3 font-medium">Estudio</th>
									<th className="pb-2 pr-3 font-medium">Marca</th>
									<th className="pb-2 pr-3 font-medium">Carritos</th>
									<th className="pb-2 font-medium">Items</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
								{studies.map((row, index) => (
									<tr key={`${row.id}-${index}`}>
										<td className="py-2.5 pr-2"><Badge color="zinc">{index + 1}</Badge></td>
										<td className="py-2.5 pr-3 font-medium text-zinc-900 dark:text-zinc-50">{row.name}</td>
										<td className="py-2.5 pr-3">{row.brand}</td>
										<td className="py-2.5 pr-3 tabular-nums text-rose-600">{row.carts}</td>
										<td className="py-2.5 tabular-nums">{row.quantity}</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				)}
			</ChartCard>
		</div>
	);
}
