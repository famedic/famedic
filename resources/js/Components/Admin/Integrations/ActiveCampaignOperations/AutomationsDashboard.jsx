import DataSourceBadge from "@/Components/Common/DataSourceBadge";
import SectionHeader from "./SectionHeader";
import { ProvenanceValue } from "./ProvenanceHelpers";
import { provenanceForSection } from "./provenanceCatalog";

export default function AutomationsDashboard({ automations = [], updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Dashboard de automations"
				description="Catálogo interno y ejecuciones instrumentadas vía dispatches."
				provenance={provenanceForSection("automations")}
				updatedAt={updatedAt}
			/>
			{automations.length === 0 ? (
				<p className="text-sm text-zinc-500">Sin automatizaciones en catálogo.</p>
			) : (
				<div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
					<table className="min-w-full text-left text-xs">
						<thead className="border-b border-zinc-200 bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:bg-zinc-950/60">
							<tr>
								<th className="px-3 py-2 font-medium">Nombre</th>
								<th className="px-3 py-2 font-medium">Ejecuciones</th>
								<th className="px-3 py-2 font-medium">Última</th>
								<th className="px-3 py-2 font-medium">Errores</th>
								<th className="px-3 py-2 font-medium">Tiempo prom.</th>
								<th className="px-3 py-2 font-medium">Estado</th>
								<th className="px-3 py-2 font-medium">Origen</th>
							</tr>
						</thead>
						<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
							{automations.map((row) => (
								<tr
									key={row.name}
									className="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
								>
									<td className="px-3 py-2.5 font-medium text-zinc-800 dark:text-zinc-100">
										{row.name}
									</td>
									<td className="px-3 py-2.5 tabular-nums">{row.runs}</td>
									<td className="px-3 py-2.5 text-zinc-500">
										<ProvenanceValue value={row.last_run} />
									</td>
									<td className="px-3 py-2.5 tabular-nums text-rose-600">
										{row.errors}
									</td>
									<td className="px-3 py-2.5 text-zinc-500">
										<ProvenanceValue value={row.avg_time} />
									</td>
									<td className="px-3 py-2.5">
										<ProvenanceValue value={row.status} />
									</td>
									<td className="px-3 py-2.5">
										<DataSourceBadge
											compact
											source="HYBRID"
											mode="LOCAL"
											quality="B"
											endpoint="AutomationCenter + dispatches"
											updatedAt={updatedAt}
											showMode={false}
										/>
									</td>
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}
		</section>
	);
}
