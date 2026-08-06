import JourneyFunnelChart from "./JourneyFunnelChart";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function JourneyCompare({
	compare,
	currentFunnel = [],
	previousStages = [],
}) {
	const previousFunnel = (previousStages || []).filter((stage) =>
		[
			"registration",
			"email_verified",
			"first_login",
			"added_cart",
			"started_checkout",
			"first_purchase",
		].includes(stage.key),
	);

	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Comparador de funnels
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					{compare?.current_label} vs {compare?.previous_label}
				</p>
			</div>
			<ChartCard title="Resumen de conversión">
				<div className="grid gap-4 sm:grid-cols-2">
					<div className="rounded-lg bg-sky-50 p-4 dark:bg-sky-950/30">
						<p className="text-xs uppercase text-sky-700 dark:text-sky-300">
							Periodo actual
						</p>
						<p className="mt-1 text-3xl font-semibold tabular-nums text-sky-900 dark:text-sky-100">
							{compare?.current_conversion != null
								? `${compare.current_conversion}%`
								: "—"}
						</p>
					</div>
					<div className="rounded-lg bg-zinc-100 p-4 dark:bg-zinc-800">
						<p className="text-xs uppercase text-zinc-500">Periodo anterior</p>
						<p className="mt-1 text-3xl font-semibold tabular-nums text-zinc-800 dark:text-zinc-100">
							{compare?.previous_conversion != null
								? `${compare.previous_conversion}%`
								: "—"}
						</p>
					</div>
				</div>
			</ChartCard>
			<div className="grid gap-4 xl:grid-cols-2">
				<JourneyFunnelChart data={currentFunnel} title="Funnel actual" />
				<JourneyFunnelChart data={previousFunnel} title="Funnel periodo anterior" />
			</div>
		</section>
	);
}
