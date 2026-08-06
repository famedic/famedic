import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import IntegrationsHubSummary from "@/Components/Admin/ActiveCampaign/Integrations/IntegrationsHubSummary";
import IntegrationsGrid from "@/Components/Admin/ActiveCampaign/Integrations/IntegrationsGrid";

function DeferredExtras({ integrations }) {
	const { deferred } = usePage().props;
	return (
		<>
			<IntegrationsGrid
				integrations={integrations}
				deferred={deferred || null}
				loadingDeferred={!deferred}
			/>
			{deferred?.probes ? (
				<ChartCard
					title="Verificación de configuración"
					description="Sin llamadas a APIs externas — solo presencia de credenciales locales."
					className="mt-2"
				>
					<ul className="space-y-2 text-sm">
						{deferred.probes.map((probe) => (
							<li
								key={probe.id}
								className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
							>
								<span className="font-medium text-zinc-900 dark:text-zinc-50">
									{probe.name}
								</span>
								<span className="text-xs text-zinc-500">
									{probe.result} · {probe.checked_at}
								</span>
							</li>
						))}
					</ul>
				</ChartCard>
			) : null}
		</>
	);
}

export default function IntegrationsHub({
	summary,
	integrations,
	meta,
	healthUrl,
	logsUrl,
	settingsUrl,
}) {
	return (
		<AdminLayout title="Marketing Intelligence · Integrations Hub">
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
						Integrations Hub
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Integrations Hub</Heading>
						<Badge color="famedic">v1.1</Badge>
						<Badge color="sky">Plataforma extensible</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Centro de conexiones externas de Famedic: estado, autenticación y
						atajos. Sin inventar sincronizaciones ni latencias.
					</Text>
					{meta?.generated_at ? (
						<p className="text-[11px] text-zinc-400">
							Actualizado {meta.generated_at}
						</p>
					) : null}
					{meta?.note ? (
						<p className="text-[11px] text-zinc-400">{meta.note}</p>
					) : null}
				</div>

				<IntegrationsHubSummary summary={summary} />

				<Deferred
					data="deferred"
					fallback={
						<IntegrationsGrid
							integrations={integrations}
							loadingDeferred
						/>
					}
				>
					<DeferredExtras integrations={integrations} />
				</Deferred>

				<section className="space-y-3">
					<div>
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Accesos relacionados
						</h2>
						<p className="text-xs text-zinc-500">
							Atajos a pantallas existentes (sin duplicar operación).
						</p>
					</div>
					<div className="flex flex-wrap gap-2">
						{healthUrl ? (
							<Button href={healthUrl} outline>
								Health Center
							</Button>
						) : null}
						{logsUrl ? (
							<Button href={logsUrl} outline>
								Logs
							</Button>
						) : null}
						{settingsUrl ? (
							<Button href={settingsUrl} outline>
								Configuración
							</Button>
						) : null}
					</div>
				</section>
			</div>
		</AdminLayout>
	);
}
