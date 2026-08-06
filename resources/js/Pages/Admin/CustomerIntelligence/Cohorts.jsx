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
import CohortsHeader from "@/Components/Admin/CustomerIntelligence/CohortsHeader";
import CohortsFilters from "@/Components/Admin/CustomerIntelligence/CohortsFilters";
import CohortHeatmap from "@/Components/Admin/CustomerIntelligence/CohortHeatmap";
import RetentionCurveChart from "@/Components/Admin/CustomerIntelligence/RetentionCurveChart";
import SourceRetentionCompare from "@/Components/Admin/CustomerIntelligence/SourceRetentionCompare";
import RepeatPurchaseLadder from "@/Components/Admin/CustomerIntelligence/RepeatPurchaseLadder";
import DaysBetweenPurchasesChart from "@/Components/Admin/CustomerIntelligence/DaysBetweenPurchasesChart";
import ChurnBucketsChart from "@/Components/Admin/CustomerIntelligence/ChurnBucketsChart";
import LtvBreakdown from "@/Components/Admin/CustomerIntelligence/LtvBreakdown";
import CohortsSegments from "@/Components/Admin/CustomerIntelligence/CohortsSegments";
import AiInsightsPanel from "@/Components/Admin/CustomerIntelligence/AiInsightsPanel";
import AutomationsGrid from "@/Components/Admin/CustomerIntelligence/AutomationsGrid";

const TABS = [
	{ id: "overview", label: "Overview" },
	{ id: "retention", label: "Retention" },
	{ id: "repeat", label: "Repeat" },
	{ id: "churn", label: "Churn" },
	{ id: "ltv", label: "LTV" },
	{ id: "ia", label: "IA" },
];

export default function Cohorts({
	filters,
	filterOptions,
	kpis,
	heatmap,
	curves,
	sourceComparison,
	repeatLadder,
	daysBetween,
	churn,
	ltv,
	segments,
	aiInsights,
	automations,
	meta,
	journeyUrl,
	dormantUrl,
	customersIndexUrl,
}) {
	const initialTab = Math.max(
		0,
		TABS.findIndex((tab) => tab.id === (filters?.tab || "overview")),
	);
	const [selectedTab, setSelectedTab] = useState(initialTab);

	const refreshForm = useForm({
		...filters,
		refresh: 1,
	});

	const handleRefresh = () => {
		refreshForm.get(route("admin.customer-intelligence.cohorts"), {
			preserveState: false,
		});
	};

	const handleTabChange = (index) => {
		setSelectedTab(index);
		router.get(
			route("admin.customer-intelligence.cohorts"),
			{ ...filters, tab: TABS[index].id },
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	return (
		<AdminLayout title="Cohorts & Retention · Intelligence Center">
			<div className="space-y-6">
				<CohortsHeader
					customersIndexUrl={customersIndexUrl}
					journeyUrl={journeyUrl}
					dormantUrl={dormantUrl}
					onRefresh={handleRefresh}
					refreshing={refreshForm.processing}
					generatedAt={meta?.generated_at}
				/>

				<CohortsFilters filters={filters} filterOptions={filterOptions} />

				<section className="space-y-3">
					<div>
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Indicadores de retención
						</h2>
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Comparado contra {meta?.previous_period?.start_date} —{" "}
							{meta?.previous_period?.end_date}
						</Text>
					</div>
					<KpiCards
						kpis={kpis}
						columnsClassName="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
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
							<CohortHeatmap heatmap={heatmap} />
							<div className="grid gap-4 xl:grid-cols-2">
								<RetentionCurveChart curves={curves} />
								<SourceRetentionCompare rows={sourceComparison} />
							</div>
							<CohortsSegments segments={segments} />
							<AiInsightsPanel insights={aiInsights} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<CohortHeatmap heatmap={heatmap} />
							<RetentionCurveChart curves={curves} />
							<SourceRetentionCompare rows={sourceComparison} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<div className="grid gap-4 xl:grid-cols-2">
								<RepeatPurchaseLadder steps={repeatLadder} />
								<DaysBetweenPurchasesChart data={daysBetween} />
							</div>
						</TabPanel>

						<TabPanel className="space-y-6">
							<ChurnBucketsChart data={churn} />
							<AutomationsGrid automations={automations} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<LtvBreakdown rows={ltv} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<AiInsightsPanel insights={aiInsights} />
							<AutomationsGrid automations={automations} />
							<SourceRetentionCompare rows={sourceComparison} />
						</TabPanel>
					</TabPanels>
				</TabGroup>

				{meta?.definitions ? (
					<div className="rounded-xl border border-dashed border-zinc-300 p-4 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
						<p className="font-medium text-zinc-700 dark:text-zinc-300">
							Definiciones · Cohorts & Retention
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
		</AdminLayout>
	);
}
