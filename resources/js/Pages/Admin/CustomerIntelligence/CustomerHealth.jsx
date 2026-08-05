import { useState } from "react";
import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Text } from "@/Components/Catalyst/text";
import {
	Tab,
	TabGroup,
	TabList,
	TabPanel,
	TabPanels,
} from "@/Components/Catalyst/tabs";
import KpiCards from "@/Components/Admin/CartsDashboard/KpiCards";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import HealthHeader from "@/Components/Admin/CustomerIntelligence/HealthHeader";
import HealthFilters from "@/Components/Admin/CustomerIntelligence/HealthFilters";
import HealthGauge from "@/Components/Admin/CustomerIntelligence/HealthGauge";
import HealthHistogram from "@/Components/Admin/CustomerIntelligence/HealthHistogram";
import HealthScatter from "@/Components/Admin/CustomerIntelligence/HealthScatter";
import HealthCustomersTable from "@/Components/Admin/CustomerIntelligence/HealthCustomersTable";
import HealthCustomerDrawer from "@/Components/Admin/CustomerIntelligence/HealthCustomerDrawer";
import HealthPredictivePanel from "@/Components/Admin/CustomerIntelligence/HealthPredictivePanel";
import HealthSegments from "@/Components/Admin/CustomerIntelligence/HealthSegments";
import AiInsightsPanel from "@/Components/Admin/CustomerIntelligence/AiInsightsPanel";
import AutomationsGrid from "@/Components/Admin/CustomerIntelligence/AutomationsGrid";

const TABS = [
	{ id: "overview", label: "Overview" },
	{ id: "scores", label: "Scores" },
	{ id: "predictive", label: "Predictive" },
	{ id: "segments", label: "Segments" },
	{ id: "ia", label: "IA" },
];

