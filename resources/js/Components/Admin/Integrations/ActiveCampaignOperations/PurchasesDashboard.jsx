import DataSourceBadge from "@/Components/Common/DataSourceBadge";
import MetricCard from "./MetricCard";
import OpsChart from "./OpsChart";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

const HYBRID = {
	source: "HYBRID",
	mode: "CALCULATED",
	quality: "B",
	endpoint: "lab + pharmacy + memberships",
};

const INSTR = {
	source: "PROXY",
	mode: "ESTIMATED",
	quality: "F",
	endpoint: "reembolsos no instrumentados",
};

export default function PurchasesDashboard({ purchases = {}, analytics, updatedAt = null }) {
	const series = analytics?.purchases_by_day || { labels: [], values: [] };

	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Dashboard de compras"
				description="GMV consolidado Ecommerce Intelligence."
				provenance={provenanceForSection("purchases")}
				updatedAt={updatedAt}
			/>
			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
				<MetricCard label="Ventas / GMV" value={purchases.sales} tone="emerald" provenance={HYBRID} updatedAt={updatedAt} />
				<MetricCard label="Pedidos" value={purchases.orders} tone="sky" provenance={HYBRID} />
				<MetricCard label="Monto total" value={purchases.total_amount} provenance={HYBRID} />
				<MetricCard label="Ticket promedio" value={purchases.avg_ticket} provenance={HYBRID} />
				<MetricCard label="Exitosas" value={purchases.successful} tone="emerald" provenance={HYBRID} />
				<MetricCard label="Fallidas" value={purchases.failed} tone="rose" provenance={{ ...HYBRID, quality: "D", mode: "ESTIMATED" }} />
				<MetricCard label="Reembolsos" value={purchases.refunds} provenance={INSTR} />
			</div>
			<div className="mt-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
				<div className="mb-3 flex flex-wrap items-center justify-between gap-2">
					<p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
						Compras por día
					</p>
					<DataSourceBadge
						compact
						source="HYBRID"
						mode="CALCULATED"
						quality="B"
						endpoint="ecommerce seriesByDay"
						updatedAt={updatedAt}
					/>
				</div>
				<OpsChart
					type="line"
					labels={series.labels}
					values={series.values}
					label="Compras"
					color="#0284c7"
				/>
			</div>
		</section>
	);
}
