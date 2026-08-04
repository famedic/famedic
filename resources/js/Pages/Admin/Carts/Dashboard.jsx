import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Text } from "@/Components/Catalyst/text";
import DashboardHeader from "@/Components/Admin/CartsDashboard/DashboardHeader";
import FiltersBar from "@/Components/Admin/CartsDashboard/FiltersBar";
import KpiCards from "@/Components/Admin/CartsDashboard/KpiCards";
import SalesVsAbandoned from "@/Components/Admin/CartsDashboard/SalesVsAbandoned";
import {
	SalesTrendChart,
	AbandonedTrendChart,
	RevenueTrendChart,
	AbandonedRevenueTrendChart,
} from "@/Components/Admin/CartsDashboard/SalesTrendChart";
import LaboratoryBreakdown from "@/Components/Admin/CartsDashboard/LaboratoryBreakdown";
import TopLaboratories from "@/Components/Admin/CartsDashboard/TopLaboratories";
import TopStudies from "@/Components/Admin/CartsDashboard/TopStudies";
import RevenueDistribution from "@/Components/Admin/CartsDashboard/RevenueDistribution";

export default function Dashboard({
	filters,
	filterOptions,
	kpis,
	salesVsAbandoned,
	trends,
	laboratories,
	laboratoryCharts,
	topStudies,
	revenueDistribution,
	meta,
	cartsIndexUrl,
	exportUrl,
}) {
	const refreshForm = useForm({
		...filters,
		refresh: 1,
	});

	const handleRefresh = () => {
		refreshForm.get(route("admin.carts.dashboard"), {
			preserveState: false,
		});
	};

	const handleExport = () => {
		router.post(exportUrl, {
			start_date: filters.start_date || "",
			end_date: filters.end_date || "",
			type: filters.type || "",
			display_status: filters.display_status || "",
		});
	};

	return (
		<AdminLayout title="Dashboard Comercial · Carritos">
			<div className="space-y-6">
				<DashboardHeader
					cartsIndexUrl={cartsIndexUrl}
					onRefresh={handleRefresh}
					onExport={handleExport}
					refreshing={refreshForm.processing}
					generatedAt={meta?.generated_at}
				/>

				<FiltersBar filters={filters} filterOptions={filterOptions} />

				<section className="space-y-3">
					<div>
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Indicadores clave
						</h2>
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Comparado contra{" "}
							{meta?.previous_period?.start_date} —{" "}
							{meta?.previous_period?.end_date}
						</Text>
					</div>
					<KpiCards kpis={kpis} />
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Ventas vs abandonos
					</h2>
					<SalesVsAbandoned data={salesVsAbandoned} />
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Laboratorios
					</h2>
					<LaboratoryBreakdown charts={laboratoryCharts} />
					<TopLaboratories laboratories={laboratories} />
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Tendencias
					</h2>
					<div className="grid gap-4 lg:grid-cols-2">
						<SalesTrendChart data={trends?.sales_count} />
						<AbandonedTrendChart data={trends?.abandoned_count} />
						<RevenueTrendChart data={trends?.sold_amount} />
						<AbandonedRevenueTrendChart data={trends?.abandoned_amount} />
					</div>
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Montos
					</h2>
					<RevenueDistribution data={revenueDistribution} />
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Top estudios
					</h2>
					<TopStudies data={topStudies} />
				</section>

				{meta?.definitions ? (
					<div className="rounded-xl border border-dashed border-zinc-300 p-4 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
						<p className="font-medium text-zinc-700 dark:text-zinc-300">
							Definiciones (Fase 1–2)
						</p>
						<ul className="mt-2 list-disc space-y-1 pl-4">
							<li>{meta.definitions.abandoned}</li>
							<li>{meta.definitions.recovered}</li>
							<li>{meta.definitions.revenue}</li>
							<li>{meta.definitions.lost_value}</li>
						</ul>
					</div>
				) : null}
			</div>
		</AdminLayout>
	);
}
