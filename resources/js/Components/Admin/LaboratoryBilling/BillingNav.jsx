import { Link } from "@inertiajs/react";
import clsx from "clsx";

const TABS = [
	{
		key: "dashboard",
		label: "Resumen general",
		route: "admin.laboratory-billing.dashboard",
	},
	{
		key: "requests",
		label: "Solicitudes",
		route: "admin.laboratory-billing.requests",
	},
	{
		key: "invoices",
		label: "Facturas",
		route: "admin.laboratory-billing.invoices",
	},
	{
		key: "tax-profiles",
		label: "Perfiles fiscales",
		route: "admin.laboratory-billing.tax-profiles.index",
	},
	{
		key: "reports",
		label: "Reportes",
		route: "admin.laboratory-billing.reports",
	},
	{
		key: "automatic-reports",
		label: "Reportes automáticos",
		route: "admin.laboratory-billing.automatic-reports.index",
		requiresAutomaticReportsAccess: true,
	},
];

export default function BillingNav({
	active,
	query = {},
	canManageAutomaticReports = false,
}) {
	const preserved = Object.fromEntries(
		Object.entries(query).filter(
			([, value]) => value !== null && value !== undefined && value !== "",
		),
	);
	const tabs = TABS.filter(
		(tab) => !tab.requiresAutomaticReportsAccess || canManageAutomaticReports,
	);

	return (
		<nav
			aria-label="Navegación de facturación"
			className="flex flex-wrap gap-2 border-b border-zinc-200 pb-3 dark:border-zinc-600/80"
		>
			{tabs.map((tab) => {
				const isActive = active === tab.key;
				return (
					<Link
						key={tab.key}
						href={route(tab.route, preserved)}
						aria-current={isActive ? "page" : undefined}
						className={clsx(
							"rounded-lg px-3 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime",
							isActive
								? "bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker"
								: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800/80",
						)}
					>
						{tab.label}
					</Link>
				);
			})}
		</nav>
	);
}
