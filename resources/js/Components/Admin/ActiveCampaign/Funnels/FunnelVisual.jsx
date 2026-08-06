import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";
import clsx from "clsx";

export default function FunnelVisual({ funnel }) {
	if (!funnel) {
		return null;
	}

	const stages = funnel.stages || [];

	return (
		<section className="space-y-3">
			<div className="flex flex-wrap items-end justify-between gap-2">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						{funnel.label}
					</h2>
					<p className="max-w-3xl text-xs text-zinc-500">{funnel.description}</p>
				</div>
				<div className="flex flex-wrap gap-1.5">
					{(funnel.timeline_map || []).map((t) => (
						<Badge key={t} color="zinc">
							{t}
						</Badge>
					))}
				</div>
			</div>

			<ChartCard
				title="Visualización del embudo"
				description="Anchos relativos solo para etapas con volumen conocido. El resto se marca con honestidad de dato."
			>
				<div className="space-y-3">
					{stages.map((stage, index) => (
						<div key={stage.id} className="space-y-1.5">
							<div className="flex flex-wrap items-center justify-between gap-2">
								<div className="flex flex-wrap items-center gap-2">
									<span className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										{index + 1}.
									</span>
									<span className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
										{stage.label}
									</span>
									<AnalyticsTruthBadge truth={stage.truth} />
								</div>
								<span className="text-sm font-semibold tabular-nums text-zinc-700 dark:text-zinc-200">
									{stage.users_label}
								</span>
							</div>
							<div className="h-9 w-full rounded-lg bg-zinc-100 dark:bg-zinc-800">
								{stage.width_pct != null ? (
									<div
										className={clsx(
											"flex h-full items-center rounded-lg px-3 text-xs font-medium text-white transition-all",
											stage.truth === "disponible"
												? "bg-emerald-600"
												: "bg-sky-600/90",
										)}
										style={{ width: `${stage.width_pct}%` }}
									>
										{stage.source && stage.source !== "—"
											? stage.source
											: null}
									</div>
								) : (
									<div className="flex h-full items-center rounded-lg border border-dashed border-zinc-300 px-3 text-xs text-zinc-400 dark:border-zinc-600">
										{stage.users_label}
									</div>
								)}
							</div>
							{stage.hint ? (
								<Text className="text-[11px] text-zinc-400">{stage.hint}</Text>
							) : null}
							{index < stages.length - 1 ? (
								<div className="flex justify-center text-zinc-300 dark:text-zinc-600">
									↓
								</div>
							) : null}
						</div>
					))}
				</div>
			</ChartCard>
		</section>
	);
}
