import { useState } from "react";
import { usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import DataLegend from "@/Components/Common/DataLegend";
import DataProvenanceCard from "@/Components/Common/DataProvenanceCard";
import Header, {
	ContactSearchBar,
} from "@/Components/Admin/Integrations/ActiveCampaignOperations/Header";
import ApiHealthCard from "@/Components/Admin/Integrations/ActiveCampaignOperations/ApiHealthCard";
import SyncCard from "@/Components/Admin/Integrations/ActiveCampaignOperations/SyncCard";
import MirrorCard from "@/Components/Admin/Integrations/ActiveCampaignOperations/MirrorCard";
import ContactIntelligenceCard from "@/Components/Admin/Integrations/ActiveCampaignOperations/ContactIntelligenceCard";
import ActivityFeed from "@/Components/Admin/Integrations/ActiveCampaignOperations/ActivityFeed";
import DiagnosticsCard from "@/Components/Admin/Integrations/ActiveCampaignOperations/DiagnosticsCard";
import { ExecutiveDashboard } from "@/Components/Admin/Integrations/ActiveCampaignOperations/ExecutiveDashboard";
import ConversionFunnel from "@/Components/Admin/Integrations/ActiveCampaignOperations/ConversionFunnel";
import LaboratoriesDashboard from "@/Components/Admin/Integrations/ActiveCampaignOperations/LaboratoriesDashboard";
import MembershipsDashboard from "@/Components/Admin/Integrations/ActiveCampaignOperations/MembershipsDashboard";
import PurchasesDashboard from "@/Components/Admin/Integrations/ActiveCampaignOperations/PurchasesDashboard";
import AutomationsDashboard from "@/Components/Admin/Integrations/ActiveCampaignOperations/AutomationsDashboard";
import ContactHealthPanel from "@/Components/Admin/Integrations/ActiveCampaignOperations/ContactHealthPanel";
import AlertsPanel from "@/Components/Admin/Integrations/ActiveCampaignOperations/AlertsPanel";
import GlobalSearch from "@/Components/Admin/Integrations/ActiveCampaignOperations/GlobalSearch";
import AnalyticsCharts from "@/Components/Admin/Integrations/ActiveCampaignOperations/AnalyticsCharts";
import FiltersBar from "@/Components/Admin/Integrations/ActiveCampaignOperations/FiltersBar";
import ExportControls from "@/Components/Admin/Integrations/ActiveCampaignOperations/ExportControls";
import AutoRefreshControls from "@/Components/Admin/Integrations/ActiveCampaignOperations/AutoRefreshControls";

export default function ActiveCampaignOperations({
	health,
	sync,
	mirror,
	intelligence,
	activity,
	diagnostics,
	meta,
	urls,
	platform = {},
}) {
	const [searchOpen, setSearchOpen] = useState(false);
	const flash = usePage().props.flashMessage;
	const filters = platform.filters || {};
	const updatedAt = platform.generated_at || meta?.generated_at || null;

	return (
		<AdminLayout title="ActiveCampaign Operations Center">
			<div className="space-y-6 pb-8">
				<Header
					urls={urls}
					meta={{
						...meta,
						generated_at: updatedAt,
					}}
					onOpenSearch={() => setSearchOpen(true)}
				/>

				<DataLegend />

				<div className="flex flex-wrap items-center justify-between gap-3">
					<p className="text-xs text-zinc-500">
						Operations Platform · auditabilidad de datos · referencia para
						integraciones futuras
					</p>
					<AutoRefreshControls urls={urls} filters={filters} />
				</div>

				{flash?.message ? (
					<div
						className={`rounded-xl border px-4 py-3 text-sm ${
							flash.type === "success"
								? "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300"
								: "border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300"
						}`}
					>
						{flash.message}
					</div>
				) : null}

				<ContactSearchBar
					urls={urls}
					open={searchOpen}
					onClose={() => setSearchOpen(false)}
				/>

				<div className="grid gap-6 xl:grid-cols-3">
					<div className="xl:col-span-2 space-y-6">
						<FiltersBar filters={filters} urls={urls} updatedAt={updatedAt} />
						<GlobalSearch
							urls={urls}
							filters={filters}
							results={platform.search_results}
							updatedAt={updatedAt}
						/>
					</div>
					<DataProvenanceCard
						title="Procedencia de la consola"
						source="HYBRID"
						mode="CALCULATED"
						endpoint="Inertia · OperationsPlatformDto"
						service="ActiveCampaignOperationsPlatformService"
						updatedAt={updatedAt}
						ttl="cache 2 min"
						quality="B"
						owner="Integrations / Operations"
						apiVersion={health?.api_version || "v3"}
					/>
				</div>

				<ExportControls urls={urls} filters={filters} updatedAt={updatedAt} />

				<ExecutiveDashboard
					executive={platform.executive || []}
					updatedAt={updatedAt}
				/>

				<div className="grid gap-6 xl:grid-cols-5">
					<div className="xl:col-span-3">
						<ConversionFunnel
							funnel={platform.funnel || []}
							updatedAt={updatedAt}
						/>
					</div>
					<div className="xl:col-span-2">
						<AlertsPanel alerts={platform.alerts || []} updatedAt={updatedAt} />
					</div>
				</div>

				<LaboratoriesDashboard
					laboratories={platform.laboratories || []}
					updatedAt={updatedAt}
				/>
				<MembershipsDashboard
					memberships={platform.memberships || {}}
					updatedAt={updatedAt}
				/>
				<PurchasesDashboard
					purchases={platform.purchases || {}}
					analytics={platform.analytics}
					updatedAt={updatedAt}
				/>
				<AutomationsDashboard
					automations={platform.automations || []}
					updatedAt={updatedAt}
				/>
				<ContactHealthPanel
					contactHealth={platform.contact_health || {}}
					updatedAt={updatedAt}
				/>
				<AnalyticsCharts
					analytics={platform.analytics || {}}
					updatedAt={updatedAt}
				/>

				{/* Phase 1 — enriquecida, sin remover */}
				<ApiHealthCard health={health} updatedAt={updatedAt} />
				<SyncCard sync={sync} updatedAt={updatedAt} />
				<MirrorCard mirror={mirror} updatedAt={updatedAt} />
				<ContactIntelligenceCard
					intelligence={intelligence}
					updatedAt={updatedAt}
				/>

				<div className="grid gap-6 xl:grid-cols-5">
					<div className="xl:col-span-3">
						<ActivityFeed activity={activity} updatedAt={updatedAt} />
					</div>
					<div className="xl:col-span-2">
						<DiagnosticsCard
							diagnostics={diagnostics}
							urls={urls}
							updatedAt={updatedAt}
						/>
					</div>
				</div>
			</div>
		</AdminLayout>
	);
}
