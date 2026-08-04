import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import {
	StatusBadge,
} from "@/Components/Admin/ActiveCampaign/Automations/AutomationMetrics";
import AutomationRecentRuns from "@/Components/Admin/ActiveCampaign/Automations/AutomationRecentRuns";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	proximamente: { label: "Próximamente", color: "zinc" },
};

function DeferredHistory() {
	const { deferred } = usePage().props;
	if (!deferred) {
		return (
			<div className="space-y-3" aria-busy="true">
				<div className="h-40 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800" />
				<div className="h-40 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800" />
			</div>
		);
	}

	return (
		<div className="grid gap-4 xl:grid-cols-2">
			<AutomationRecentRuns
				runs={deferred.history || []}
				title="Historial"
			/>
			<ChartCard title="Logs" description="Derivados de dispatches locales.">
				<ul className="space-y-2 text-sm">
					{(deferred.logs || []).map((log, idx) => (
						<li
							key={`${log.when}-${idx}`}
							className="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
						>
							<div className="flex items-center justify-between gap-2">
								<Badge color={log.level === "error" ? "red" : "zinc"}>
									{log.level}
								</Badge>
								<span className="text-[11px] text-zinc-400">{log.when}</span>
							</div>
							<p className="mt-1 text-zinc-700 dark:text-zinc-200">
								{log.message}
							</p>
						</li>
					))}
				</ul>
			</ChartCard>
		</div>
	);
}

export default function AutomationDetail({
	automation,
	info,
	conditions = [],
	actions = [],
	links = {},
}) {
	return (
		<AdminLayout title={`Automation · ${info?.name || "Detalle"}`}>
			<div className="space-y-6 pb-6">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<Link
						href={route("admin.activecampaign.automations")}
						className="font-medium text-zinc-400 transition hover:text-famedic-light"
					>
						Automation Center
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300 dark:text-zinc-600" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Detalle
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>{info.name}</Heading>
							<StatusBadge
								status={automation.status}
								label={info.status}
							/>
						</div>
						<Text className="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
							{info.description}
						</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button href={links.list} outline>
							Listado
						</Button>
						<Button href={links.builder} outline>
							Abrir en builder
						</Button>
					</div>
				</div>

				<div className="grid gap-4 lg:grid-cols-3">
					<ChartCard title="Información" description="Metadatos de la automatización">
						<dl className="space-y-2 text-sm">
							<div>
								<dt className="text-zinc-400">Disparador</dt>
								<dd className="font-medium">{info.trigger}</dd>
							</div>
							<div>
								<dt className="text-zinc-400">Tipo</dt>
								<dd>{info.trigger_type}</dd>
							</div>
							<div>
								<dt className="text-zinc-400">Schedule / próxima</dt>
								<dd>{info.schedule}</dd>
							</div>
							<div>
								<dt className="text-zinc-400">Fuente</dt>
								<dd className="text-xs">{info.source}</dd>
							</div>
						</dl>
					</ChartCard>

					<ChartCard title="Condiciones" description="Evaluación previa a acciones">
						<ul className="space-y-2">
							{conditions.map((c) => {
								const truth = TRUTH[c.truth] || TRUTH.proximamente;
								return (
									<li
										key={c.id}
										className="flex items-center justify-between gap-2 text-sm"
									>
										<span>{c.label}</span>
										<Badge color={truth.color}>{truth.label}</Badge>
									</li>
								);
							})}
						</ul>
					</ChartCard>

					<ChartCard title="Acciones" description="Canales de ejecución">
						<ul className="space-y-2">
							{actions.map((a) => {
								const truth = TRUTH[a.truth] || TRUTH.proximamente;
								return (
									<li
										key={a.id}
										className="flex items-center justify-between gap-2 text-sm"
									>
										<span>{a.label}</span>
										<Badge color={truth.color}>
											{a.status || truth.label}
										</Badge>
									</li>
								);
							})}
						</ul>
					</ChartCard>
				</div>

				<Deferred
					data="deferred"
					fallback={
						<div className="space-y-3" aria-busy="true">
							<div className="h-40 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800" />
							<div className="h-40 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800" />
						</div>
					}
				>
					<DeferredHistory />
				</Deferred>
			</div>
		</AdminLayout>
	);
}
