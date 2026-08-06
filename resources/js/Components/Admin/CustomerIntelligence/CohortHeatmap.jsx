import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

function retentionColor(percent) {
	if (percent == null) return "bg-zinc-100 text-zinc-400 dark:bg-zinc-800";
	if (percent >= 40) return "bg-emerald-500 text-white";
	if (percent >= 20) return "bg-emerald-300 text-emerald-950";
	if (percent >= 10) return "bg-amber-300 text-amber-950";
	if (percent >= 5) return "bg-orange-300 text-orange-950";
	return "bg-rose-400 text-white";
}

export default function CohortHeatmap({ heatmap = [] }) {
	const maxWeeks = Math.max(
		...heatmap.map((row) => row.weeks?.length || 0),
		0,
	);
	const weekHeaders = Array.from({ length: maxWeeks }, (_, i) => `S${i}`);

	return (
		<ChartCard
			title="Cohort heatmap"
			description="Filas = mes de registro. Columnas = semanas posteriores. Color = % activos (compra)."
		>
			{heatmap.length === 0 ? (
				<div className="flex h-40 items-center justify-center text-sm text-zinc-400">
					Sin cohortes en el periodo
				</div>
			) : (
				<div className="overflow-x-auto">
					<table className="min-w-full border-separate border-spacing-1 text-xs">
						<thead>
							<tr>
								<th className="sticky left-0 z-10 bg-white px-2 py-1 text-left font-medium text-zinc-500 dark:bg-zinc-900">
									Cohort
								</th>
								<th className="px-2 py-1 text-right font-medium text-zinc-400">
									Size
								</th>
								{weekHeaders.map((label) => (
									<th
										key={label}
										className="px-1 py-1 text-center font-medium text-zinc-400"
									>
										{label}
									</th>
								))}
							</tr>
						</thead>
						<tbody>
							{heatmap.map((row) => (
								<tr key={row.cohort_key}>
									<td className="sticky left-0 z-10 whitespace-nowrap bg-white px-2 py-1 font-medium text-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
										{row.cohort_label}
									</td>
									<td className="px-2 py-1 text-right tabular-nums text-zinc-500">
										{row.size_formatted}
									</td>
									{(row.weeks || []).map((cell) => (
										<td key={`${row.cohort_key}-${cell.week}`} className="p-0.5">
											<div
												title={`${row.cohort_label} · Semana ${cell.week}: ${cell.percent ?? 0}% (${cell.retained} clientes)`}
												className={`flex h-9 min-w-[42px] items-center justify-center rounded-md font-semibold tabular-nums transition hover:ring-2 hover:ring-sky-400 ${retentionColor(cell.percent)}`}
											>
												{cell.percent != null ? `${cell.percent}%` : "—"}
											</div>
										</td>
									))}
								</tr>
							))}
						</tbody>
					</table>
					<div className="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-zinc-500">
						<span>Baja</span>
						<span className="size-3 rounded bg-rose-400" />
						<span className="size-3 rounded bg-orange-300" />
						<span className="size-3 rounded bg-amber-300" />
						<span className="size-3 rounded bg-emerald-300" />
						<span className="size-3 rounded bg-emerald-500" />
						<span>Alta</span>
					</div>
				</div>
			)}
		</ChartCard>
	);
}
