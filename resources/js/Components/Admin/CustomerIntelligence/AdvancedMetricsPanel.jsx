import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import SourceBarChart from "./SourceBarChart";

function MetricTile({ label, value, hint }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-500">
				{label}
			</p>
			<p className="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
				{value}
			</p>
			{hint ? <p className="mt-1 text-xs text-zinc-400">{hint}</p> : null}
		</div>
	);
}

function formatDays(value) {
	if (value == null) return "—";
	return `${Number(value).toFixed(1)} días`;
}

function formatMoney(value) {
	if (value == null) return "—";
	return `$${Number(value).toLocaleString("es-MX", { maximumFractionDigits: 0 })} MXN`;
}

export default function AdvancedMetricsPanel({ metrics, byState = [], byCity = [] }) {
	if (!metrics) return null;

	const conversionRows = (metrics.conversion_by_source || []).map((row) => ({
		label: row.label,
		value: row.conversion,
	}));

	return (
		<section className="space-y-4">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Métricas avanzadas
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					Tiempos de activación, conversión y valor esperado.
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				<MetricTile
					label="Registro → Primera compra"
					value={formatDays(metrics.avg_reg_to_purchase)}
				/>
				<MetricTile
					label="Registro → Primer carrito"
					value={formatDays(metrics.avg_reg_to_cart)}
					hint="Requiere tracking de eventos de producto"
				/>
				<MetricTile
					label="Carrito → Compra"
					value={formatDays(metrics.avg_cart_to_purchase)}
					hint="Requiere timestamps de carrito histórico"
				/>
				<MetricTile
					label="Ticket promedio"
					value={formatMoney(metrics.avg_ticket)}
				/>
				<MetricTile
					label="LTV / potencial"
					value={formatMoney(metrics.potential_ltv)}
				/>
				<MetricTile
					label="Clientes recuperados"
					value={Number(metrics.recovered || 0).toLocaleString()}
				/>
				<MetricTile
					label="Clientes dormidos"
					value={Number(metrics.dormant || 0).toLocaleString()}
				/>
				<MetricTile
					label="ROI esperado"
					value="—"
					hint="Pendiente costo de adquisición por canal"
				/>
			</div>

			<div className="grid gap-4 lg:grid-cols-2">
				<SourceBarChart
					data={conversionRows}
					title="Conversión por fuente (%)"
				/>
				<ChartCard title="Top estados dormidos" description="Concentración geográfica.">
					<ul className="space-y-2">
						{(byState || []).slice(0, 8).map((row) => (
							<li
								key={row.key || row.label}
								className="flex items-center justify-between text-sm"
							>
								<span className="text-zinc-700 dark:text-zinc-300">{row.label}</span>
								<span className="tabular-nums font-medium text-zinc-900 dark:text-zinc-50">
									{Number(row.value).toLocaleString()}
								</span>
							</li>
						))}
						{(byState || []).length === 0 ? (
							<li className="text-sm text-zinc-400">Sin datos de estado</li>
						) : null}
					</ul>
				</ChartCard>
				<ChartCard title="Top ciudades dormidas" description="Direcciones registradas.">
					<ul className="space-y-2">
						{(byCity || []).slice(0, 8).map((row) => (
							<li
								key={row.key || row.label}
								className="flex items-center justify-between text-sm"
							>
								<span className="text-zinc-700 dark:text-zinc-300">{row.label}</span>
								<span className="tabular-nums font-medium text-zinc-900 dark:text-zinc-50">
									{Number(row.value).toLocaleString()}
								</span>
							</li>
						))}
						{(byCity || []).length === 0 ? (
							<li className="text-sm text-zinc-400">Sin datos de ciudad</li>
						) : null}
					</ul>
				</ChartCard>
			</div>
		</section>
	);
}
