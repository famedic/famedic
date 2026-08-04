import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import AutomationMetrics, {
	AutomationNav,
} from "@/Components/Admin/ActiveCampaign/Automations/AutomationMetrics";
import AutomationRecentRuns, {
	AutomationCatalogPreview,
} from "@/Components/Admin/ActiveCampaign/Automations/AutomationRecentRuns";

export default function AutomationDashboard({
	metrics,
	recentRunsPreview = [],
	catalogPreview = [],
	meta,
	links = {},
}) {
	return (
		<AdminLayout title="Marketing Intelligence · Automation Center">
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
						Automation Center
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Automation Center</Heading>
							<Badge color="famedic">v1.1</Badge>
							<Badge color="sky">Motor Famedic</Badge>
						</div>
						<Text className="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
							Consola de automatizaciones internas: scheduler, dispatches y
							flujos preparados sobre Timeline — no es un clon de
							ActiveCampaign.
						</Text>
						{meta?.generated_at ? (
							<p className="text-[11px] text-zinc-400">
								Actualizado {meta.generated_at}
							</p>
						) : null}
					</div>
					<AutomationNav active="dashboard" links={links} />
				</div>

				<AutomationMetrics metrics={metrics} />

				<div className="grid gap-4 xl:grid-cols-2">
					<AutomationRecentRuns runs={recentRunsPreview} />
					<AutomationCatalogPreview items={catalogPreview} />
				</div>

				<div className="flex flex-wrap gap-2">
					<Button href={links.list} outline>
						Ver listado completo
					</Button>
					<Button href={links.builder} outline>
						Abrir builder
					</Button>
				</div>
			</div>
		</AdminLayout>
	);
}
