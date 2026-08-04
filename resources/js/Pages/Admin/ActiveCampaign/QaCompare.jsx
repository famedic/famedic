import { useState } from "react";
import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import QaSummary from "@/Components/Admin/ActiveCampaign/QaCompare/QaSummary";
import QaToolbar from "@/Components/Admin/ActiveCampaign/QaCompare/QaToolbar";
import QaTable from "@/Components/Admin/ActiveCampaign/QaCompare/QaTable";
import QaDrawer from "@/Components/Admin/ActiveCampaign/QaCompare/QaDrawer";
import QaExecutive from "@/Components/Admin/ActiveCampaign/QaCompare/QaExecutive";

function DeferredExecutive() {
	const { executive } = usePage().props;
	return <QaExecutive executive={executive || null} />;
}

function QuickActions({ actions = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Acciones rápidas
				</h2>
				<p className="text-xs text-zinc-500">
					Atajos a consolas de gobernanza relacionadas.
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

export default function QaCompare({
	filters,
	filterOptions,
	summary,
	rows = [],
	actions,
	meta,
}) {
	const [selected, setSelected] = useState(null);

	return (
		<AdminLayout title="Marketing Intelligence · QA vs Production">
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
						QA vs Production
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>QA vs Production</Heading>
						<Badge color="famedic">Platform Governance</Badge>
						<Badge color="zinc">Solo lectura</Badge>
						{meta?.current_environment ? (
							<Badge color="sky">{meta.current_environment}</Badge>
						) : null}
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						{meta?.purpose ||
							"Comparador de configuración entre ambientes para Desarrollo, QA y DevOps."}
					</Text>
					{meta?.source_of_truth ? (
						<p className="text-[11px] text-zinc-400">
							Fuente: {meta.source_of_truth}
						</p>
					) : null}
					{meta?.note ? (
						<p className="text-[11px] text-zinc-400">{meta.note}</p>
					) : null}
					{(meta?.qa_role || meta?.prod_role) && (
						<p className="text-[11px] text-zinc-500">
							QA: {meta.qa_role} · Producción: {meta.prod_role}
						</p>
					)}
				</div>

				<QaSummary summary={summary} />

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
					<QaToolbar filters={filters} filterOptions={filterOptions} />
				</div>

				<QaTable
					rows={rows}
					total={meta?.total ?? rows.length}
					selectedId={selected?.id}
					onSelect={setSelected}
				/>

				<QuickActions actions={actions} />

				<Deferred data="executive" fallback={<QaExecutive executive={null} />}>
					<DeferredExecutive />
				</Deferred>
			</div>

			<QaDrawer
				open={Boolean(selected)}
				row={selected}
				onClose={() => setSelected(null)}
			/>
		</AdminLayout>
	);
}
