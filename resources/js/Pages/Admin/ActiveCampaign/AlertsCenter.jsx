import { useState } from "react";
import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import AlertsSummary from "@/Components/Admin/ActiveCampaign/Alerts/AlertsSummary";
import AlertsToolbar from "@/Components/Admin/ActiveCampaign/Alerts/AlertsToolbar";
import AlertsTable from "@/Components/Admin/ActiveCampaign/Alerts/AlertsTable";
import AlertsDrawer from "@/Components/Admin/ActiveCampaign/Alerts/AlertsDrawer";
import AlertsExecutive from "@/Components/Admin/ActiveCampaign/Alerts/AlertsExecutive";

function DeferredExecutive() {
	const { executive } = usePage().props;
	return <AlertsExecutive executive={executive || null} />;
}

function QuickActions({ actions = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Acciones rápidas
				</h2>
				<p className="text-xs text-zinc-500">
					Atajos a consolas del Intelligence Platform.
				</p>
			</div>
			<div className="flex flex-wrap gap-2">
				{actions.map((action) =>
					action.enabled && action.href ? (
						<Button key={action.id} href={action.href} outline>
							{action.label}
						</Button>
					) : (
						<Button key={action.id} outline disabled>
							{action.label}
						</Button>
					),
				)}
			</div>
		</section>
	);
}

export default function AlertsCenter({
	filters,
	filterOptions,
	summary,
	alerts = [],
	actions,
	meta,
}) {
	const [selected, setSelected] = useState(null);

	return (
		<AdminLayout title="Marketing Intelligence · Alerts Center">
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
						Alerts Center
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Alerts Center</Heading>
						<Badge color="famedic">Administration</Badge>
						<Badge color="amber">Prioridades</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						{meta?.purpose ||
							"Consola principal para identificar situaciones que requieren atención."}
					</Text>
					{meta?.source_of_truth ? (
						<p className="text-[11px] text-zinc-400">
							Fuente: {meta.source_of_truth}
						</p>
					) : null}
				</div>

				<AlertsSummary summary={summary} />

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
					<AlertsToolbar filters={filters} filterOptions={filterOptions} />
				</div>

				<AlertsTable
					alerts={alerts}
					total={meta?.total ?? alerts.length}
					selectedId={selected?.id}
					onSelect={setSelected}
				/>

				<QuickActions actions={actions} />

				<Deferred
					data="executive"
					fallback={<AlertsExecutive executive={null} />}
				>
					<DeferredExecutive />
				</Deferred>
			</div>

			<AlertsDrawer
				open={Boolean(selected)}
				alert={selected}
				onClose={() => setSelected(null)}
			/>
		</AdminLayout>
	);
}
