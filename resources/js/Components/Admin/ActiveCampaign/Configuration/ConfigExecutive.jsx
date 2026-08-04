import {
	Bar,
	BarChart,
	CartesianGrid,
	Legend,
	ResponsiveContainer,
	Tooltip,
	XAxis,
	YAxis,
} from "recharts";
import { ChartCard, DASHBOARD_COLORS } from "@/Components/Admin/CartsDashboard/chartTheme";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

function Skeleton() {
	return (
		<div className="grid gap-4 xl:grid-cols-2" aria-busy="true">
			{Array.from({ length: 4 }).map((_, i) => (
				<div
					key={i}
					className="h-48 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800"
				/>
			))}
		</div>
	);
}

function MiniBar({ title, description, data, dataKey = "value", color }) {
	return (
		<ChartCard title={title} description={description}>
			{data?.length ? (
				<div className="h-48">
					<ResponsiveContainer width="100%" height="100%">
						<BarChart data={data}>
							<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
							<XAxis dataKey="label" tick={{ fontSize: 11 }} />
							<YAxis allowDecimals={false} />
							<Tooltip />
							<Bar
								dataKey={dataKey}
								fill={color || DASHBOARD_COLORS.blue}
								radius={[4, 4, 0, 0]}
							/>
						</BarChart>
					</ResponsiveContainer>
				</div>
			) : (
				<p className="text-sm text-zinc-500">Sin datos.</p>
			)}
		</ChartCard>
	);
}

export default function ConfigExecutive({ executive = null }) {
	if (!executive) {
		return (
			<section className="space-y-3">
				<div className="flex items-center gap-2">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Panel ejecutivo
					</h2>
					<AnalyticsTruthBadge truth="proxy" />
				</div>
				<Skeleton />
			</section>
		);
	}

	return (
		<section className="space-y-4">
			<div className="flex flex-wrap items-center gap-2">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Panel ejecutivo
				</h2>
				<AnalyticsTruthBadge truth={executive.truth || "disponible"} />
			</div>
			{executive.note ? (
				<p className="text-xs text-zinc-500">{executive.note}</p>
			) : null}

			<div className="grid gap-4 xl:grid-cols-2">
				<MiniBar
					title="Configuraciones por categoría"
					description="Distribución del inventario gobernado."
					data={executive.by_category}
					color={DASHBOARD_COLORS.blue}
				/>
				<ChartCard
					title="Feature Flags"
					description="1 = activo · 0 = desactivado."
				>
					{(executive.feature_flags || []).length ? (
						<div className="h-48">
							<ResponsiveContainer width="100%" height="100%">
								<BarChart data={executive.feature_flags}>
									<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
									<XAxis dataKey="label" tick={{ fontSize: 11 }} />
									<YAxis allowDecimals={false} domain={[0, 1]} />
									<Tooltip />
									<Legend />
									<Bar
										dataKey="value"
										name="Activo"
										fill={DASHBOARD_COLORS.green}
										radius={[4, 4, 0, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
					) : (
						<p className="text-sm text-zinc-500">Sin datos.</p>
					)}
				</ChartCard>
				<MiniBar
					title="Integraciones configuradas"
					description="1 = OK · 0 = pendiente/crítica/desactivada."
					data={executive.integrations}
					color={DASHBOARD_COLORS.purple}
				/>
				<MiniBar
					title="Configuraciones críticas"
					description="Estado OK (1) vs no OK (0) en claves críticas."
					data={executive.critical}
					color={DASHBOARD_COLORS.orange}
				/>
			</div>

			{(executive.gaps || []).length ? (
				<div className="rounded-xl border border-dashed border-zinc-300 bg-white/70 p-4 dark:border-zinc-600 dark:bg-zinc-900/50">
					<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
						Capacidades pendientes
					</h3>
					<ul className="mt-3 space-y-2">
						{executive.gaps.map((gap) => (
							<li
								key={gap.label}
								className="flex flex-wrap items-start justify-between gap-2 text-sm"
							>
								<div className="min-w-0">
									<p className="font-medium text-zinc-800 dark:text-zinc-200">
										{gap.label}
									</p>
									<p className="text-xs text-zinc-500">{gap.reason}</p>
								</div>
								<AnalyticsTruthBadge truth={gap.truth} />
							</li>
						))}
					</ul>
				</div>
			) : null}
		</section>
	);
}
