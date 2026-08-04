import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import MembershipSummary from "@/Components/Admin/ActiveCampaign/Membership/MembershipSummary";
import MembershipToolbar from "@/Components/Admin/ActiveCampaign/Membership/MembershipToolbar";
import MembershipDistribution from "@/Components/Admin/ActiveCampaign/Membership/MembershipDistribution";
import MembershipCharts from "@/Components/Admin/ActiveCampaign/Membership/MembershipCharts";
import MembershipDecision from "@/Components/Admin/ActiveCampaign/Membership/MembershipDecision";

function DeferredCharts() {
	const { charts } = usePage().props;
	return <MembershipCharts charts={charts || null} />;
}

export default function MembershipIntelligence({
	filters,
	summary,
	kpis,
	distribution,
	insights,
	recommendations,
	risks,
	suggested_actions,
	gaps,
	meta,
}) {
	return (
		<AdminLayout title="Marketing Intelligence · Membership Intelligence">
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
						Membership Intelligence
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Membership Intelligence</Heading>
						<Badge color="famedic">Lifecycle</Badge>
						<Badge color="sky">Membresías</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						{meta?.purpose ||
							"Consola ejecutiva del comportamiento completo del negocio de membresías."}
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

				<MembershipToolbar filters={filters} meta={meta} />

				<MembershipSummary summary={summary} kpis={kpis} />

				<MembershipDistribution distribution={distribution} />

				<Deferred data="charts" fallback={<MembershipCharts charts={null} />}>
					<DeferredCharts />
				</Deferred>

				<MembershipDecision
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
