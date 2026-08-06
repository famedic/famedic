import { Badge } from "@/Components/Catalyst/badge";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	proximamente: { label: "Próximamente", color: "zinc" },
};

export default function HealthIntegrations({ integrations = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Integraciones
				</h2>
				<p className="text-xs text-zinc-500">
					ActiveCampaign con datos reales; el resto queda preparado como
					Próximamente.
				</p>
			</div>

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
				{integrations.map((card) => {
					const truth = TRUTH[card.truth] || TRUTH.proximamente;
					return (
						<div key={card.id} className="relative">
							<div className="absolute right-3 top-3 z-10">
								<Badge
									color={truth.color}
									className="!text-[10px] uppercase"
								>
									{truth.label}
								</Badge>
							</div>
							<ChartCard title={card.label} description={card.hint}>
								<div className="space-y-2 pr-16 text-sm">
									<p>
										<span className="text-zinc-400">Estado · </span>
										<span className="font-medium text-zinc-900 dark:text-zinc-50">
											{card.status}
										</span>
									</p>
									<p>
										<span className="text-zinc-400">Última sync · </span>
										{card.last_sync}
									</p>
									<p>
										<span className="text-zinc-400">Errores recientes · </span>
										{card.recent_errors}
									</p>
									<p>
										<span className="text-zinc-400">Latencia · </span>
										{card.latency}
									</p>
								</div>
							</ChartCard>
						</div>
					);
				})}
			</div>
		</section>
	);
}