export default function CustomerHealth({
	filters,
	filterOptions,
	kpis,
	gauge,
	histogram,
	scatter,
	byCity,
	bySource,
	byChannel,
	bands,
	segments,
	predictiveAverages,
	recommendations,
	aiInsights,
	automations,
	customers,
	drawer,
	meta,
	journeyUrl,
	cohortsUrl,
	dormantUrl,
	customersIndexUrl,
}) {
	const initialTab = Math.max(
		0,
		TABS.findIndex((tab) => tab.id === (filters?.tab || "overview")),
	);
	const [selectedTab, setSelectedTab] = useState(initialTab);
	const [drawerOpen, setDrawerOpen] = useState(Boolean(drawer?.customer_id));
	const [drawerLoading, setDrawerLoading] = useState(false);
	const [selectedCustomerId, setSelectedCustomerId] = useState(
		drawer?.customer_id || null,
	);

	const refreshForm = useForm({
		...filters,
		refresh: 1,
	});

	const handleRefresh = () => {
		refreshForm.get(route("admin.customer-intelligence.customer-health"), {
			preserveState: false,
		});
	};

	const handleTabChange = (index) => {
		setSelectedTab(index);
		router.get(
			route("admin.customer-intelligence.customer-health"),
			{ ...filters, tab: TABS[index].id },
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	const openDrawer = (row) => {
		setSelectedCustomerId(row.id);
		setDrawerOpen(true);
		setDrawerLoading(true);
		router.reload({
			only: ["drawer"],
			data: { drawer_customer_id: row.id },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => setDrawerLoading(false),
		});
	};

	const closeDrawer = () => {
		setDrawerOpen(false);
		setSelectedCustomerId(null);
		router.reload({
			only: ["drawer"],
			data: { drawer_customer_id: null },
			preserveState: true,
			preserveScroll: true,
		});
	};

	const activeDrawer =
		drawer?.customer_id && drawer.customer_id === selectedCustomerId
			? drawer
			: null;

	return (
		<AdminLayout title="Customer Health · Intelligence Center">
			<div className="space-y-6">
				<HealthHeader
					customersIndexUrl={customersIndexUrl}
					journeyUrl={journeyUrl}
					cohortsUrl={cohortsUrl}
					dormantUrl={dormantUrl}
					onRefresh={handleRefresh}
					refreshing={refreshForm.processing}
					generatedAt={meta?.generated_at}
					sampleSize={meta?.sample_size}
				/>

				<HealthFilters filters={filters} filterOptions={filterOptions} />

				<section className="space-y-3">
					<div>
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Indicadores de salud
						</h2>
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Comparado contra {meta?.previous_period?.start_date} —{" "}
							{meta?.previous_period?.end_date}
						</Text>
					</div>
					<KpiCards
						kpis={kpis}
						columnsClassName="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
					/>
				</section>

				<TabGroup selectedIndex={selectedTab} onChange={handleTabChange}>
					<TabList className="gap-1 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/60">
						{TABS.map((tab) => (
							<Tab key={tab.id} className="shrink-0">
								{(selected) => (
									<span
										className={
											selected
												? "inline-flex rounded-lg bg-white px-3 py-2 text-sm font-semibold text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50"
												: "inline-flex rounded-lg px-3 py-2 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
										}
									>
										{tab.label}
									</span>
								)}
							</Tab>
						))}
					</TabList>

					<TabPanels className="mt-6 space-y-6">
						<TabPanel className="space-y-6">
							<div className="grid gap-4 xl:grid-cols-2">
								<HealthGauge gauge={gauge} bands={bands} />
								<HealthHistogram data={histogram} />
								<HealthScatter data={scatter} />
								<ChartCard title="Health por dimensión" description="Ciudad, fuente y canal.">
									<div className="space-y-4 text-sm">
										<div>
											<p className="mb-2 text-xs font-semibold uppercase text-zinc-400">
												Ciudad
											</p>
											<ul className="space-y-1.5">
												{(byCity || []).slice(0, 5).map((row) => (
													<li key={row.label} className="flex justify-between">
														<span className="text-zinc-600 dark:text-zinc-300">
															{row.label}
														</span>
														<span className="font-semibold tabular-nums">
															{row.average}
														</span>
													</li>
												))}
											</ul>
										</div>
										<div>
											<p className="mb-2 text-xs font-semibold uppercase text-zinc-400">
												Fuente
											</p>
											<ul className="space-y-1.5">
												{(bySource || []).map((row) => (
													<li key={row.label} className="flex justify-between">
														<span className="text-zinc-600 dark:text-zinc-300">
															{row.label}
														</span>
														<span className="font-semibold tabular-nums">
															{row.average}
														</span>
													</li>
												))}
											</ul>
										</div>
										<div>
											<p className="mb-2 text-xs font-semibold uppercase text-zinc-400">
												Canal
											</p>
											<ul className="space-y-1.5">
												{(byChannel || []).map((row) => (
													<li key={row.label} className="flex justify-between">
														<span className="text-zinc-600 dark:text-zinc-300">
															{row.label}
														</span>
														<span className="font-semibold tabular-nums">
															{row.average ?? "—"}
														</span>
													</li>
												))}
											</ul>
										</div>
									</div>
								</ChartCard>
							</div>
							<AiInsightsPanel insights={aiInsights} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<HealthCustomersTable customers={customers} onSelect={openDrawer} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<HealthPredictivePanel
								averages={predictiveAverages}
								recommendations={recommendations}
							/>
							<AutomationsGrid automations={automations} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<HealthSegments segments={segments} filters={filters} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<AiInsightsPanel insights={aiInsights} />
							<HealthPredictivePanel
								averages={predictiveAverages}
								recommendations={recommendations}
							/>
							<AutomationsGrid automations={automations} />
						</TabPanel>
					</TabPanels>
				</TabGroup>

				{meta?.definitions ? (
					<div className="rounded-xl border border-dashed border-zinc-300 p-4 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
						<p className="font-medium text-zinc-700 dark:text-zinc-300">
							Definiciones · Customer Health
						</p>
						<ul className="mt-2 list-disc space-y-1 pl-4">
							{Object.values(meta.definitions).map((definition) => (
								<li key={definition}>{definition}</li>
							))}
						</ul>
						{meta.data_gaps?.length ? (
							<>
								<p className="mt-3 font-medium text-zinc-700 dark:text-zinc-300">
									Gaps / roadmap
								</p>
								<ul className="mt-2 list-disc space-y-1 pl-4">
									{meta.data_gaps.map((gap) => (
										<li key={gap}>{gap}</li>
									))}
								</ul>
							</>
						) : null}
					</div>
				) : null}
			</div>

			<HealthCustomerDrawer
				open={drawerOpen}
				drawer={activeDrawer}
				loading={drawerLoading}
				onClose={closeDrawer}
			/>
		</AdminLayout>
	);
}
