import DataSourceBadge from "@/Components/Common/DataSourceBadge";
import SectionHeader from "./SectionHeader";
import { ProvenanceValue } from "./ProvenanceHelpers";
import { provenanceForSection } from "./provenanceCatalog";

export default function LaboratoriesDashboard({ laboratories = [], updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Dashboard de laboratorios"
				description="Top 10 por desempeño en el periodo."
				provenance={provenanceForSection("laboratories")}
				updatedAt={updatedAt}
			/>
			{laboratories.length === 0 ? (
				<p className="text-sm text-zinc-500">Sin datos de laboratorios.</p>
			) : (
				<div className="grid gap-3 md:grid-cols-2">
					{laboratories.map((row) => (
						<article
							key={row.laboratory}
							className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
						>
							<div className="flex flex-wrap items-start justify-between gap-2">
								<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									{row.laboratory}
								</p>
								<DataSourceBadge
									compact
									source="FAMEDIC_DATABASE"
									mode="LOCAL"
									quality="A"
									endpoint="laboratory_purchases · brand"
									updatedAt={updatedAt}
								/>
							</div>
							<dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
								<div>
									<dt className="text-zinc-400">Compras</dt>
									<dd className="font-medium tabular-nums">{row.orders}</dd>
								</div>
								<div>
									<dt className="text-zinc-400">Monto</dt>
									<dd className="font-medium">{row.amount}</dd>
								</div>
								<div>
									<dt className="text-zinc-400">Resultados</dt>
									<dd className="font-medium">
										<ProvenanceValue value={row.results} />
									</dd>
								</div>
								<div>
									<dt className="text-zinc-400">Promedio</dt>
									<dd className="font-medium">
										<ProvenanceValue value={row.average} />
									</dd>
								</div>
								<div>
									<dt className="text-zinc-400">Conversión</dt>
									<dd className="font-medium">
										<ProvenanceValue value={row.conversion} />
									</dd>
								</div>
								<div>
									<dt className="text-zinc-400">Abandonados</dt>
									<dd className="font-medium">
										<ProvenanceValue value={row.abandoned} />
									</dd>
								</div>
							</dl>
						</article>
					))}
				</div>
			)}
		</section>
	);
}
