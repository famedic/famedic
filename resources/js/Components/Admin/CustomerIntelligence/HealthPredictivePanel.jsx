import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import clsx from "clsx";
import { TONE_CLASSES } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

const LABELS = {
	purchase: "Prob. compra",
	churn: "Prob. abandono",
	email_response: "Responder email",
	whatsapp_response: "Responder WhatsApp",
	membership: "Comprar membresía",
	laboratory: "Comprar laboratorio",
	pharmacy: "Comprar farmacia",
};

export default function HealthPredictivePanel({ averages = {}, recommendations = [] }) {
	return (
		<section className="space-y-4">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					IA predictiva
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					Promedios del cohort y recomendaciones accionables.
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{Object.entries(LABELS).map(([key, label]) => (
					<div
						key={key}
						className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
					>
						<p className="text-[11px] uppercase tracking-wide text-zinc-400">{label}</p>
						<p className="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
							{averages[key] != null ? `${averages[key]}%` : "—"}
						</p>
					</div>
				))}
			</div>
			<div className="grid gap-3 sm:grid-cols-2">
				{recommendations.map((card) => {
					const tone = TONE_CLASSES[card.tone] || TONE_CLASSES.blue;
					return (
						<div
							key={card.id}
							className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
						>
							<div className="flex items-center gap-2">
								<span className={clsx("size-2.5 rounded-full", tone.bar)} />
								<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									{card.title}
								</p>
							</div>
							<p className="mt-2 text-xs leading-relaxed text-zinc-500">{card.detail}</p>
						</div>
					);
				})}
			</div>
		</section>
	);
}
