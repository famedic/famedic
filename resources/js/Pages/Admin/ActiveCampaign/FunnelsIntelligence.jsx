import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import FunnelsSummary from "@/Components/Admin/ActiveCampaign/Funnels/FunnelsSummary";
import FunnelsToolbar from "@/Components/Admin/ActiveCampaign/Funnels/FunnelsToolbar";
import FunnelVisual from "@/Components/Admin/ActiveCampaign/Funnels/FunnelVisual";
import FunnelsMetricsTable from "@/Components/Admin/ActiveCampaign/Funnels/FunnelsMetricsTable";
import FunnelsCharts from "@/Components/Admin/ActiveCampaign/Funnels/FunnelsCharts";
import FunnelsDecision from "@/Components/Admin/ActiveCampaign/Funnels/FunnelsDecision";

function DeferredCharts() {
	const { charts } = usePage().props;
	return <FunnelsCharts charts={charts || null} />;
}

export default function FunnelsIntelligence({
	filters,
	funnelOptions,
	summary,
	funnel,
	metrics,
	insights,
	recommendations,
	risks,
	suggested_actions,
	gaps,
	meta,
}) {
	return (
		<AdminLayout title="Marketing Intelligence · Funnels Intelligence">
			<div className="space-y-6 pb-6">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<Link
						href={route("admin.activecampaign.dashboard")}
						className="font-medium text-zinc-400 transition hover:text-famedic-light"
					>
						Marketing Intelligence
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300 dark:text-zinc-600" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Funnels Intelligence
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Funnels Intelligence</Heading>
						<Badge color="famedic">Conversión</Badge>
						<Badge color="sky">Recorridos</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						{meta?.purpose ||
							"Embudos de conversión sobre recorridos reales de pacientes en Famedic."}
					</Text>
					{meta?.source_of_truth ? (
						<p className="text-[11px] text-zinc-400">
							Fuente: {meta.source_of_truth}
						</p>
					) : null}
				</div>

				<FunnelsSummary summary={summary} />

				<FunnelsToolbar
					filters={filters}
					funnelOptions={funnelOptions}
					meta={meta}
				/>

				<FunnelVisual funnel={funnel} />

				<FunnelsMetricsTable metrics={metrics} />

				<Deferred data="charts" fallback={<FunnelsCharts charts={null} />}>
					<DeferredCharts />
				</Deferred>

				<FunnelsDecision
					insights={insights}
					recommendations={recommendations}
					risks={risks}
					suggested_actions={suggested_actions}
					gaps={gaps}
				/>
			</div>
		</AdminLayout>
	);
}
