import {
	ResponsiveContainer,
	AreaChart,
	Area,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Legend,
} from "recharts";
import { Text } from "@/Components/Catalyst/text";

const COLORS = {
	total: "#0ea5e9",
	success: "#10b981",
	failed: "#f43f5e",
	avg: "#6366f1",
};

function ChartCard({ title, hint, children }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<div className="mb-3">
				<h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					{title}
				</h3>
				{hint ? (
					<Text className="text-xs text-zinc-500">{hint}</Text>
				) : null}
			</div>
			<div className="h-64 w-full">{children}</div>
		</div>
	);
}

export default function AutomationPerformanceCharts({ performance }) {
	const hourly = performance?.hourly || [];
	const errors = performance?.errors_hourly || [];
	const avg = performance?.avg_duration_hourly || [];
	const slowest = performance?.avg_ms_by_driver?.length
		? performance.avg_ms_by_driver
		: performance?.slowest_drivers || [];
	const successRate = performance?.success_rate;

	return (
		<div className="space-y-4">
			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
				{[
					["Success %", successRate != null ? `${successRate}%` : "—"],
					["Retries", performance?.retries_total ?? 0],
					["Dead Letters", performance?.dead_letters_total ?? 0],
					["P95", performance?.p95_ms != null ? `${performance.p95_ms} ms` : "—"],
					["P99", performance?.p99_ms != null ? `${performance.p99_ms} ms` : "—"],
				].map(([label, value]) => (
					<div
						key={label}
						className="rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
					>
						<p className="text-xs uppercase tracking-wide text-zinc-500">
							{label}
						</p>
						<p className="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
							{value}
						</p>
					</div>
				))}
			</div>

			<div className="grid gap-4 lg:grid-cols-2">
				<ChartCard
					title="Automatizaciones por hora"
					hint="Eventos o proxy de payment_attempts"
				>
					<ResponsiveContainer width="100%" height="100%">
						<AreaChart data={hourly}>
							<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
							<XAxis dataKey="hour" tick={{ fontSize: 11 }} />
							<YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
							<Tooltip />
							<Legend />
							<Area
								type="monotone"
								dataKey="total"
								name="Total"
								stroke={COLORS.total}
								fill={COLORS.total}
								fillOpacity={0.15}
							/>
							<Area
								type="monotone"
								dataKey="success"
								name="Correctas"
								stroke={COLORS.success}
								fill={COLORS.success}
								fillOpacity={0.12}
							/>
						</AreaChart>
					</ResponsiveContainer>
				</ChartCard>

				<ChartCard title="Errores por hora">
					<ResponsiveContainer width="100%" height="100%">
						<BarChart data={errors}>
							<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
							<XAxis dataKey="hour" tick={{ fontSize: 11 }} />
							<YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
							<Tooltip />
							<Bar dataKey="errors" name="Errores" fill={COLORS.failed} radius={4} />
						</BarChart>
					</ResponsiveContainer>
				</ChartCard>

				<ChartCard title="Tiempo promedio (ms)">
					<ResponsiveContainer width="100%" height="100%">
						<AreaChart data={avg}>
							<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
							<XAxis dataKey="hour" tick={{ fontSize: 11 }} />
							<YAxis tick={{ fontSize: 11 }} />
							<Tooltip />
							<Area
								type="monotone"
								dataKey="avg_ms"
								name="ms"
								stroke={COLORS.avg}
								fill={COLORS.avg}
								fillOpacity={0.15}
							/>
						</AreaChart>
					</ResponsiveContainer>
				</ChartCard>

				<ChartCard title="Tiempo promedio por Driver" hint="Últimos 7 días">
					{slowest.length === 0 ? (
						<div className="flex h-full items-center justify-center">
							<Text className="text-sm text-zinc-500">
								Sin muestras de duración todavía.
							</Text>
						</div>
					) : (
						<ResponsiveContainer width="100%" height="100%">
							<BarChart data={slowest} layout="vertical" margin={{ left: 24 }}>
								<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
								<XAxis type="number" tick={{ fontSize: 11 }} />
								<YAxis
									type="category"
									dataKey="driver"
									width={140}
									tick={{ fontSize: 10 }}
								/>
								<Tooltip />
								<Bar dataKey="avg_ms" name="ms promedio" fill={COLORS.avg} radius={4} />
							</BarChart>
						</ResponsiveContainer>
					)}
				</ChartCard>
			</div>
		</div>
	);
}
