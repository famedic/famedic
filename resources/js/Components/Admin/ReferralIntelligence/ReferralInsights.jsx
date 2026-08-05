import MarketingIntelligenceCards from "@/Components/Admin/CustomerIntelligence/MarketingIntelligenceCards";
import AiInsightsPanel from "@/Components/Admin/CustomerIntelligence/AiInsightsPanel";
import AutomationsGrid from "@/Components/Admin/CustomerIntelligence/AutomationsGrid";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import { Link } from "@inertiajs/react";

function ComparePanel({ compare }) {
	if (!compare) {
		return null;
	}

	const rows = [
		{ key: "referrals", label: "Referidos" },
		{ key: "conversion", label: "Conversión", suffix: "%" },
		{ key: "revenue", label: "Ingresos", money: true },
		{ key: "credits", label: "Créditos", money: true },
	];

	const fmt = (row, value) => {
		if (row.money) {
			return `$${Number(value || 0).toLocaleString("es-MX")} MXN`;
		}
		if (row.suffix) {
			return `${Number(value || 0).toLocaleString("es-MX")}${row.suffix}`;
		}
		return Number(value || 0).toLocaleString("es-MX");
	};

	return (
		<ChartCard
			title="Comparador"
			description="Periodo actual vs periodo de referencia."
		>
			<div className="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
				<table className="w-full text-sm">
					<thead className="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60">
						<tr>
							<th className="px-3 py-2 font-medium">Métrica</th>
							<th className="px-3 py-2 font-medium">Actual</th>
							<th className="px-3 py-2 font-medium">Anterior</th>
						</tr>
					</thead>
					<tbody>
						{rows.map((row) => (
							<tr
								key={row.key}
								className="border-t border-zinc-100 dark:border-zinc-800"
							>
								<td className="px-3 py-2 text-zinc-600 dark:text-zinc-300">
									{row.label}
								</td>
								<td className="px-3 py-2 font-semibold tabular-nums">
									{fmt(row, compare.current?.[row.key])}
								</td>
								<td className="px-3 py-2 tabular-nums text-zinc-500">
									{fmt(row, compare.previous?.[row.key])}
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>
		</ChartCard>
	);
}

function PerformancePanel({ performance = [] }) {
	return (
		<ChartCard
			title="Performance"
			description="Tiempo promedio en el embudo de referidos."
		>
			<div className="flex flex-col gap-3 sm:flex-row sm:items-stretch">
				{performance.map((step, index) => (
					<div key={step.key} className="flex flex-1 items-center gap-3">
						<div className="flex-1 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
							<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
								{step.label}
							</p>
							<p className="mt-2 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
								{step.value === null || step.value === undefined
									? "—"
									: `${step.value}`}
								{step.value !== null && step.value !== undefined ? (
									<span className="ml-1 text-sm font-medium text-zinc-400">
										{step.unit}
									</span>
								) : null}
							</p>
							<p className="mt-1 text-xs text-zinc-500">{step.hint}</p>
						</div>
						{index < performance.length - 1 ? (
							<span className="hidden text-zinc-300 sm:block">↓</span>
						) : null}
					</div>
				))}
			</div>
		</ChartCard>
	);
}

function AutomationsWithLinks({ automations = [] }) {
	return (
		<section className="space-y-3">
			<AutomationsGrid automations={automations} />
			<div className="flex flex-wrap gap-2">
				{automations
					.filter((item) => item.href)
					.map((item) => (
						<Link
							key={`link-${item.id}`}
							href={item.href}
							className="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
						>
							Abrir · {item.label}
						</Link>
					))}
			</div>
		</section>
	);
}

export default function ReferralInsights({
	marketingInsights = [],
	aiInsights,
	automations = [],
	compare,
	performance = [],
}) {
	return (
		<div className="space-y-8">
			<div className="grid gap-4 xl:grid-cols-2">
				<ComparePanel compare={compare} />
				<PerformancePanel performance={performance} />
			</div>
			<MarketingIntelligenceCards items={marketingInsights} />
			<AiInsightsPanel
				title="Referral AI Insights"
				insights={{
					...aiInsights,
					headline: aiInsights?.headline || "La IA detectó que:",
				}}
			/>
			<AutomationsWithLinks automations={automations} />
		</div>
	);
}
