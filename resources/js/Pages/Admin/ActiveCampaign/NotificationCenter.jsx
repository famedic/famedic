import { useState } from "react";
import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import NotificationSummary from "@/Components/Admin/ActiveCampaign/Notifications/NotificationSummary";
import NotificationToolbar from "@/Components/Admin/ActiveCampaign/Notifications/NotificationToolbar";
import NotificationTable from "@/Components/Admin/ActiveCampaign/Notifications/NotificationTable";
import NotificationDrawer from "@/Components/Admin/ActiveCampaign/Notifications/NotificationDrawer";

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
						<Button
							key={action.id}
							outline
							disabled
							title={action.hint || "No disponible"}
						>
							{action.label}
						</Button>
					),
				)}
			</div>
		</section>
	);
}

export default function NotificationCenter({
	filters,
	filterOptions,
	summary,
	notifications = [],
	actions,
	meta,
}) {
	const [selected, setSelected] = useState(null);

	return (
		<AdminLayout title="Marketing Intelligence · Notification Center">
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
						Notification Center
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Notification Center</Heading>
						<Badge color="famedic">Prioridades</Badge>
						<Badge color="amber">Ops</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Centro de prioridades del ecosistema Famedic: identifica rápido qué
						requiere atención a partir de señales existentes.
					</Text>
					{meta?.generated_at ? (
						<p className="text-[11px] text-zinc-400">
							Actualizado {meta.generated_at}
						</p>
					) : null}
				</div>

				<NotificationSummary summary={summary} meta={meta} />

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
					<NotificationToolbar
						filters={filters}
						filterOptions={filterOptions}
					/>
				</div>

				<NotificationTable
					notifications={notifications}
					total={meta?.total ?? notifications.length}
					selectedId={selected?.id}
					onSelect={setSelected}
				/>

				<QuickActions actions={actions} />
			</div>

			<NotificationDrawer
				open={Boolean(selected)}
				notification={selected}
				onClose={() => setSelected(null)}
			/>
		</AdminLayout>
	);
}
