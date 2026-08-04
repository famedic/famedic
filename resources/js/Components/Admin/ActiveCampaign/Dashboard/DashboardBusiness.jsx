import KpiCards from "@/Components/Admin/CartsDashboard/KpiCards";
import SectionHeading from "./SectionHeading";
import TruthBadge from "./TruthBadge";

export default function DashboardBusiness({ kpis = [], previousPeriod }) {
	return (
		<section className="space-y-4">
			<SectionHeading
				eyebrow="Negocio"
				title="Indicadores clave"
				description={
					previousPeriod
						? `Comparado contra ${previousPeriod.start_date} — ${previousPeriod.end_date}`
						: "Comparado contra el periodo anterior equivalente."
				}
			/>

			<div className="flex flex-wrap gap-2">
				{kpis.map((kpi) => (
					<div key={kpi.id} className="inline-flex items-center gap-1.5 text-xs text-zinc-500">
						<span className="font-medium text-zinc-700 dark:text-zinc-300">
							{kpi.label}
						</span>
						<TruthBadge truth={kpi.truth} />
					</div>
				))}
			</div>

			<KpiCards
				kpis={kpis}
				columnsClassName="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
			/>
		</section>
	);
}
