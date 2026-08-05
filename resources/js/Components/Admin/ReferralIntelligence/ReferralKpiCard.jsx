import KpiCards from "@/Components/Admin/CartsDashboard/KpiCards";

/**
 * Wrapper del design system de KPIs para Referral Intelligence.
 */
export default function ReferralKpiCard({ kpis = [], columnsClassName }) {
	return <KpiCards kpis={kpis} columnsClassName={columnsClassName} />;
}
