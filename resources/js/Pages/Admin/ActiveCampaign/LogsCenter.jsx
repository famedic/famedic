import { useState } from "react";
import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import LogsSummary from "@/Components/Admin/ActiveCampaign/Logs/LogsSummary";
import LogsToolbar from "@/Components/Admin/ActiveCampaign/Logs/LogsToolbar";
import LogsTable from "@/Components/Admin/ActiveCampaign/Logs/LogsTable";
import LogsDrawer from "@/Components/Admin/ActiveCampaign/Logs/LogsDrawer";
import LogsExecutive from "@/Components/Admin/ActiveCampaign/Logs/LogsExecutive";

function DeferredExecutive() {
	const { executive } = usePage().props;
	return <LogsExecutive executive={executive || null} />;
}

function QuickActions({ actions = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Acciones rápidas
				</h2>
				<p className="text-xs text-zinc-500">
					Atajos a consolas relacionadas con la investigación.
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

export default function LogsCenter({
	filters,
	filterOptions,
	summary,
	logs = [],
	actions,
	meta,
}) {
	const [selected, setSelected] = useState(null);

	return (
		<AdminLayout title="Marketing Intelligence · Logs Center">
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
						Logs Center
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Logs Center</Heading>
						<Badge color="famedic">Administration</Badge>
						<Badge color="zinc">Investigación</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						{meta?.purpose ||
							"Consola para investigar incidentes con información ya existente."}
					</Text>
					{meta?.source_of_truth ? (
						<p className="text-[11px] text-zinc-400">
							Fuente: {meta.source_of_truth}
						</p>
					) : null}
					{meta?.note ? (
						<p className="text-[11px] text-zinc-400">{meta.note}</p>
					) : null}
				</div>

				<LogsSummary summary={summary} />

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
					<LogsToolbar filters={filters} filterOptions={filterOptions} />
				</div>

				<LogsTable
					logs={logs}
					total={meta?.total ?? logs.length}
					selectedId={selected?.id}
					onSelect={setSelected}
				/>

				<QuickActions actions={actions} />

				<Deferred
					data="executive"
					fallback={<LogsExecutive executive={null} />}
				>
					<DeferredExecutive />
				</Deferred>
			</div>

			<LogsDrawer
				open={Boolean(selected)}
				log={selected}
				onClose={() => setSelected(null)}
			/>
		</AdminLayout>
	);
}
