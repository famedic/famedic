import { Deferred, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import DashboardHeader from "@/Components/Admin/ActiveCampaign/Dashboard/DashboardHeader";
import DashboardOverview from "@/Components/Admin/ActiveCampaign/Dashboard/DashboardOverview";
import DashboardHealth from "@/Components/Admin/ActiveCampaign/Dashboard/DashboardHealth";
import DashboardBusiness from "@/Components/Admin/ActiveCampaign/Dashboard/DashboardBusiness";
import DashboardCharts, {
	DashboardChartsSkeleton,
} from "@/Components/Admin/ActiveCampaign/Dashboard/DashboardCharts";
import DashboardTables from "@/Components/Admin/ActiveCampaign/Dashboard/DashboardTables";
import DashboardDefinitions from "@/Components/Admin/ActiveCampaign/Dashboard/DashboardDefinitions";

export default function ActiveCampaignDashboard({
	filters,
	health = [],
	business = [],
	tables = {},
	meta = {},
	alertsUrl,
	logsUrl,
	healthUrl,
	settingsUrl,
	eventsUrl,
}) {
	const refreshForm = useForm({
		start_date: filters?.start_date || "",
		end_date: filters?.end_date || "",
		refresh: 1,
	});

	const handleRefresh = () => {
		refreshForm.get(route("admin.activecampaign.dashboard"), {
			preserveState: false,
		});
	};

	return (
		<AdminLayout title="Marketing Intelligence · Dashboard">
			<div className="relative space-y-8 pb-4">
				<div className="pointer-events-none absolute inset-x-0 -top-6 h-40 bg-[radial-gradient(ellipse_at_top,_rgba(0,154,216,0.08),_transparent_60%)] dark:bg-[radial-gradient(ellipse_at_top,_rgba(213,242,120,0.06),_transparent_55%)]" />

				<div className="relative space-y-8">
					<DashboardHeader
						filters={filters}
						meta={meta}
						onRefresh={handleRefresh}
						refreshing={refreshForm.processing}
						alertsUrl={alertsUrl}
						logsUrl={logsUrl}
					/>

					<DashboardOverview
						filters={filters}
						meta={meta}
						disabled={refreshForm.processing}
					/>

					<DashboardHealth
						cards={health}
						healthUrl={healthUrl}
						settingsUrl={settingsUrl}
					/>

					<DashboardBusiness
						kpis={business}
						previousPeriod={meta?.previous_period}
					/>

					<Deferred data="charts" fallback={<DashboardChartsSkeleton />}>
						<DashboardCharts eventsUrl={eventsUrl} />
					</Deferred>

					<DashboardTables tables={tables} logsUrl={logsUrl} />

					<DashboardDefinitions definitions={meta?.definitions} />
				</div>
			</div>
		</AdminLayout>
	);
}
