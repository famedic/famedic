import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import {
	Bar,
	BarChart,
	ResponsiveContainer,
	Tooltip,
	XAxis,
	YAxis,
	Cell,
} from "recharts";

const FUNNEL_COLORS = ["#0f766e", "#2563eb", "#7c3aed", "#059669"];

function FunnelVisual({ steps = [] }) {
	const max = Math.max(1, ...steps.map((s) => Number(s.count) || 0));

	return (
		<ol className="space-y-3">
			{steps.map((step, index) => {
				const width = Math.max(12, Math.round(((Number(step.count) || 0) / max) * 100));
				return (
					<li key={step.id} className="space-y-1.5">
						<div className="flex items-baseline justify-between gap-2 text-sm">
							<span className="font-medium text-zinc-800 dark:text-zinc-100">
								{step.label}
							</span>
							<span className="tabular-nums text-zinc-500">{step.count}</span>
						</div>
						<div className="h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
							<div
								className="h-full rounded-full transition-all"
								style={{
									width: `${width}%`,
									backgroundColor: FUNNEL_COLORS[index % FUNNEL_COLORS.length],
								}}
							/>
						</div>
						{index < steps.length - 1 && (
							<p className="text-center text-[10px] text-zinc-300">↓</p>
						)}
					</li>
				);
			})}
		</ol>
	);
}

export default function OverviewModule({ data }) {
	const kpis = data?.kpis || [];
	const funnel = data?.funnel || [];
	const conversion = data?.conversion || {};

	return (
		<div className="space-y-6">
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{kpis.map((kpi) => (
					<BillingMetricCard
						key={kpi.id}
						label={kpi.label}
						value={kpi.value}
						hint={kpi.hint}
						tone={
							kpi.tone === "green"
								? "lime"
								: kpi.tone === "orange"
									? "amber"
									: kpi.tone === "blue"
										? "sky"
										: "default"
						}
					/>
				))}
			</div>

			<div className="grid gap-4 lg:grid-cols-2">
				<ChartCard
					title="Conversiones"
					description="Interpretación → Order → Checkout → Compra"
				>
					<FunnelVisual steps={funnel} />
					<dl className="mt-5 grid grid-cols-2 gap-3 border-t border-zinc-100 pt-4 text-xs dark:border-zinc-800">
						<div>
							<dt className="text-zinc-400">Interp. → Order</dt>
							<dd className="font-semibold tabular-nums">
								{conversion.interpretation_to_order != null
									? `${conversion.interpretation_to_order}%`
									: "—"}
							</dd>
						</div>
						<div>
							<dt className="text-zinc-400">Order → Checkout</dt>
							<dd className="font-semibold tabular-nums">
								{conversion.order_to_checkout != null
									? `${conversion.order_to_checkout}%`
									: "—"}
							</dd>
						</div>
						<div>
							<dt className="text-zinc-400">Checkout → Compra</dt>
							<dd className="font-semibold tabular-nums">
								{conversion.checkout_to_purchase != null
									? `${conversion.checkout_to_purchase}%`
									: "—"}
							</dd>
						</div>
						<div>
							<dt className="text-zinc-400">Interp. → Compra</dt>
							<dd className="font-semibold tabular-nums">
								{conversion.interpretation_to_purchase != null
									? `${conversion.interpretation_to_purchase}%`
									: "—"}
							</dd>
						</div>
					</dl>
				</ChartCard>

				<ChartCard title="Embudo (volumen)" description="Conteo por etapa">
					<div className="h-56">
						<ResponsiveContainer width="100%" height="100%">
							<BarChart data={funnel}>
								<XAxis dataKey="label" tick={{ fontSize: 11 }} />
								<YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
								<Tooltip />
								<Bar dataKey="count" radius={[6, 6, 0, 0]}>
									{funnel.map((entry, index) => (
										<Cell
											key={entry.id}
											fill={FUNNEL_COLORS[index % FUNNEL_COLORS.length]}
										/>
									))}
								</Bar>
							</BarChart>
						</ResponsiveContainer>
					</div>
				</ChartCard>
			</div>
		</div>
	);
}
