import { Link, router } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import {
	ArrowLeftIcon,
	ArrowPathIcon,
	ArrowRightIcon,
	CheckCircleIcon,
	Cog6ToothIcon,
	DocumentTextIcon,
	SparklesIcon,
} from "@heroicons/react/16/solid";
import SuiteBadge from "@/Components/Admin/IntelligenceHub/SuiteBadge";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

function StatusPill({ label, tone = "default" }) {
	const tones = {
		green: "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300",
		amber: "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200",
		red: "border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300",
		default:
			"border-zinc-200 bg-zinc-50 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300",
	};

	return (
		<span
			className={`inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold ${
				tones[tone] || tones.default
			}`}
		>
			{label}
		</span>
	);
}

function Dot({ tone = "green" }) {
	const colors = {
		green: "bg-emerald-500",
		amber: "bg-amber-500",
		red: "bg-rose-500",
	};
	return (
		<span
			className={`inline-block size-2.5 shrink-0 rounded-full ${colors[tone] || colors.amber}`}
			aria-hidden="true"
		/>
	);
}

function HorizontalTabs({ tabs = [], activeId, onChange }) {
	return (
		<nav className="flex gap-1 overflow-x-auto rounded-2xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/60">
			{tabs.map((tab) => {
				const active = tab.id === activeId;
				return (
					<button
						key={tab.id}
						type="button"
						onClick={() => onChange(tab.id)}
						className={`shrink-0 rounded-xl px-3.5 py-2 text-sm font-semibold transition ${
							active
								? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white"
								: "text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
						}`}
					>
						{tab.label}
					</button>
				);
			})}
		</nav>
	);
}

function ModuleCard({ module }) {
	const soon = module.status === "coming_soon" || !module.href;
	const body = (
		<div
			className={`group flex h-full flex-col rounded-2xl border p-5 transition-all duration-300 ${
				soon
					? "border-dashed border-zinc-300 bg-zinc-50/70 opacity-75 dark:border-zinc-700 dark:bg-zinc-900/40"
					: "border-zinc-200 bg-white hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-lg dark:border-zinc-800 dark:bg-zinc-900/60 dark:hover:border-zinc-600"
			}`}
		>
			<div className="flex items-start justify-between gap-3">
				<span className="text-2xl" aria-hidden="true">
					{module.emoji}
				</span>
				{soon ? (
					<SuiteBadge variant="comingSoon" className="ml-0">
						Soon
					</SuiteBadge>
				) : (
					<span className="inline-flex items-center gap-1 text-xs font-semibold text-zinc-500 transition group-hover:text-zinc-900 dark:group-hover:text-white">
						Abrir
						<ArrowRightIcon className="size-3.5 transition-transform group-hover:translate-x-0.5" />
					</span>
				)}
			</div>
			<h3 className="mt-4 text-lg font-semibold text-zinc-950 dark:text-white">
				{module.title}
			</h3>
			<p className="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">
				{module.description}
			</p>
			<div className="mt-4 flex flex-wrap gap-1.5">
				{(module.items || []).map((item) => (
					<span
						key={item}
						className="rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-[11px] font-medium text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
					>
						{item}
					</span>
				))}
			</div>
		</div>
	);

	if (soon) return body;
	return (
		<Link href={module.href} className="block h-full">
			{body}
		</Link>
	);
}

function ShortcutGrid({ items = [] }) {
	if (!items.length) return null;
	return (
		<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
			{items.map((item) => (
				<Link
					key={item.id}
					href={item.href}
					className="group flex items-center justify-between rounded-2xl border border-zinc-200 bg-white px-4 py-4 transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900/60 dark:hover:border-zinc-600"
				>
					<span className="text-sm font-semibold text-zinc-900 dark:text-white">
						{item.label}
					</span>
					<span className="inline-flex items-center gap-1 text-xs font-semibold text-zinc-500 group-hover:text-zinc-900 dark:group-hover:text-white">
						Abrir
						<ArrowRightIcon className="size-3.5" />
					</span>
				</Link>
			))}
		</div>
	);
}

