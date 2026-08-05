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
import KpiCards from "@/Components/Admin/CartsDashboard/KpiCards";
import DormantHeader from "@/Components/Admin/CustomerIntelligence/DormantHeader";
import DormantFilters from "@/Components/Admin/CustomerIntelligence/DormantFilters";
import EvolutionChart from "@/Components/Admin/CustomerIntelligence/EvolutionChart";
import DaysBucketDonut from "@/Components/Admin/CustomerIntelligence/DaysBucketDonut";
import SourceBarChart from "@/Components/Admin/CustomerIntelligence/SourceBarChart";
import ConversionFunnel from "@/Components/Admin/CustomerIntelligence/ConversionFunnel";
import DormantCustomersTable from "@/Components/Admin/CustomerIntelligence/DormantCustomersTable";
import DormantCustomerDrawer from "@/Components/Admin/CustomerIntelligence/DormantCustomerDrawer";
import MarketingIntelligenceCards from "@/Components/Admin/CustomerIntelligence/MarketingIntelligenceCards";
import AiInsightsPanel from "@/Components/Admin/CustomerIntelligence/AiInsightsPanel";
import SegmentsGrid from "@/Components/Admin/CustomerIntelligence/SegmentsGrid";
import AutomationsGrid from "@/Components/Admin/CustomerIntelligence/AutomationsGrid";
import AdvancedMetricsPanel from "@/Components/Admin/CustomerIntelligence/AdvancedMetricsPanel";

const TABS = [
	{ id: "resumen", label: "Resumen" },
	{ id: "clientes", label: "Clientes" },
	{ id: "conversion", label: "Conversión" },
	{ id: "segmentacion", label: "Segmentación" },
	{ id: "campanas", label: "Campañas" },
	{ id: "fuentes", label: "Fuentes" },
	{ id: "ia", label: "IA Insights" },
];

export default function DormantCustomers({
	filters,
	filterOptions,
	kpis,
	evolution,
	daysBuckets,
	bySource,
	funnel,
	byState,
	byCity,
	segments,
	sourceConversion,
	marketingIntelligence,
	aiInsights,
	automations,
	advancedMetrics,
	customers,
	drawer,
	meta,
	customersIndexUrl,
	canExport,
}) {
	const initialTab = Math.max(
		0,
		TABS.findIndex((tab) => tab.id === (filters?.tab || "resumen")),
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
		refreshForm.get(route("admin.customers.dormant"), {
			preserveState: false,
		});
	};

	const handleExport = (format) => {
		window.location.href = route("admin.customers.dormant", {
			...filters,
			export: format,
		});
	};

	const handleGranularityChange = (granularity) => {
		router.get(
			route("admin.customers.dormant"),
			{ ...filters, granularity, tab: "resumen" },
			{ preserveState: true },
		);
	};

	const handleTabChange = (index) => {
		setSelectedTab(index);
		router.get(
			route("admin.customers.dormant"),
			{ ...filters, tab: TABS[index].id },
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	const openDrawer = (customer) => {
		setSelectedCustomerId(customer.id);
		setDrawerOpen(true);
		setDrawerLoading(true);
		router.reload({
			only: ["drawer"],
			data: { drawer_customer_id: customer.id },
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

	const sourceConversionBars = useMemo(
		() =>
			(sourceConversion?.by_source || []).map((row) => ({
				label: row.label,
				value: row.dormant,
			})),
		[sourceConversion],
	);

	return (
		<AdminLayout title="Clientes Dormidos · Customer Intelligence">
			<div className="space-y-6">
				<DormantHeader
					customersIndexUrl={customersIndexUrl}
					onRefresh={handleRefresh}
					onExport={handleExport}
					refreshing={refreshForm.processing}
					generatedAt={meta?.generated_at}
					canExport={canExport}
				/>

				<DormantFilters filters={filters} filterOptions={filterOptions} />

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
					<KpiCards
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
								<EvolutionChart
									data={evolution}
									granularity={filters.granularity || "day"}
									onGranularityChange={handleGranularityChange}
								/>
								<DaysBucketDonut data={daysBuckets} />
								<SourceBarChart data={bySource} />
								<ConversionFunnel data={funnel} />
							</div>
							<MarketingIntelligenceCards items={marketingIntelligence} />
							<AiInsightsPanel insights={aiInsights} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<DormantCustomersTable
								customers={customers}
								onSelect={openDrawer}
							/>
						</TabPanel>

						<TabPanel className="space-y-6">
							<div className="grid gap-4 xl:grid-cols-2">
								<ConversionFunnel data={funnel} />
								<SourceBarChart
									data={(sourceConversion?.by_source || []).map((row) => ({
										label: row.label,
										value: row.conversion,
									}))}
									title="Conversión por fuente (%)"
								/>
							</div>
							<AdvancedMetricsPanel
								metrics={advancedMetrics}
								byState={byState}
								byCity={byCity}
							/>
						</TabPanel>

						<TabPanel className="space-y-6">
							<SegmentsGrid segments={segments} filters={filters} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<AutomationsGrid automations={automations} />
							<MarketingIntelligenceCards items={marketingIntelligence} />
						</TabPanel>

						<TabPanel className="space-y-6">
							<div className="grid gap-4 xl:grid-cols-2">
								<SourceBarChart data={bySource} />
								<SourceBarChart
									data={sourceConversionBars}
									title="Dormidos por fuente (detalle)"
								/>
							</div>
							<div className="grid gap-4 lg:grid-cols-2">
								{(sourceConversion?.by_source || []).map((row) => (
									<div
										key={row.key}
										className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
									>
										<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
											{row.label}
										</p>
										<div className="mt-3 grid grid-cols-3 gap-3 text-center">
											<div>
												<p className="text-[11px] uppercase text-zinc-400">
													Registros
												</p>
												<p className="text-lg font-semibold tabular-nums">
													{row.registered}
												</p>
											</div>
											<div>
												<p className="text-[11px] uppercase text-zinc-400">
													Convertidos
												</p>
												<p className="text-lg font-semibold tabular-nums text-emerald-600">
													{row.converted}
												</p>
											</div>
											<div>
												<p className="text-[11px] uppercase text-zinc-400">
													Conv.
												</p>
												<p className="text-lg font-semibold tabular-nums">
													{row.conversion}%
												</p>
											</div>
										</div>
									</div>
								))}
							</div>
						</TabPanel>

						<TabPanel className="space-y-6">
							<AiInsightsPanel insights={aiInsights} />
							<AutomationsGrid automations={automations} />
						</TabPanel>
					</TabPanels>
				</TabGroup>

				{meta?.definitions ? (
					<div className="rounded-xl border border-dashed border-zinc-300 p-4 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
						<p className="font-medium text-zinc-700 dark:text-zinc-300">
							Definiciones · Customer Intelligence Center
						</p>
						<ul className="mt-2 list-disc space-y-1 pl-4">
							{Object.values(meta.definitions).map((definition) => (
								<li key={definition}>{definition}</li>
							))}
						</ul>
						{meta.data_gaps?.length ? (
							<>
								<p className="mt-3 font-medium text-zinc-700 dark:text-zinc-300">
									Gaps de datos (roadmap)
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

			<DormantCustomerDrawer
				open={drawerOpen}
				drawer={activeDrawer}
				loading={drawerLoading}
				onClose={closeDrawer}
			/>
		</AdminLayout>
	);
}
