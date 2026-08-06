import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";

function Stat({ label, value }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
				{label}
			</p>
			<p className="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
				{value}
			</p>
		</div>
	);
}

export default function JourneyStats({ stats }) {
	if (!stats) {
		return null;
	}

	return (
		<section className="space-y-3">
			<ChartCard
				title="Resumen inferior"
				description="Totales derivados del Timeline (misma fuente de verdad)."
			>
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
					<Stat label="Totales" value={stats.totals} />
					<Stat label="Tiempo entre extremos" value={stats.span_label} />
					<Stat label="Compras" value={stats.purchases} />
					<Stat label="Laboratorios" value={stats.laboratories} />
					<Stat label="Facturas" value={stats.invoices} />
					<Stat label="Membresías" value={stats.memberships} />
					<Stat label="Beneficiarios" value={stats.beneficiaries} />
				</div>
			</ChartCard>
		</section>
	);
}
