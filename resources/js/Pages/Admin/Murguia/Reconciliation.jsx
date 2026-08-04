import { router } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import ProviderUploadPanel, {
	ReconciliationSummary,
	ReconciliationIssueFilter,
} from "@/Components/Admin/Murguia/ProviderUploadPanel";
import ReconciliationTable from "@/Components/Admin/Murguia/ReconciliationTable";

export default function Reconciliation({
	preview,
	issues,
	issueFilter,
	issueTypes,
	successMessage,
}) {
	const clearPreview = () => {
		if (confirm("¿Eliminar el preview de conciliación actual?")) {
			router.delete(route("admin.murguia-reconciliation.clear"));
		}
	};

	return (
		<AdminLayout title="Murguía — conciliación proveedor">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div>
						<Heading>Conciliación contra archivo proveedor</Heading>
						<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
							Compara el archivo Murguía/Odessa con la BD local. Flujo separado
							del upload operativo (altas/bajas).
						</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button href={route("admin.murguia-dashboard.index")} outline>
							Dashboard
						</Button>
						<Button href={route("admin.murguia-reports.index")} outline>
							Reportes
						</Button>
						<Button href={route("admin.murguia.upload")} outline>
							Upload operativo
						</Button>
					</div>
				</div>

				{successMessage && (
					<div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
						{successMessage}
					</div>
				)}

				<ProviderUploadPanel />

				{preview?.error && (
					<div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
						{preview.error}
					</div>
				)}

				{preview?.summary && (
					<>
						<div className="flex flex-wrap items-center justify-between gap-4">
							<Text className="text-sm font-medium">Resumen de conciliación</Text>
							<Button type="button" outline onClick={clearPreview}>
								Limpiar preview
							</Button>
						</div>

						<ReconciliationSummary
							summary={preview.summary}
							meta={preview.meta}
						/>

						<ReconciliationIssueFilter
							issueTypes={issueTypes}
							issueFilter={issueFilter}
						/>

						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Mostrando {issues.from ?? 0}–{issues.to ?? 0} de{" "}
							{issues.total?.toLocaleString("es-MX")} diferencias
						</Text>

						<ReconciliationTable issues={issues} />
					</>
				)}
			</div>
		</AdminLayout>
	);
}
