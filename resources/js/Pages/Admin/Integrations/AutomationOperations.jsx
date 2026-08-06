import { useMemo, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Tab,
	TabGroup,
	TabList,
	TabPanel,
	TabPanels,
} from "@/Components/Catalyst/tabs";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import AutomationHealthCards from "@/Components/Admin/Integrations/AutomationOperations/AutomationHealthCards";
import AutomationTimeline from "@/Components/Admin/Integrations/AutomationOperations/AutomationTimeline";
import AutomationDriversTable from "@/Components/Admin/Integrations/AutomationOperations/AutomationDriversTable";
import AutomationPerformanceCharts from "@/Components/Admin/Integrations/AutomationOperations/AutomationPerformanceCharts";
import AutomationDiagnostics from "@/Components/Admin/Integrations/AutomationOperations/AutomationDiagnostics";
import AutomationArchitecture from "@/Components/Admin/Integrations/AutomationOperations/AutomationArchitecture";
import AutomationEventDrawer from "@/Components/Admin/Integrations/AutomationOperations/AutomationEventDrawer";
import AutomationKpiGrid from "@/Components/Admin/Integrations/AutomationOperations/AutomationKpiGrid";
import AutomationQueuePanel from "@/Components/Admin/Integrations/AutomationOperations/AutomationQueuePanel";

const TABS = [
	{ id: "dashboard", label: "Dashboard" },
	{ id: "timeline", label: "Timeline" },
	{ id: "drivers", label: "Drivers" },
	{ id: "queue", label: "Queue" },
	{ id: "performance", label: "Performance" },
	{ id: "diagnostics", label: "Diagnostics" },
	{ id: "architecture", label: "Architecture" },
];

export default function AutomationOperations({
	tab = "dashboard",
	health,
	kpis = [],
	drivers = [],
	timeline = [],
	performance,
	queue,
	architecture,
	roadmap = [],
	diagnosticsCatalog = [],
	meta,
	diagnosticUrl,
	queueActionUrl,
}) {
	const initialTab = Math.max(
		0,
		TABS.findIndex((t) => t.id === (tab || "dashboard")),
	);
	const [selectedTab, setSelectedTab] = useState(initialTab);
	const [selectedEvent, setSelectedEvent] = useState(null);
	const refreshForm = useForm({ tab: TABS[selectedTab]?.id || "dashboard", refresh: 1 });

	const handleRefresh = () => {
		refreshForm.get(route("admin.automation"), {
			preserveState: false,
		});
	};

	const handleTabChange = (index) => {
		setSelectedTab(index);
		router.get(
			route("admin.automation"),
			{ tab: TABS[index].id },
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	const healthBadge = useMemo(() => {
		const status = health?.status || "healthy";
		if (status === "healthy") return { color: "lime", label: "Healthy" };
		if (status === "degraded") return { color: "amber", label: "Degraded" };
		return { color: "rose", label: "Critical" };
	}, [health]);

	return (
		<AdminLayout title="Automation Operations Center">
			<div className="space-y-6 pb-8">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Automation Operations Center</Heading>
							<Badge color="famedic">Integraciones</Badge>
							<Badge color={healthBadge.color}>{healthBadge.label}</Badge>
						</div>
						<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
							Consola de monitoreo, auditoría y diagnóstico de la Automation
							Platform. No ejecuta automatizaciones de negocio.
						</Text>
						{meta?.generated_at ? (
							<p className="text-[11px] text-zinc-400">
								Actualizado {meta.generated_at}
								{meta?.events_table === false
									? " · Migración de eventos pendiente"
									: null}
							</p>
						) : null}
					</div>
					<Button
						outline
						onClick={handleRefresh}
						disabled={refreshForm.processing}
					>
						<ArrowPathIcon
							className={
								refreshForm.processing ? "animate-spin" : undefined
							}
						/>
						Actualizar
					</Button>
				</div>

				<TabGroup selectedIndex={selectedTab} onChange={handleTabChange}>
					<TabList className="gap-1 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/60">
						{TABS.map((t) => (
							<Tab key={t.id} className="shrink-0">
								{(selected) => (
									<span
										className={
											selected
												? "rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white"
												: "rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
										}
									>
										{t.label}
									</span>
								)}
							</Tab>
						))}
					</TabList>
					<TabPanels className="mt-6">
						<TabPanel className="space-y-6">
							<AutomationHealthCards health={health} roadmap={roadmap} />
							<section className="space-y-3">
								<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									Automation Health · KPIs
								</h2>
								<AutomationKpiGrid kpis={kpis} />
							</section>
							<section className="space-y-3">
								<div className="flex items-center justify-between gap-2">
									<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
										Actividad reciente
									</h2>
									<button
										type="button"
										className="text-xs font-medium text-famedic-navy hover:underline dark:text-famedic-light"
										onClick={() => handleTabChange(1)}
									>
										Ver timeline completo
									</button>
								</div>
								<AutomationTimeline
									items={timeline.slice(0, 12)}
									onSelect={setSelectedEvent}
									compact
								/>
							</section>
						</TabPanel>

						<TabPanel>
							<AutomationTimeline
								items={timeline}
								onSelect={setSelectedEvent}
							/>
						</TabPanel>

						<TabPanel>
							<AutomationDriversTable
								drivers={drivers}
								onSelectDriver={(driver) =>
									setSelectedEvent({
										id: `driver-${driver.key}`,
										automation: driver.layer,
										driver: driver.name,
										result: driver.status,
										occurred_at: driver.last_execution_at,
										duration_ms: driver.avg_duration_ms,
										retryable: driver.retryables > 0,
										meta: driver,
										source: "driver_catalog",
									})
								}
							/>
						</TabPanel>

						<TabPanel>
							<AutomationQueuePanel
								queue={queue}
								queueActionUrl={queueActionUrl}
								onChanged={handleRefresh}
							/>
						</TabPanel>

						<TabPanel>
							<AutomationPerformanceCharts performance={performance} />
						</TabPanel>

						<TabPanel>
							<AutomationDiagnostics
								catalog={diagnosticsCatalog}
								diagnosticUrl={diagnosticUrl}
							/>
						</TabPanel>

						<TabPanel>
							<AutomationArchitecture
								architecture={architecture}
								roadmap={roadmap}
							/>
						</TabPanel>
					</TabPanels>
				</TabGroup>

				<AutomationEventDrawer
					event={selectedEvent}
					open={Boolean(selectedEvent)}
					onClose={() => setSelectedEvent(null)}
				/>
			</div>
		</AdminLayout>
	);
}