function OverviewTab({ hub }) {
	return (
		<div className="space-y-10">
			<section className="space-y-3">
				<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
					Indicadores
				</p>
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
					{(hub?.kpis || []).map((kpi) => (
						<div
							key={kpi.id}
							className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600"
						>
							<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
								{kpi.label}
							</p>
							<p className="mt-2 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
								{kpi.value_formatted}
							</p>
							{kpi.hint ? (
								<p className="mt-1 text-xs text-zinc-500">{kpi.hint}</p>
							) : null}
						</div>
					))}
				</div>
			</section>

			{(hub?.quick_actions || []).length > 0 ? (
				<section className="space-y-3">
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Quick Actions
					</p>
					<div className="flex flex-wrap gap-2">
						{hub.quick_actions.map((action) => (
							<Link
								key={action.id}
								href={action.href}
								className="rounded-full border border-zinc-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
							>
								{action.label}
							</Link>
						))}
					</div>
				</section>
			) : null}

			<section className="space-y-4">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Consola
					</p>
					<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
						Módulos ActiveCampaign
					</h2>
					<p className="mt-1 text-sm text-zinc-500">
						Cada tarjeta abre una pantalla existente. Este hub no duplica lógica.
					</p>
				</div>
				<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
					{(hub?.modules || []).map((module) => (
						<ModuleCard key={module.id} module={module} />
					))}
				</div>
			</section>

			<section className="space-y-4">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Integraciones Famedic
					</p>
					<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
						Qué envía Famedic a ActiveCampaign
					</h2>
					<p className="mt-1 text-sm text-zinc-500">
						Señales detectadas en la infraestructura existente.
					</p>
				</div>
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
					{(hub?.famedic_integrations || []).map((item) => {
						const body = (
							<div className="group flex h-full flex-col justify-between rounded-2xl border border-zinc-200 bg-white p-4 transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900/60">
								<div>
									<p className="text-sm font-semibold text-zinc-900 dark:text-white">
										{item.label}
									</p>
									{item.signal ? (
										<p className="mt-1 text-[11px] text-zinc-400">{item.signal}</p>
									) : null}
								</div>
								<span className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-zinc-500 group-hover:text-zinc-900 dark:group-hover:text-white">
									Ver
									<ArrowRightIcon className="size-3.5" />
								</span>
							</div>
						);
						return item.href ? (
							<Link key={item.id} href={item.href} className="block h-full">
								{body}
							</Link>
						) : (
							<div key={item.id}>{body}</div>
						);
					})}
				</div>
			</section>

			<div className="grid gap-4 xl:grid-cols-5">
				<section className="rounded-[1.75rem] border border-violet-200/60 bg-gradient-to-br from-violet-50/70 via-white to-sky-50/40 p-6 xl:col-span-3 dark:border-violet-500/20 dark:from-violet-950/20 dark:via-zinc-900 dark:to-sky-950/10">
					<div className="flex items-center gap-2">
						<SparklesIcon className="size-4 text-violet-500" />
						<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-violet-500">
							{hub?.copilot?.headline || "ActiveCampaign Copilot"}
						</p>
					</div>
					<ul className="mt-4 space-y-2.5">
						{(hub?.copilot?.findings || []).map((item, index) => (
							<li
								key={`f-${index}`}
								className="flex gap-2 text-sm text-zinc-700 dark:text-zinc-300"
							>
								<span className="text-zinc-400">•</span>
								<span>{item}</span>
							</li>
						))}
					</ul>
					<ul className="mt-5 space-y-2">
						{(hub?.copilot?.recommendations || []).map((item, index) => (
							<li
								key={`r-${index}`}
								className="flex gap-2 rounded-xl bg-white/70 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-900/50 dark:text-zinc-300"
							>
								<CheckCircleIcon className="mt-0.5 size-4 shrink-0 text-emerald-500" />
								<span>{item}</span>
							</li>
						))}
					</ul>
					{(hub?.copilot?.actions || []).length > 0 ? (
						<div className="mt-5 flex flex-wrap gap-2">
							{hub.copilot.actions.map((action) => (
								<Button key={action.id} href={action.href} outline className="!text-xs">
									{action.label}
								</Button>
							))}
						</div>
					) : null}
				</section>

				<ChartCard
					title="Timeline"
					description="Últimos eventos, syncs y errores."
					className="xl:col-span-2"
				>
					<ol className="relative max-h-80 space-y-4 overflow-y-auto border-l border-zinc-200 pl-4 dark:border-zinc-700">
						{(hub?.timeline || []).length === 0 ? (
							<li className="text-sm text-zinc-400">Sin actividad reciente.</li>
						) : (
							(hub.timeline || []).map((item, index) => (
								<li key={index} className="relative">
									<span
										className={`absolute -left-[21px] top-1 size-2.5 rounded-full ring-4 ring-white dark:ring-zinc-900 ${
											item.type === "error"
												? "bg-rose-500"
												: item.type === "sync"
													? "bg-amber-500"
													: "bg-sky-500"
										}`}
									/>
									<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
										{item.label}
									</p>
									{item.detail ? (
										<p className="text-xs text-zinc-500">{item.detail}</p>
									) : null}
									{item.at ? (
										<p className="mt-0.5 text-[11px] text-zinc-400">{item.at}</p>
									) : null}
								</li>
							))
						)}
					</ol>
				</ChartCard>
			</div>

			<section className="space-y-4">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Estado de integración
					</p>
					<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
						Dominios Famedic ↔ ActiveCampaign
					</h2>
				</div>
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
					{(hub?.integration_status || []).map((row) => {
						const body = (
							<div className="rounded-2xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900/60 dark:hover:border-zinc-600">
								<div className="flex items-center justify-between gap-2">
									<p className="text-sm font-semibold text-zinc-900 dark:text-white">
										{row.label}
									</p>
									<span className="inline-flex items-center gap-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300">
										<Dot tone={row.tone} />
										{row.status}
									</span>
								</div>
								<div className="mt-3 grid grid-cols-3 gap-2 text-[11px] text-zinc-500">
									<div>
										<p className="uppercase tracking-wide text-zinc-400">Última</p>
										<p className="mt-0.5 font-medium text-zinc-700 dark:text-zinc-200">
											{row.last_run}
										</p>
									</div>
									<div>
										<p className="uppercase tracking-wide text-zinc-400">Cantidad</p>
										<p className="mt-0.5 font-medium text-zinc-700 dark:text-zinc-200">
											{row.quantity}
										</p>
									</div>
									<div>
										<p className="uppercase tracking-wide text-zinc-400">Errores</p>
										<p className="mt-0.5 font-medium text-zinc-700 dark:text-zinc-200">
											{row.errors}
										</p>
									</div>
								</div>
							</div>
						);
						return row.href ? (
							<Link key={row.id} href={row.href} className="block">
								{body}
							</Link>
						) : (
							<div key={row.id}>{body}</div>
						);
					})}
				</div>
			</section>

			<section className="rounded-[1.5rem] border border-dashed border-zinc-300 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-900/40">
				<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
					Roadmap de canales
				</p>
				<p className="mt-1 text-sm text-zinc-500">
					Visibles y deshabilitados — se activarán sin cambiar el Sidebar.
				</p>
				<div className="mt-4 flex flex-wrap gap-2">
					{(hub?.future_channels || []).map((channel) => (
						<span
							key={channel.id}
							className="rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-500 opacity-80 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
						>
							{channel.label}
							<span className="ml-1.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">
								Coming Soon
							</span>
						</span>
					))}
				</div>
			</section>
		</div>
	);
}

