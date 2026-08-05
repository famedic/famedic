import { router } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import {
	ArrowLeftIcon,
	ArrowRightIcon,
	ArrowTopRightOnSquareIcon,
} from "@heroicons/react/16/solid";
import SuiteBadge from "@/Components/Admin/IntelligenceHub/SuiteBadge";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

function KpiGrid({ kpis = [] }) {
	return (
		<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
			{kpis.map((kpi) => (
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

function SubTabs({ items = [], activeId, onChange }) {
	if (!items.length) {
		return null;
	}

	return (
		<div className="flex flex-wrap gap-2">
			{items.map((item) => {
				const active = item.id === activeId;
				const soon = item.status === "coming_soon";
				return (
					<button
						key={item.id}
						type="button"
						onClick={() => onChange(item.id)}
						className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
							active
								? "border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900"
								: soon
									? "border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-600"
									: "border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
						}`}
					>
						{item.label}
						{soon ? (
							<span className="text-[9px] uppercase opacity-70">Soon</span>
						) : null}
					</button>
				);
			})}
		</div>
	);
}

export default function CustomerEngagement({
	workspace,
	engagement,
	meta = {},
}) {
	const filters = engagement?.filters || { tab: "dashboard", sub: null };
	const navigation = engagement?.navigation || [];
	const currentTab = navigation.find((tab) => tab.id === filters.tab) || navigation[0];
	const panel = engagement?.active_panel || {};

	const visit = (tab, sub = null) => {
		router.get(
			route("admin.workspace.show", workspace.slug),
			{ tab, ...(sub ? { sub } : {}) },
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	return (
		<AdminLayout title="Customer Engagement · Workspace">
			<div className="mx-auto max-w-6xl space-y-8">
				<div className="flex flex-wrap gap-2">
					<Button href={workspace.home_url} outline>
						<ArrowLeftIcon className="size-4" />
						Workspace
					</Button>
				</div>

				<header className="space-y-3">
					<nav className="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
						<a
							href={workspace.home_url}
							className="hover:text-zinc-800 dark:hover:text-zinc-200"
						>
							Workspace
						</a>
						<span className="text-zinc-300">/</span>
						<span className="font-medium text-zinc-700 dark:text-zinc-300">
							Customer Engagement
						</span>
					</nav>

					<div className="flex flex-wrap items-start justify-between gap-4">
						<div className="max-w-3xl">
							<div className="flex items-center gap-2">
								<span className="text-3xl" aria-hidden="true">
									{workspace.emoji || "💬"}
								</span>
								<SuiteBadge variant="new" className="ml-0">
									CRM
								</SuiteBadge>
							</div>
							<Heading className="mt-2 !text-3xl tracking-tight">
								{workspace.name}
							</Heading>
							<Text className="mt-2 text-base text-zinc-500 dark:text-zinc-400">
								{workspace.description}
							</Text>
						</div>
						<div className="space-y-2 text-right text-xs text-zinc-500">
							{meta.user_name ? <p>{meta.user_name}</p> : null}
							{meta.generated_at ? <p>{meta.generated_at}</p> : null}
							<div className="flex flex-wrap justify-end gap-1.5">
								{(engagement?.meta?.channels_ready || []).map((ch) => (
									<span
										key={ch}
										className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
									>
										{ch}
									</span>
								))}
								{(engagement?.meta?.channels_upcoming || []).map((ch) => (
									<span
										key={ch}
										className="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
									>
										{ch} soon
									</span>
								))}
							</div>
						</div>
					</div>
				</header>

				<HorizontalTabs
					tabs={navigation}
					activeId={filters.tab}
					onChange={(tabId) => visit(tabId)}
				/>

				{currentTab?.subtabs?.length ? (
					<SubTabs
						items={currentTab.subtabs}
						activeId={filters.sub}
						onChange={(subId) => visit(filters.tab, subId)}
					/>
				) : null}

				{filters.tab === "dashboard" ? (
					<section className="space-y-6">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
								Indicadores
							</p>
							<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
								Dashboard de Engagement
							</h2>
						</div>
						<KpiGrid kpis={engagement?.kpis || []} />

						<div className="grid gap-4 lg:grid-cols-2">
							<ChartCard
								title="Actividad reciente"
								description="Últimos eventos de sincronización."
							>
								<ul className="space-y-2">
									{(engagement?.tables?.recent_activity || [])
										.slice(0, 6)
										.map((row, index) => (
											<li
												key={index}
												className="flex justify-between gap-3 text-sm text-zinc-600 dark:text-zinc-300"
											>
												<span className="truncate">
													{row.event || row.type || row.label || "Evento"}
												</span>
												<span className="shrink-0 text-xs text-zinc-400">
													{row.when || row.at || row.status || ""}
												</span>
											</li>
										))}
									{(engagement?.tables?.recent_activity || []).length === 0 ? (
										<li className="text-sm text-zinc-400">Sin actividad reciente.</li>
									) : null}
								</ul>
							</ChartCard>
							<ChartCard
								title="Accesos rápidos"
								description="Módulos ActiveCampaign conectados."
							>
						<div className="flex flex-wrap gap-2">
							<Button
								href={
									engagement?.links?.activecampaign_hub ||
									route("admin.workspace.activecampaign")
								}
								className="!text-xs"
							>
								💬 ActiveCampaign Hub
								<ArrowRightIcon className="size-3.5" />
							</Button>
							{Object.entries(engagement?.links || {})
								.filter(([key]) => key !== "activecampaign_hub")
								.map(([key, href]) => (
									<Button key={key} href={href} outline className="!text-xs">
										{key.replaceAll("_", " ")}
										<ArrowTopRightOnSquareIcon className="size-3.5" />
									</Button>
								))}
						</div>
							</ChartCard>
						</div>
					</section>
				) : (
					<section className="rounded-[1.5rem] border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900/50">
						<div className="flex flex-wrap items-start justify-between gap-4">
							<div className="max-w-2xl">
								<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
									{panel.tab_label}
									{panel.sub_label ? ` · ${panel.sub_label}` : ""}
								</p>
								<h2 className="mt-2 text-xl font-semibold text-zinc-900 dark:text-white">
									{panel.sub_label || panel.tab_label}
								</h2>
								<p className="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
									{panel.description}
								</p>
							</div>
							{panel.status === "coming_soon" ? (
								<SuiteBadge variant="comingSoon" className="ml-0">
									Coming Soon
								</SuiteBadge>
							) : panel.href ? (
								<Button href={panel.href}>
									Abrir módulo
									<ArrowRightIcon className="size-4" />
								</Button>
							) : null}
						</div>

						{panel.status === "coming_soon" ? (
							<div className="mt-8 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-6 py-12 text-center dark:border-zinc-700 dark:bg-zinc-800/40">
								<p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
									Preparado para futuras integraciones
								</p>
								<p className="mt-2 text-xs text-zinc-500">
									WhatsApp Business, SMS y Push Notifications se agregarán sin
									cambiar el Sidebar.
								</p>
							</div>
						) : (
							<div className="mt-8 grid gap-3 sm:grid-cols-3">
								{(currentTab?.subtabs || []).map((item) => (
									<button
										key={item.id}
										type="button"
										onClick={() => visit(filters.tab, item.id)}
										className={`rounded-xl border px-4 py-3 text-left transition ${
											item.id === filters.sub
												? "border-zinc-900 bg-zinc-950 text-white dark:border-white dark:bg-white dark:text-zinc-900"
												: "border-zinc-200 hover:border-zinc-300 dark:border-zinc-700"
										}`}
									>
										<p className="text-sm font-semibold">{item.label}</p>
										<p
											className={`mt-1 text-xs ${
												item.id === filters.sub
													? "text-zinc-300 dark:text-zinc-500"
													: "text-zinc-500"
											}`}
										>
											{item.status === "coming_soon"
												? "Próximamente"
												: "Disponible"}
										</p>
									</button>
								))}
							</div>
						)}
					</section>
				)}
			</div>
		</AdminLayout>
	);
}
