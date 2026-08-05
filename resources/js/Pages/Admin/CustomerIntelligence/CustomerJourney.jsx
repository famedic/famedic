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
import JourneyHeader from "@/Components/Admin/CustomerIntelligence/JourneyHeader";
import JourneyFilters from "@/Components/Admin/CustomerIntelligence/JourneyFilters";
import JourneyPipeline from "@/Components/Admin/CustomerIntelligence/JourneyPipeline";
import JourneyFunnelChart from "@/Components/Admin/CustomerIntelligence/JourneyFunnelChart";
import JourneySankey from "@/Components/Admin/CustomerIntelligence/JourneySankey";
import JourneyTimeline from "@/Components/Admin/CustomerIntelligence/JourneyTimeline";
import JourneyHeatmap from "@/Components/Admin/CustomerIntelligence/JourneyHeatmap";
import JourneyPaths from "@/Components/Admin/CustomerIntelligence/JourneyPaths";
import JourneyUsersTable from "@/Components/Admin/CustomerIntelligence/JourneyUsersTable";
import JourneyUserDrawer from "@/Components/Admin/CustomerIntelligence/JourneyUserDrawer";
import JourneyPredictiveCards from "@/Components/Admin/CustomerIntelligence/JourneyPredictiveCards";
import JourneyCompare from "@/Components/Admin/CustomerIntelligence/JourneyCompare";
import MarketingIntelligenceCards from "@/Components/Admin/CustomerIntelligence/MarketingIntelligenceCards";
import AiInsightsPanel from "@/Components/Admin/CustomerIntelligence/AiInsightsPanel";
import AutomationsGrid from "@/Components/Admin/CustomerIntelligence/AutomationsGrid";

const TABS = [
	{ id: "overview", label: "Overview" },
	{ id: "paths", label: "Paths" },
	{ id: "usuarios", label: "Usuarios" },
	{ id: "insights", label: "Insights" },
	{ id: "ia", label: "IA" },
];

const HEATMAP_LABELS = {
	registrations: "Registros",
	logins: "Logins",
	checkouts: "Checkouts",
	purchases: "Compras",
};

export default function CustomerJourney({
	filters,
	filterOptions,
	kpis,
	stages,
	previousStages,
	funnel,
	sankey,
	timeline,
	heatmap,
	paths,
	marketingInsights,
	aiInsights,
	automations,
	predictive,
	compare,
	users,
	drawer,
	meta,
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
		refreshForm.get(route("admin.customer-intelligence.customer-journey"), {
			preserveState: false,
		});
	};

	const handleTabChange = (index) => {
		setSelectedTab(index);
		router.get(
			route("admin.customer-intelligence.customer-journey"),
			{ ...filters, tab: TABS[index].id },
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	const openDrawer = (user) => {
		setSelectedCustomerId(user.id);
		setDrawerOpen(true);
		setDrawerLoading(true);
		router.reload({
			only: ["drawer"],
			data: { drawer_customer_id: user.id },
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
		<AdminLayout title="Customer Journey · Intelligence Center">
			<div className="space-y-6">
				<JourneyHeader
					customersIndexUrl={customersIndexUrl}
					dormantUrl={dormantUrl}
					onRefresh={handleRefresh}
					refreshing={refreshForm.processing}
					generatedAt={meta?.generated_at}
				/>

				<JourneyFilters filters={filters} filterOptions={filterOptions} />

				<section className="space-y-3">
					<div>
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Indicadores del journey
						</h2>
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Comparado contra {compare?.previous_label}
						</Text>
					</div>
					<KpiCards
						kpis={kpis}
						columnsClassName="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3"
					/>
				</section>

				<JourneyPipeline stages={stages} />

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
								<JourneyFunnelChart data={funnel} />
								<JourneySankey sankey={sankey} />
								<JourneyTimeline timeline={timeline} />
								<JourneyHeatmap
									data={heatmap}
									metricLabel={
										HEATMAP_LABELS[filters.heatmap_metric] || "Compras"
									}
								/>
							</div>
							<JourneyCompare
								compare={compare}
								currentFunnel={funnel}
								previousStages={previousStages}
							/>
							<JourneyPredictiveCards items={predictive} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<JourneyPaths paths={paths} />
							<div className="grid gap-4 xl:grid-cols-2">
								<JourneyFunnelChart data={funnel} />
								<JourneySankey sankey={sankey} />
							</div>
						</TabPanel>

						<TabPanel className="space-y-6">
							<JourneyUsersTable users={users} onSelect={openDrawer} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<MarketingIntelligenceCards items={marketingInsights} />
							<AutomationsGrid automations={automations} />
							<JourneyCompare
								compare={compare}
								currentFunnel={funnel}
								previousStages={previousStages}
							/>
						</TabPanel>

						<TabPanel className="space-y-6">
							<AiInsightsPanel insights={aiInsights} />
							<JourneyPredictiveCards items={predictive} />
							<AutomationsGrid automations={automations} />
						</TabPanel>
					</TabPanels>
				</TabGroup>

				{meta?.definitions ? (
					<div className="rounded-xl border border-dashed border-zinc-300 p-4 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
						<p className="font-medium text-zinc-700 dark:text-zinc-300">
							Definiciones · Customer Journey
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

			<JourneyUserDrawer
				open={drawerOpen}
				drawer={activeDrawer}
				loading={drawerLoading}
				onClose={closeDrawer}
			/>
		</AdminLayout>
	);
}
