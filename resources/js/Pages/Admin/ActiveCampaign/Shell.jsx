import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Link } from "@inertiajs/react";
import {
	PhaseBanner,
	MetricCardPlaceholders,
	ChartPlaceholders,
	TablePlaceholders,
	FunnelPlaceholder,
	JourneyPlaceholder,
	SettingsSectionsPlaceholder,
	HealthChecklistPlaceholder,
} from "@/Components/Admin/ActiveCampaign/ModulePlaceholders";
import { ChevronRightIcon } from "@heroicons/react/16/solid";

function resolveHref(action) {
	if (!action?.href) return null;
	try {
		return route(action.href);
	} catch {
		return null;
	}
}

export default function MarketingIntelligenceShell({ page }) {
	const parent = page.parent;

	return (
		<AdminLayout title={`Marketing Intelligence — ${page.title}`}>
			<div className="space-y-6">
				<nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1 text-xs">
					<Link
						href={route("admin.activecampaign.dashboard")}
						className="font-medium text-zinc-500 transition hover:text-famedic-light dark:text-zinc-400"
					>
						Marketing Intelligence
					</Link>
					{parent ? (
						<>
							<ChevronRightIcon className="size-3 text-zinc-400" />
							<Link
								href={route(parent.route)}
								className="font-medium text-zinc-500 transition hover:text-famedic-light dark:text-zinc-400"
							>
								{parent.title}
							</Link>
						</>
					) : null}
					<ChevronRightIcon className="size-3 text-zinc-400" />
					<span className="font-semibold text-zinc-800 dark:text-zinc-100">
						{page.title}
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="max-w-3xl space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>{page.title}</Heading>
							{page.badge ? <Badge color="famedic">{page.badge}</Badge> : null}
						</div>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							{page.description}
						</Text>
					</div>

					<div className="flex flex-wrap gap-2">
						{(page.actions || []).map((action) => {
							const href = resolveHref(action);
							if (href && !action.disabled) {
								return (
									<Button key={action.label} href={href} outline>
										{action.label}
									</Button>
								);
							}

							return (
								<Button key={action.label} outline disabled>
									{action.label}
								</Button>
							);
						})}
					</div>
				</div>

				<PhaseBanner phase={page.phase} phaseLabel={page.phase_label} />

				{page.metric_cards?.length || page.kpi_cards?.length ? (
					<section className="space-y-3">
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Indicadores
						</h2>
						<MetricCardPlaceholders
							cards={[...(page.metric_cards || []), ...(page.kpi_cards || [])]}
						/>
					</section>
				) : null}

				{page.layout === "journey" ? (
					<section className="space-y-3">
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Journey
						</h2>
						<JourneyPlaceholder />
					</section>
				) : null}

				{page.layout === "funnel" ? (
					<section className="space-y-3">
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Embudo
						</h2>
						<FunnelPlaceholder />
					</section>
				) : null}

				{page.layout === "health" ? (
					<section className="space-y-3">
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Salud
						</h2>
						<HealthChecklistPlaceholder />
					</section>
				) : null}

				{page.layout === "settings" ? (
					<section className="space-y-3">
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Secciones
						</h2>
						<SettingsSectionsPlaceholder />
					</section>
				) : null}

				{page.charts?.length ? (
					<section className="space-y-3">
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Visualizaciones
						</h2>
						<ChartPlaceholders charts={page.charts} />
					</section>
				) : null}

				{page.tables?.length ? (
					<section className="space-y-3">
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Tablas
						</h2>
						<TablePlaceholders tables={page.tables} />
					</section>
				) : null}

				{(page.notes || []).length ? (
					<section className="rounded-xl border border-dashed border-zinc-300 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-900/40">
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Notas de implementación
						</h2>
						<ul className="mt-2 list-disc space-y-1 pl-4 text-xs text-zinc-600 dark:text-zinc-400">
							{page.notes.map((note) => (
								<li key={note}>{note}</li>
							))}
						</ul>
					</section>
				) : null}
			</div>
		</AdminLayout>
	);
}
