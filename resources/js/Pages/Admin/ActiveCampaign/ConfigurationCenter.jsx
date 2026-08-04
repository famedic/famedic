import { useState } from "react";
import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import ConfigSummary from "@/Components/Admin/ActiveCampaign/Configuration/ConfigSummary";
import ConfigToolbar from "@/Components/Admin/ActiveCampaign/Configuration/ConfigToolbar";
import ConfigTable from "@/Components/Admin/ActiveCampaign/Configuration/ConfigTable";
import ConfigDrawer from "@/Components/Admin/ActiveCampaign/Configuration/ConfigDrawer";
import ConfigExecutive from "@/Components/Admin/ActiveCampaign/Configuration/ConfigExecutive";

function DeferredExecutive() {
	const { executive } = usePage().props;
	return <ConfigExecutive executive={executive || null} />;
}

function QuickActions({ actions = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Acciones rápidas
				</h2>
				<p className="text-xs text-zinc-500">
					Atajos a consolas de gobernanza e integración.
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

export default function ConfigurationCenter({
	filters,
	filterOptions,
	summary,
	configs = [],
	actions,
	meta,
}) {
	const [selected, setSelected] = useState(null);

	return (
		<AdminLayout title="Marketing Intelligence · Configuration Center">
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
						Configuration Center
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Configuration Center</Heading>
						<Badge color="famedic">Platform Governance</Badge>
						<Badge color="zinc">Solo lectura</Badge>
						{meta?.environment ? (
							<Badge color="sky">{meta.environment}</Badge>
						) : null}
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						{meta?.purpose ||
							"Consola para visualizar y analizar la configuración de la plataforma."}
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

				<ConfigSummary summary={summary} />

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
					<ConfigToolbar filters={filters} filterOptions={filterOptions} />
				</div>

				<ConfigTable
					configs={configs}
					total={meta?.total ?? configs.length}
					selectedId={selected?.id}
					onSelect={setSelected}
				/>

				<QuickActions actions={actions} />

				<Deferred
					data="executive"
					fallback={<ConfigExecutive executive={null} />}
				>
					<DeferredExecutive />
				</Deferred>
			</div>

			<ConfigDrawer
				open={Boolean(selected)}
				config={selected}
				onClose={() => setSelected(null)}
			/>
		</AdminLayout>
	);
}
