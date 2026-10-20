import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import ResultsCenterFilters from "@/Components/Admin/LaboratoryResultsCenter/ResultsCenterFilters";
import ResultsCenterTable from "@/Components/Admin/LaboratoryResultsCenter/ResultsCenterTable";
import ResultsPipelineTimeline from "@/Components/Admin/LaboratoryResultsCenter/ResultsPipelineTimeline";
import ResultsErrorPanel from "@/Components/Admin/LaboratoryResultsCenter/ResultsErrorPanel";
import AiExecutionSummary from "@/Components/Admin/LaboratoryResultsCenter/AiExecutionSummary";
import { formatDateTime, statusMeta } from "@/Components/Admin/LaboratoryResultsCenter/status";

function Kpis({ summary = [] }) {
	if (!summary.length) return null;

	return (
		<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
			{summary.map((card) => (
				<BillingMetricCard
					key={card.id}
					label={card.label}
					value={card.value}
					tone={card.tone || "default"}
				/>
			))}
		</div>
	);
}

function DetailHeader({ detail }) {
	const meta = statusMeta(detail.purchase.status);

	return (
		<div className="flex flex-wrap items-start justify-between gap-3">
			<div>
				<Heading>Compra #{detail.purchase.id}</Heading>
				<div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-zinc-500">
					<span>Folio {detail.purchase.folio || "—"}</span>
					<span>·</span>
					<span>{formatDateTime(detail.purchase.created_at)}</span>
					<Badge color={meta.color}>{meta.label}</Badge>
				</div>
			</div>
			<Button outline href={route("admin.laboratory-results-center.index")}>
				Volver al listado
			</Button>
		</div>
	);
}

export default function Center({ filters = {}, filterOptions = {}, summary = [], purchases = null, detail = null }) {
	return (
		<AdminLayout title="Centro de resultados">
			<div className="space-y-6 pb-8">
				{detail ? (
					<>
						<DetailHeader detail={detail} />
						<ResultsPipelineTimeline pipeline={detail.pipeline} />
						<ResultsErrorPanel errors={detail.errors} />
						<AiExecutionSummary executions={detail.ai} />
					</>
				) : (
					<>
						<div className="flex flex-wrap items-center justify-between gap-3">
							<div>
								<Heading>Centro de resultados</Heading>
								<p className="mt-1 text-sm text-zinc-500">
									Monitoreo read-only del pipeline de resultados por compra.
								</p>
							</div>
							<Badge color="zinc">Read-only</Badge>
						</div>
						<Kpis summary={summary} />
						<ResultsCenterFilters filters={filters} filterOptions={filterOptions} />
						<ResultsCenterTable purchases={purchases} />
					</>
				)}
			</div>
		</AdminLayout>
	);
}
