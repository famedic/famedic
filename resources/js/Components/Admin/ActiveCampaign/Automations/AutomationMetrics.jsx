import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	proximamente: { label: "Próximamente", color: "zinc" },
	no_disponible: { label: "No disponible", color: "zinc" },
};

export default function AutomationMetrics({ metrics = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Resumen operativo
				</h2>
				<p className="text-xs text-zinc-500">
					Señales reales de config/scheduler/dispatches — sin inventar
					telemetría.
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
				{metrics.map((card) => {
					const truth = TRUTH[card.truth] || TRUTH.no_disponible;
					return (
						<div key={card.id} className="space-y-2">
							<BillingMetricCard
								label={card.label}
								value={card.value}
								hint={card.hint}
								tone={card.tone || "default"}
							/>
							<Badge color={truth.color}>{truth.label}</Badge>
						</div>
					);
				})}
			</div>
		</section>
	);
}

export function AutomationNav({ active = "dashboard", links = {} }) {
	return (
		<div className="flex flex-wrap gap-2">
			<Button
				href={links.dashboard || route("admin.activecampaign.automations")}
				outline={active !== "dashboard"}
			>
				Dashboard
			</Button>
			<Button
				href={links.list || route("admin.activecampaign.automations.list")}
				outline={active !== "list"}
			>
				Listado
			</Button>
			<Button
				href={links.builder || route("admin.activecampaign.automations.builder")}
				outline={active !== "builder"}
			>
				Builder
			</Button>
		</div>
	);
}

export function StatusBadge({ status, label }) {
	const color =
		status === "active"
			? "emerald"
			: status === "paused"
				? "amber"
				: "zinc";
	return <Badge color={color}>{label || status}</Badge>;
}