function IntegrationsTab({ hub }) {
	return (
		<div className="space-y-10">
			<section className="space-y-4">
				<div className="flex flex-wrap items-end justify-between gap-3">
					<div>
						<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
							Integration Catalog
						</p>
						<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
							Eventos implementados
						</h2>
						<p className="mt-1 text-sm text-zinc-500">
							Dispatches reales y señales de dominio instrumentadas.
						</p>
					</div>
					{hub?.links?.integrations_hub ? (
						<Button href={hub.links.integrations_hub} outline className="!text-xs">
							Integrations Hub
							<ArrowRightIcon className="size-3.5" />
						</Button>
					) : null}
				</div>

				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
					{(hub?.event_catalog || []).map((event) => (
						<div
							key={event.key}
							className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900/60"
						>
							<div className="flex items-start justify-between gap-2">
								<div>
									<p className="font-mono text-xs text-zinc-400">{event.key}</p>
									<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
										{event.label}
									</p>
								</div>
								<span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
									<Dot tone={event.tone} />
									{event.status}
								</span>
							</div>
							<div className="mt-3 grid grid-cols-3 gap-2 text-[11px] text-zinc-500">
								<div>
									<p className="uppercase text-zinc-400">Último</p>
									<p className="mt-0.5 font-medium text-zinc-700 dark:text-zinc-200">
										{event.last_at || "—"}
									</p>
								</div>
								<div>
									<p className="uppercase text-zinc-400">Cantidad</p>
									<p className="mt-0.5 font-medium text-zinc-700 dark:text-zinc-200">
										{event.count == null ? "—" : event.count}
									</p>
								</div>
								<div>
									<p className="uppercase text-zinc-400">Errores</p>
									<p className="mt-0.5 font-medium text-zinc-700 dark:text-zinc-200">
										{event.errors == null ? "—" : event.errors}
									</p>
								</div>
							</div>
							{event.logs_href ? (
								<Link
									href={event.logs_href}
									className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-zinc-500 hover:text-zinc-900 dark:hover:text-white"
								>
									Logs
									<ArrowRightIcon className="size-3.5" />
								</Link>
							) : null}
						</div>
					))}
				</div>
			</section>

			<section className="space-y-4">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Integraciones Famedic
					</p>
					<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
						Flujos de negocio conectados
					</h2>
				</div>
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
					{(hub?.famedic_integrations || []).map((item) => (
						item.href ? (
							<Link
								key={item.id}
								href={item.href}
								className="group flex items-center justify-between rounded-2xl border border-zinc-200 bg-white px-4 py-3.5 transition hover:border-zinc-300 hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60"
							>
								<span className="text-sm font-semibold text-zinc-900 dark:text-white">
									{item.label}
								</span>
								<span className="text-xs font-semibold text-zinc-500 group-hover:text-zinc-900 dark:group-hover:text-white">
									Ver →
								</span>
							</Link>
						) : (
							<div
								key={item.id}
								className="rounded-2xl border border-dashed border-zinc-300 px-4 py-3.5 text-sm text-zinc-400"
							>
								{item.label}
							</div>
						)
					))}
				</div>
			</section>

			<section className="space-y-4">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Estado
					</p>
					<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
						Indicadores por dominio
					</h2>
				</div>
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
					{(hub?.integration_status || []).map((row) => (
						<div
							key={row.id}
							className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900/60"
						>
							<div className="flex items-center justify-between">
								<p className="text-sm font-semibold text-zinc-900 dark:text-white">
									{row.label}
								</p>
								<span className="inline-flex items-center gap-1.5 text-xs">
									<Dot tone={row.tone} />
									{row.status}
								</span>
							</div>
						</div>
					))}
				</div>
			</section>
		</div>
	);
}

