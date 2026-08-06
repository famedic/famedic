import { useState } from "react";
import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import EventSummary from "@/Components/Admin/ActiveCampaign/Events/EventSummary";
import EventCenterToolbar from "@/Components/Admin/ActiveCampaign/Events/EventCenterToolbar";
import EventTable from "@/Components/Admin/ActiveCampaign/Events/EventTable";
import EventDrawer from "@/Components/Admin/ActiveCampaign/Events/EventDrawer";

function DeferredEvents({ selectedId, onSelect }) {
	const { events } = usePage().props;
	return (
		<EventTable
			events={events || null}
			loading={!events}
			selectedId={selectedId}
			onSelect={onSelect}
		/>
	);
}

function QuickActions({ actions = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Acciones rápidas
				</h2>
				<p className="text-xs text-zinc-500">
					Atajos a pantallas existentes del Marketing Intelligence Center.
				</p>
			</div>
			<div className="flex flex-wrap gap-2">
				{actions.map((action) =>
					action.enabled && action.href ? (
						<Button key={action.id} href={action.href} outline>
							{action.label}
						</Button>
					) : (
						<div key={action.id} className="space-y-1">
							<Button outline disabled title={action.hint || "No disponible"}>
								{action.label}
							</Button>
							{action.hint ? (
								<Text className="max-w-xs text-[11px] text-zinc-400">
									{action.hint}
								</Text>
							) : null}
						</div>
					),
				)}
			</div>
		</section>
	);
}

export default function EventCenter({
	filters,
	filterOptions,
	summary,
	actions,
	contactOptions,
	meta,
}) {
	const [selected, setSelected] = useState(null);

	return (
		<AdminLayout title="Marketing Intelligence · Event Center">
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
						Event Center
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Event Center</Heading>
						<Badge color="famedic">Operación</Badge>
						<Badge color="sky">Consola de eventos</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Consola operativa para soporte, operaciones y desarrollo: eventos
						relevantes del ecosistema Famedic, sin dump técnico de logs.
					</Text>
					{meta?.generated_at ? (
						<p className="text-[11px] text-zinc-400">
							Actualizado {meta.generated_at}
						</p>
					) : null}
				</div>

				<EventSummary summary={summary} />

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
					<EventCenterToolbar
						filters={filters}
						filterOptions={filterOptions}
						contactOptions={contactOptions}
					/>
				</div>

				<Deferred
					data="events"
					fallback={<EventTable loading selectedId={selected?.id} />}
				>
					<DeferredEvents
						selectedId={selected?.id}
						onSelect={setSelected}
					/>
				</Deferred>

				<QuickActions actions={actions} />
			</div>

			<EventDrawer
				open={Boolean(selected)}
				event={selected}
				onClose={() => setSelected(null)}
			/>
		</AdminLayout>
	);
}
