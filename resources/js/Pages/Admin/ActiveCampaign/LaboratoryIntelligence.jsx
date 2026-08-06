import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import LaboratorySummary from "@/Components/Admin/ActiveCampaign/Laboratory/LaboratorySummary";
import LaboratoryToolbar from "@/Components/Admin/ActiveCampaign/Laboratory/LaboratoryToolbar";
import LaboratoryTopLabs from "@/Components/Admin/ActiveCampaign/Laboratory/LaboratoryTopLabs";
import LaboratoryTopStudies from "@/Components/Admin/ActiveCampaign/Laboratory/LaboratoryTopStudies";
import LaboratoryCharts from "@/Components/Admin/ActiveCampaign/Laboratory/LaboratoryCharts";
import LaboratoryDecision from "@/Components/Admin/ActiveCampaign/Laboratory/LaboratoryDecision";

function DeferredCharts() {
	const { charts } = usePage().props;
	return <LaboratoryCharts charts={charts || null} />;
}

export default function LaboratoryIntelligence({
	filters,
	summary,
	kpis,
	top_laboratories,
	top_studies,
	insights,
	recommendations,
	risks,
	suggested_actions,
	gaps,
	meta,
}) {
	return (
		<AdminLayout title="Marketing Intelligence · Laboratory Intelligence">
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
						Laboratory Intelligence
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Laboratory Intelligence</Heading>
						<Badge color="famedic">Lab</Badge>
						<Badge color="sky">Negocio</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						{meta?.purpose ||
							"Consola ejecutiva del comportamiento completo del negocio de laboratorios."}
					</Text>
					{meta?.source_of_truth ? (
						<p className="text-[11px] text-zinc-400">
							Fuente: {meta.source_of_truth}
						</p>
					) : null}
					{(meta?.timeline_map || []).length ? (
						<div className="flex flex-wrap gap-1.5">
							{meta.timeline_map.map((t) => (
								<Badge key={t} color="zinc">
									{t}
								</Badge>
							))}
						</div>
					) : null}
				</div>

				<LaboratoryToolbar filters={filters} meta={meta} />

				<LaboratorySummary summary={summary} kpis={kpis} />

				<LaboratoryTopLabs rows={top_laboratories} />

				<LaboratoryTopStudies topStudies={top_studies} />

				<Deferred data="charts" fallback={<LaboratoryCharts charts={null} />}>
					<DeferredCharts />
				</Deferred>

				<LaboratoryDecision
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