function GenericTab({ title, description, shortcuts = [] }) {
	return (
		<section className="space-y-4">
			<div>
				<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
					Módulo
				</p>
				<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{title}</h2>
				<p className="mt-1 text-sm text-zinc-500">{description}</p>
			</div>
			<ShortcutGrid items={shortcuts} />
		</section>
	);
}

export default function ActiveCampaignHub({ hub, meta = {} }) {
	const header = hub?.header || {};
	const links = hub?.links || {};
	const tab = hub?.filters?.tab || "overview";

	const visit = (nextTab) => {
		router.get(
			route("admin.workspace.activecampaign"),
			{ tab: nextTab },
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	return (
		<AdminLayout title="ActiveCampaign Hub">
			<div className="mx-auto max-w-6xl space-y-8">
				<div className="flex flex-wrap gap-2">
					<Button href={links.workspace || route("admin.workspace.index")} outline>
						<ArrowLeftIcon className="size-4" />
						Workspace
					</Button>
					{links.customer_engagement ? (
						<Button href={links.customer_engagement} outline>
							Customer Engagement
						</Button>
					) : null}
				</div>

				<header className="space-y-4">
					<nav className="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
						<a
							href={route("admin.workspace.index")}
							className="hover:text-zinc-800 dark:hover:text-zinc-200"
						>
							Workspace
						</a>
						<span className="text-zinc-300">/</span>
						<span className="font-medium text-zinc-700 dark:text-zinc-300">
							ActiveCampaign
						</span>
					</nav>

					<div className="flex flex-wrap items-start justify-between gap-4">
						<div className="max-w-2xl">
							<div className="flex flex-wrap items-center gap-2">
								<span className="text-3xl" aria-hidden="true">
									💬
								</span>
								<SuiteBadge variant="new" className="ml-0">
									Producto
								</SuiteBadge>
							</div>
							<Heading className="mt-2 !text-3xl tracking-tight">
								{meta.title || "ActiveCampaign Hub"}
							</Heading>
							<Text className="mt-2 text-base text-zinc-500 dark:text-zinc-400">
								{meta.subtitle ||
									"Centro de integración entre Famedic y ActiveCampaign."}
							</Text>
						</div>
						<div className="flex flex-wrap gap-2">
							{links.sync ? (
								<Button href={links.sync} outline>
									<ArrowPathIcon className="size-4" />
									Sincronizar
								</Button>
							) : null}
							{links.settings ? (
								<Button href={links.settings} outline>
									<Cog6ToothIcon className="size-4" />
									Configuración
								</Button>
							) : null}
							{links.logs ? (
								<Button href={links.logs} outline>
									<DocumentTextIcon className="size-4" />
									Logs
								</Button>
							) : null}
						</div>
					</div>

					<div className="flex flex-wrap gap-2">
						<StatusPill
							label={`Estado · ${header.connection_label || "—"}`}
							tone={header.connection_tone || "default"}
						/>
						<StatusPill
							label={`API · ${header.api_status || "—"}`}
							tone={header.api_status_tone || "default"}
						/>
						<StatusPill label={`Sync · ${header.last_sync || "—"}`} />
						<StatusPill label={`Workspace · ${header.workspace || "Famedic"}`} />
						<StatusPill label={`API ${header.api_version || "v3"}`} />
						{meta.generated_at ? (
							<StatusPill label={`Actualizado ${meta.generated_at}`} />
						) : null}
					</div>
				</header>

				<HorizontalTabs tabs={hub?.tabs || []} activeId={tab} onChange={visit} />

				{tab === "overview" ? <OverviewTab hub={hub} /> : null}
				{tab === "integrations" ? <IntegrationsTab hub={hub} /> : null}
				{tab === "crm" ? (
					<GenericTab
						title="CRM"
						description="Contactos, Customer 360, tags y campos."
						shortcuts={hub?.tab_shortcuts || []}
					/>
				) : null}
				{tab === "campaigns" ? (
					<GenericTab
						title="Campañas"
						description="Email, analytics y centros de notificación."
						shortcuts={hub?.tab_shortcuts || []}
					/>
				) : null}
				{tab === "automations" ? (
					<GenericTab
						title="Automatizaciones"
						description="Flujos, builder y event center."
						shortcuts={hub?.tab_shortcuts || []}
					/>
				) : null}
				{tab === "analytics" ? (
					<GenericTab
						title="Analytics"
						description="Funnels, journey, ecommerce y verticales."
						shortcuts={hub?.tab_shortcuts || []}
					/>
				) : null}
				{tab === "developer" ? (
					<GenericTab
						title="Developer"
						description="API, logs, health, queue e integraciones."
						shortcuts={hub?.tab_shortcuts || []}
					/>
				) : null}
				{tab === "settings" ? (
					<GenericTab
						title="Configuración"
						description="Settings, health y dashboard operativo."
						shortcuts={hub?.tab_shortcuts || []}
					/>
				) : null}
			</div>
		</AdminLayout>
	);
}
