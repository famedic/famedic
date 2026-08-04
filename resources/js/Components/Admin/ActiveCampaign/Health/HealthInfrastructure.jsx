import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import { Badge } from "@/Components/Catalyst/badge";

const TONE = {
	lime: "lime",
	amber: "amber",
	red: "red",
	sky: "sky",
	zinc: "zinc",
	default: "default",
};

export default function HealthInfrastructure({ items = null, loading = false }) {
	return (
		<section className="space-y-3">
			<div className="flex flex-wrap items-end justify-between gap-2">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Infraestructura
					</h2>
					<p className="text-xs text-zinc-500">
						Señales obtenibles desde Laravel (config / DB). Sin estados
						inventados.
					</p>
				</div>
				{loading ? <Badge color="zinc">Cargando…</Badge> : null}
			</div>

			{loading || !items ? (
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-busy="true">
					{Array.from({ length: 8 }).map((_, i) => (
						<div
							key={i}
							className="h-24 animate-pulse rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
						/>
					))}
				</div>
			) : (
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
					{items.map((item) => (
						<BillingMetricCard
							key={item.id}
							label={item.label}
							value={item.value}
							hint={item.detail}
							tone={TONE[item.tone] || "default"}
						/>
					))}
				</div>
			)}
		</section>
	);
}
