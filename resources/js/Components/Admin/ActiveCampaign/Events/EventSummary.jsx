import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import { Badge } from "@/Components/Catalyst/badge";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	proxy: { label: "Proxy", color: "amber" },
	no_disponible: { label: "No disponible", color: "zinc" },
};

const TONE = {
	sky: "sky",
	red: "red",
	amber: "amber",
	zinc: "zinc",
	default: "default",
};

export default function EventSummary({ summary = [] }) {
	return (
		<section className="space-y-3">
			<div className="flex flex-wrap items-end justify-between gap-2">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Resumen
					</h2>
					<p className="text-xs text-zinc-500">
						Señales del periodo y del día — sin inventar agregaciones.
					</p>
				</div>
			</div>

			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
				{summary.map((card) => {
					const truth = TRUTH[card.truth] || TRUTH.no_disponible;
					return (
						<div key={card.id} className="space-y-2">
							<BillingMetricCard
								label={card.label}
								value={card.value}
								hint={card.hint}
								tone={TONE[card.tone] || "default"}
							/>
							<Badge color={truth.color}>{truth.label}</Badge>
						</div>
					);
				})}
			</div>

			<ChartCard
				title="Fuente de verdad"
				description="Dashboard + dispatches locales + Timeline por paciente"
				className="mt-1"
			>
				<p className="text-xs text-zinc-500">
					Resultados y Facturas globales aparecen como No disponible hasta
					existir agregación en Dashboard. El detalle por evento sí usa las
					mismas fuentes del Timeline.
				</p>
			</ChartCard>
		</section>
	);
}
