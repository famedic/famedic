import { useMemo, useState } from "react";
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
import ReferralHeader from "@/Components/Admin/ReferralIntelligence/ReferralHeader";
import ReferralFilters from "@/Components/Admin/ReferralIntelligence/ReferralFilters";
import ReferralChartCard from "@/Components/Admin/ReferralIntelligence/ReferralChartCard";
import ReferralLeaderboard from "@/Components/Admin/ReferralIntelligence/ReferralLeaderboard";
import ReferralStatusDonut from "@/Components/Admin/ReferralIntelligence/ReferralStatusDonut";
import ReferralTable from "@/Components/Admin/ReferralIntelligence/ReferralTable";
import ReferralDrawer from "@/Components/Admin/ReferralIntelligence/ReferralDrawer";
import ReferralInsights from "@/Components/Admin/ReferralIntelligence/ReferralInsights";
import ReferralKpiCard from "@/Components/Admin/ReferralIntelligence/ReferralKpiCard";

const TABS = [
	{ id: "overview", label: "Overview" },
	{ id: "inviters", label: "Invitadores" },
	{ id: "leaderboard", label: "Leaderboard" },
	{ id: "insights", label: "Insights" },
	{ id: "ia", label: "IA" },
];

export default function CustomerReferrals({
	filters = {},
	filterOptions = {},
	kpis = [],
	evolution = [],
	topInviters = [],
	statusBreakdown = [],
	leaderboards = {},
	marketingInsights = [],
	aiInsights,
	automations = [],
	performance = [],
	compare,
	meta,
	inviters,
	drawer,
	customersIndexUrl,
	hubUrl,
	canExport = false,
}) {
	const initialTab = Math.max(
		0,
		TABS.findIndex((tab) => tab.id === (filters?.tab || "overview")),
	);
	const [selectedTab, setSelectedTab] = useState(initialTab);
	const [filtersOpen, setFiltersOpen] = useState(true);
	const [drawerOpen, setDrawerOpen] = useState(Boolean(drawer?.user_id));
	const [drawerLoading, setDrawerLoading] = useState(false);
	const [selectedUserId, setSelectedUserId] = useState(drawer?.user_id || null);

	const refreshForm = useForm({
		...filters,
		refresh: 1,
	});

	const handleRefresh = () => {
		refreshForm.get(route("admin.customers.referrals"), {
			preserveState: false,
		});
	};

	const handleExport = () => {
		window.location.href = route("admin.customers.referrals", {
			...filters,
			export: "csv",
		});
	};

	const handleGranularityChange = (granularity) => {
		router.get(
			route("admin.customers.referrals"),
			{ ...filters, granularity, tab: "overview" },
			{ preserveState: true },
		);
	};

	const handleTabChange = (index) => {
		setSelectedTab(index);
		router.get(
			route("admin.customers.referrals"),
			{ ...filters, tab: TABS[index].id },
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	const openDrawer = (row) => {
		setSelectedUserId(row.id);
		setDrawerOpen(true);
		setDrawerLoading(true);
		router.reload({
			only: ["drawer"],
			data: { drawer_user_id: row.id },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => setDrawerLoading(false),
		});
	};

	const closeDrawer = () => {
		setDrawerOpen(false);
		setSelectedUserId(null);
		router.reload({
			only: ["drawer"],
			data: { drawer_user_id: null },
			preserveState: true,
			preserveScroll: true,
		});
	};

	const activeDrawer =
		drawer?.user_id && drawer.user_id === selectedUserId ? drawer : null;

	const iaOnlyInsights = useMemo(
		() => ({
			marketingInsights: [],
			aiInsights,
			automations,
			compare: null,
			performance: [],
		}),
		[aiInsights, automations],
	);

	return (
		<AdminLayout title="Referenciados · Referral Intelligence">
			<div className="space-y-6">
				<ReferralHeader
					customersIndexUrl={customersIndexUrl}
					hubUrl={hubUrl}
					onRefresh={handleRefresh}
					onExport={handleExport}
					onToggleFilters={() => setFiltersOpen((value) => !value)}
					refreshing={refreshForm.processing}
					generatedAt={meta?.generated_at}
					canExport={canExport}
					filtersOpen={filtersOpen}
				/>

				<ReferralFilters
					filters={filters}
					filterOptions={filterOptions}
					open={filtersOpen}
				/>

				<section className="space-y-3">
					<div>
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Indicadores clave
						</h2>
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Comparado contra {meta?.previous_period?.start_date} —{" "}
							{meta?.previous_period?.end_date}
						</Text>
					</div>
					<ReferralKpiCard
						kpis={kpis}
						columnsClassName="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
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
												? "rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white"
												: "rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
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
							<div className="grid gap-4 xl:grid-cols-3">
								<div className="xl:col-span-2">
									<ReferralChartCard
										data={evolution}
										granularity={filters.granularity || "day"}
										onGranularityChange={handleGranularityChange}
									/>
								</div>
								<ReferralLeaderboard
									items={topInviters.slice(0, 10)}
									onSelect={openDrawer}
								/>
							</div>
							<div className="grid gap-4 xl:grid-cols-2">
								<ReferralStatusDonut data={statusBreakdown} />
								<ReferralLeaderboard
									title="Top Empresas"
									description="Empresas Odessa con más referidos."
									items={leaderboards.companies || []}
									variant="companies"
								/>
							</div>
						</TabPanel>

						<TabPanel>
							<ReferralTable
								inviters={inviters}
								view={filters.view || "table"}
								onOpen={openDrawer}
							/>
						</TabPanel>

						<TabPanel className="space-y-4">
							<div className="grid gap-4 xl:grid-cols-2">
								<ReferralLeaderboard
									title="Top Invitadores"
									items={leaderboards.inviters || []}
									onSelect={openDrawer}
								/>
								<ReferralLeaderboard
									title="Top Empresas"
									items={leaderboards.companies || []}
									variant="companies"
								/>
								<ReferralLeaderboard
									title="Top Embajadores"
									description="Niveles Oro, Platino y Diamante."
									items={leaderboards.ambassadors || []}
									onSelect={openDrawer}
								/>
								<ReferralLeaderboard
									title="Top Influencers"
									description="10+ referidos en el periodo."
									items={leaderboards.influencers || []}
									onSelect={openDrawer}
								/>
							</div>
						</TabPanel>

						<TabPanel>
							<ReferralInsights
								marketingInsights={marketingInsights}
								aiInsights={aiInsights}
								automations={automations}
								compare={compare}
								performance={performance}
							/>
						</TabPanel>

						<TabPanel>
							<ReferralInsights {...iaOnlyInsights} />
						</TabPanel>
					</TabPanels>
				</TabGroup>
			</div>

			<ReferralDrawer
				open={drawerOpen}
				drawer={activeDrawer}
				loading={drawerLoading}
				onClose={closeDrawer}
			/>
		</AdminLayout>
	);
}
