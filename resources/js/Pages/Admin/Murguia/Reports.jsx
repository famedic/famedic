import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import ReportFilters from "@/Components/Admin/Murguia/ReportFilters";
import ReportTable from "@/Components/Admin/Murguia/ReportTable";
import ExportButtons from "@/Components/Admin/Murguia/ExportButtons";

export default function Reports({ filters, presets, rows }) {
	return (
		<AdminLayout title="Murguía — reportes de asegurados">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div>
						<Heading>Reportes de asegurados Murguía / Odessa</Heading>
						<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
							Listado filtrable y exportación CSV/Excel con los mismos criterios.
						</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button href={route("admin.murguia-dashboard.index")} outline>
							Dashboard
						</Button>
						<Button href={route("admin.murguia-reconciliation.index")} outline>
							Conciliación
						</Button>
						<Button href={route("admin.murguia-monitor.index")} outline>
							Monitor operativo
						</Button>
					</div>
				</div>

				<ReportFilters filters={filters} presets={presets} />

				<div className="flex flex-wrap items-center justify-between gap-4">
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						{rows.total.toLocaleString("es-MX")} resultado
						{rows.total === 1 ? "" : "s"}
					</Text>
					<ExportButtons filters={filters} />
				</div>

				<ReportTable rows={rows} />
			</div>
		</AdminLayout>
	);
}
