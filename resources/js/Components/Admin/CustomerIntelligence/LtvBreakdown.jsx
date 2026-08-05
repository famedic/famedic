import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import { Badge } from "@/Components/Catalyst/badge";

const DIMENSION_LABELS = {
	source: "Fuente",
	state: "Estado",
	city: "Ciudad",
	gender: "Sexo",
	channel: "Canal",
};

export default function LtvBreakdown({ rows = [] }) {
	const grouped = rows.reduce((acc, row) => {
		acc[row.dimension] = acc[row.dimension] || [];
		acc[row.dimension].push(row);
		return acc;
	}, {});

	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Lifetime Value
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					LTV promedio por dimensión del cohort.
				</p>
			</div>
			<div className="grid gap-4 lg:grid-cols-2">
				{Object.entries(grouped).map(([dimension, items]) => (
					<ChartCard
						key={dimension}
						title={DIMENSION_LABELS[dimension] || dimension}
						description="Ordenado por LTV promedio."
					>
						<ul className="space-y-2">
							{items.slice(0, 8).map((item) => (
								<li
									key={`${dimension}-${item.key}`}
									className="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800/50"
								>
									<div className="min-w-0">
										<p className="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">
											{item.label}
										</p>
										<p className="text-[11px] text-zinc-400">
											{Number(item.customers || 0).toLocaleString()} clientes
										</p>
									</div>
									<div className="text-right">
										<p className="text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
											${Number(item.avg_ltv || 0).toLocaleString("es-MX")}
										</p>
										<Badge color="zinc" className="mt-0.5">
											Σ ${Number(item.total_ltv || 0).toLocaleString("es-MX")}
										</Badge>
									</div>
								</li>
							))}
						</ul>
					</ChartCard>
				))}
			</div>
			{rows.length === 0 ? (
				<p className="text-sm text-zinc-400">Sin breakdown de LTV</p>
			) : null}
		</section>
	);
}
