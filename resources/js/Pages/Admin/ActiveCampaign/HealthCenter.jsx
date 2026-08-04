import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import HealthOverview from "@/Components/Admin/ActiveCampaign/Health/HealthOverview";
import HealthIntegrations from "@/Components/Admin/ActiveCampaign/Health/HealthIntegrations";
import HealthInfrastructure from "@/Components/Admin/ActiveCampaign/Health/HealthInfrastructure";
import HealthAlerts from "@/Components/Admin/ActiveCampaign/Health/HealthAlerts";
import HealthActions from "@/Components/Admin/ActiveCampaign/Health/HealthActions";

function DeferredInfrastructure() {
	const { deferred } = usePage().props;
	return (
		<HealthInfrastructure
			items={deferred?.infrastructure || null}
			loading={!deferred}
		/>
	);
}

function DeferredAlerts() {
	const { deferred } = usePage().props;
	return <HealthAlerts alerts={deferred?.alerts || null} loading={!deferred} />;
}

export default function HealthCenter({
	overview,
	integrations,
	actions,
	meta,
}) {
	return (
		<AdminLayout title="Marketing Intelligence · Health Center">
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
						Health Center
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Health Center</Heading>
						<Badge color="famedic">Operación</Badge>
						<Badge color="sky">Consola visual</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Estado del ecosistema Famedic para Marketing Intelligence: señales
						reales, sin inventario técnico de logs.
					</Text>
					{meta?.generated_at ? (
						<p className="text-[11px] text-zinc-400">
							Actualizado {meta.generated_at}
						</p>
					) : null}
				</div>

				<HealthOverview overview={overview} />
				<HealthIntegrations integrations={integrations} />

				<Deferred
					data="deferred"
					fallback={
						<>
							<HealthInfrastructure loading />
							<HealthAlerts loading />
						</>
					}
				>
					<>
						<DeferredInfrastructure />
						<DeferredAlerts />
					</>
				</Deferred>

				<HealthActions actions={actions} />
			</div>
		</AdminLayout>
	);
}
