import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function SourceRetentionCompare({ rows = [] }) {
	return (
		<ChartCard
			title="Comparador por fuente"
			description="Retención 30d, repeat rate y LTV por origen (proxy)."
		>
			<div className="space-y-3">
				{rows.map((row) => (
					<div
						key={row.key}
						className="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
					>
						<div className="flex items-start justify-between gap-3">
							<div>
								<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									{row.label}
								</p>
								<p className="text-xs text-zinc-500">
									{Number(row.customers || 0).toLocaleString()} clientes ·{" "}
									{Number(row.recurrent || 0).toLocaleString()} recurrentes
								</p>
							</div>
							<p className="text-xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
								{row.retention_30 != null ? `${row.retention_30}%` : "—"}
							</p>
						</div>
						<div className="mt-3 grid grid-cols-2 gap-3 text-xs text-zinc-500">
							<div>
								<p className="uppercase tracking-wide">Repeat rate</p>
								<p className="mt-0.5 text-sm font-semibold text-zinc-800 dark:text-zinc-100">
									{row.repeat_rate != null ? `${row.repeat_rate}%` : "—"}
								</p>
							</div>
							<div>
								<p className="uppercase tracking-wide">LTV avg</p>
								<p className="mt-0.5 text-sm font-semibold text-zinc-800 dark:text-zinc-100">
									${Number(row.avg_ltv || 0).toLocaleString("es-MX")}
								</p>
							</div>
						</div>
						<div className="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
							<div
								className="h-full rounded-full bg-emerald-500"
								style={{
									width: `${Math.min(100, row.retention_30 || 0)}%`,
								}}
							/>
						</div>
					</div>
				))}
				{rows.length === 0 ? (
					<p className="text-sm text-zinc-400">Sin datos por fuente</p>
				) : null}
			</div>
		</ChartCard>
	);
}
