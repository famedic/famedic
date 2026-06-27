import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import SummaryCards from "@/Components/Admin/Murguia/SummaryCards";
import DashboardFilters from "@/Components/Admin/Murguia/DashboardFilters";
import MembershipPieChart, {
	LocalStatusPieChart,
} from "@/Components/Admin/Murguia/MembershipPieChart";
import AccountTypeBarChart, {
	SyncStatusBarChart,
	MonthlyBarChart,
} from "@/Components/Admin/Murguia/AccountTypeBarChart";

export default function Dashboard({ filters, summary, charts }) {
	return (
		<AdminLayout title="Murguía — dashboard de asegurados">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div>
						<Heading>Dashboard de asegurados Murguía / Odessa</Heading>
						<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
							Conciliación y resúmenes. El monitor operativo sigue disponible por
							separado.
						</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button href={route("admin.murguia-reports.index")} outline>
							Reportes
						</Button>
						<Button href={route("admin.murguia-reconciliation.index")} outline>
							Conciliación
						</Button>
						<Button href={route("admin.murguia-monitor.index")} outline>
							Monitor operativo
						</Button>
						<Button href={route("admin.murguia.upload")} outline>
							Carga Excel
						</Button>
						<Button href={route("admin.murguia.logs")} outline>
							Logs de auditoría
						</Button>
					</div>
				</div>

				<DashboardFilters filters={filters} />

				<SummaryCards summary={summary} />

				<div className="grid gap-4 lg:grid-cols-2">
					<MembershipPieChart segments={charts.membership_distribution} />
					<LocalStatusPieChart segments={charts.local_status_distribution} />
				</div>

				<div className="grid gap-4 lg:grid-cols-2">
					<AccountTypeBarChart data={charts.account_type_bars} />
					<SyncStatusBarChart data={charts.sync_status_bars} />
				</div>

				<MonthlyBarChart
					signups={charts.monthly_signups}
					payments={charts.monthly_payments}
				/>
			</div>
		</AdminLayout>
	);
}
