import CouponMetricCard from "@/Components/Admin/Coupon/CouponMetricCard";
import { Text } from "@/Components/Catalyst/text";

export default function CouponBulkImportSummary({ summary, isLargeFile = false }) {
	return (
		<div className="space-y-3">
			{isLargeFile ? (
				<div className="rounded-lg border border-sky-200 bg-sky-50/80 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">
					<p className="font-medium">Archivo grande detectado.</p>
					<p className="mt-1 text-sky-800 dark:text-sky-200">
						Para mejorar la experiencia, mostramos un resumen y una vista filtrable de los
						registros. Usa los filtros para revisar casos específicos.
					</p>
				</div>
			) : null}

			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-4">
				<CouponMetricCard label="Total detectados" value={summary.total} tone="default" />
				<CouponMetricCard
					label="Seleccionados"
					value={summary.selected}
					tone="lime"
					hint="Listos para continuar al resumen"
				/>
				<CouponMetricCard label="Registrados" value={summary.registered} tone="lime" />
				<CouponMetricCard label="Pendientes de registro" value={summary.pending} tone="amber" />
				<CouponMetricCard label="Inválidos" value={summary.invalid} tone="red" />
				<CouponMetricCard label="Duplicados" value={summary.duplicate} tone="zinc" />
				<CouponMetricCard label="Omitidos" value={summary.skipped} tone="zinc" />
			</div>

			<Text className="text-xs text-zinc-500 dark:text-zinc-400">
				<strong>Pendiente de registro:</strong> se puede guardar aunque aún no tenga cuenta.{" "}
				<strong>Inválido:</strong> no podrá asignarse hasta corregirse.{" "}
				<strong>Duplicado:</strong> no se recomienda asignarlo de nuevo.
			</Text>
		</div>
	);
}
