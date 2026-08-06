import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { StatusBadge } from "./AutomationMetrics";

export default function AutomationRecentRuns({ runs = [], title = "Últimas ejecuciones" }) {
	return (
		<ChartCard
			title={title}
			description="Dispatches locales recientes (Event Center / pipeline AC)."
		>
			{!runs.length ? (
				<Text className="text-sm text-zinc-500">Sin ejecuciones recientes.</Text>
			) : (
				<ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
					{runs.map((run) => (
						<li
							key={run.id}
							className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
						>
							<div className="min-w-0">
								<p className="font-medium text-zinc-900 dark:text-zinc-50">
									{run.label}
								</p>
								<p className="truncate text-[11px] text-zinc-400">
									{run.email} · {run.when}
								</p>
							</div>
							<Badge
								color={run.status === "failed" ? "red" : "zinc"}
							>
								{run.status_label}
							</Badge>
						</li>
					))}
				</ul>
			)}
		</ChartCard>
	);
}

export function AutomationCatalogPreview({ items = [] }) {
	return (
		<ChartCard
			title="Automatizaciones"
			description="Pipelines reales + flujos preparados sobre Timeline."
		>
			<ul className="space-y-3">
				{items.map((item) => (
					<li
						key={item.id}
						className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
					>
						<div className="min-w-0">
							<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
								{item.name}
							</p>
							<p className="text-[11px] text-zinc-400">
								{item.trigger_label}
							</p>
						</div>
						<div className="flex items-center gap-2">
							<StatusBadge
								status={item.status}
								label={item.status_label}
							/>
							<Button href={item.detail_url} plain>
								Ver
							</Button>
						</div>
					</li>
				))}
			</ul>
		</ChartCard>
	);
}
