import useZohoSalesIQTracking from "@/Hooks/useZohoSalesIQTracking";

export default function AppLayout({ children }) {
	useZohoSalesIQTracking();

	return <div className="min-h-screen">{children}</div>;
}
