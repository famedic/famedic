import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	Bar,
	BarChart,
	Cell,
	ResponsiveContainer,
	Tooltip,
	XAxis,
	YAxis,
} from "recharts";

const DIST_COLORS = { high: "#059669", medium: "#d97706", low: "#dc2626" };

function StudyTable({ rows, empty }) {
	if (!rows?.length) {
		return <p className="py-4 text-sm text-zinc-400">{empty}</p>;
	}

	return (
		<Table dense>
			<TableHead>
				<TableRow>
					<TableHeader>Estudio</TableHeader>
					<TableHeader>Confianza</TableHeader>
					<TableHeader>Muestras</TableHeader>
				</TableRow>
			</TableHead>
			<TableBody>
				{rows.map((row) => (
					<TableRow key={`${row.name}-${row.avg}`}>
						<TableCell className="font-medium">{row.name}</TableCell>
						<TableCell className="tabular-nums">{row.avg}%</TableCell>
						<TableCell>{row.count}</TableCell>
					</TableRow>
				))}
			</TableBody>
		</Table>
	);
}

export default function ConfidenceAnalyticsModule({ data }) {
	const distribution = data?.distribution || [];
	const chartData = distribution.map((d) => ({
		...d,
		fill: DIST_COLORS[d.id] || "#64748b",
	}));

	return (
		<div className="space-y-6">
			<div className="grid gap-3 sm:grid-cols-3">
				{distribution.map((d) => (
					<BillingMetricCard
						key={d.id}
						label={`${d.label} (${d.range})`}
						value={d.count}
						tone={
							d.id === "high"
								? "lime"
								: d.id === "medium"
									? "amber"
									: "red"
						}
					/>
				))}
			</div>

			<ChartCard title="Distribución de confianza" description="Alta · Media · Baja">
				<div className="h-52">
					<ResponsiveContainer width="100%" height="100%">
						<BarChart data={chartData}>
							<XAxis dataKey="label" tick={{ fontSize: 12 }} />
							<YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
							<Tooltip />
							<Bar dataKey="count" radius={[6, 6, 0, 0]}>
								{chartData.map((entry) => (
									<Cell key={entry.id} fill={entry.fill} />
								))}
							</Bar>
						</BarChart>
					</ResponsiveContainer>
				</div>
			</ChartCard>

			<div className="grid gap-4 lg:grid-cols-2">
				<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
					<h3 className="text-sm font-semibold">Estudios con mayor confianza</h3>
					<StudyTable
						rows={data?.highest}
						empty="Sin scores de matching persistidos todavía."
					/>
				</section>
				<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
					<h3 className="text-sm font-semibold">Estudios con menor confianza</h3>
					<StudyTable
						rows={data?.lowest}
						empty="Sin scores de matching persistidos todavía."
					/>
				</section>
			</div>

			<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
				<h3 className="text-sm font-semibold">Coincidencias ambiguas</h3>
				{!(data?.ambiguous || []).length ? (
					<p className="mt-3 text-sm text-zinc-400">
						No hay ambigüedades registradas en validation.items.
					</p>
				) : (
					<ul className="mt-3 space-y-2">
						{data.ambiguous.map((row, i) => (
							<li
								key={`${row.detected}-${i}`}
								className="rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-950"
							>
								<span className="font-medium">{row.detected}</span>
								<span className="text-zinc-400"> → </span>
								{row.chosen || "—"}
							</li>
						))}
					</ul>
				)}
			</section>

			<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
				<h3 className="text-sm font-semibold">Correcciones frecuentes</h3>
				{!(data?.frequent_corrections || []).length ? (
					<p className="mt-3 text-sm text-zinc-400">Sin correcciones en AI Learning.</p>
				) : (
					<Table dense className="mt-2">
						<TableHead>
							<TableRow>
								<TableHeader>Detectado</TableHeader>
								<TableHeader>Confirmado</TableHeader>
								<TableHeader>Ocurrencias</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{data.frequent_corrections.map((row, i) => (
								<TableRow key={`${row.detected_text}-${i}`}>
									<TableCell>{row.detected_text}</TableCell>
									<TableCell>{row.confirmed_text}</TableCell>
									<TableCell>{row.occurrences}</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				)}
			</section>
		</div>
	);
}
