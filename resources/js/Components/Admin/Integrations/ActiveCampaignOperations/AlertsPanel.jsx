import clsx from "clsx";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

const PRIORITY = {
	info: "border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200",
	warning:
		"border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200",
	critical:
		"border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200",
};

const BADGE = {
	info: "bg-sky-600",
	warning: "bg-amber-600",
	critical: "bg-rose-600",
};

export default function AlertsPanel({ alerts = [], updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Alertas"
				description="Prioridad Info · Warning · Critical"
				provenance={provenanceForSection("alerts")}
				updatedAt={updatedAt}
			/>
			{alerts.length === 0 ? (
				<p className="text-sm text-zinc-500">Sin alertas activas.</p>
			) : (
				<ul className="space-y-2">
					{alerts.map((alert) => (
						<li
							key={alert.key}
							className={clsx(
								"rounded-xl border px-3 py-2.5 transition hover:shadow-sm",
								PRIORITY[alert.priority] || PRIORITY.info,
							)}
						>
							<div className="flex items-start gap-2">
								<span
									className={clsx(
										"mt-0.5 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white",
										BADGE[alert.priority] || BADGE.info,
									)}
								>
									{alert.priority}
								</span>
								<div className="min-w-0">
									<p className="text-sm font-semibold">{alert.title}</p>
									<p className="text-xs opacity-80">{alert.message}</p>
								</div>
							</div>
						</li>
					))}
				</ul>
			)}
		</section>
	);
}
