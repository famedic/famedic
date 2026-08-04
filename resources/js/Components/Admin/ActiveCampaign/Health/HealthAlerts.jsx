import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import clsx from "clsx";

const SEVERITY = {
	ok: "emerald",
	info: "sky",
	warning: "amber",
	critical: "red",
};

export default function HealthAlerts({ alerts = null, loading = false }) {
	return (
		<section className="space-y-3">
			<div className="flex flex-wrap items-end justify-between gap-2">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Alertas
					</h2>
					<p className="text-xs text-zinc-500">
						Errores, backlog y excepciones recientes con datos existentes.
					</p>
				</div>
				{loading ? <Badge color="zinc">Cargando…</Badge> : null}
			</div>

			{loading || !alerts ? (
				<div className="space-y-2" aria-busy="true">
					{Array.from({ length: 4 }).map((_, i) => (
						<div
							key={i}
							className="h-16 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800"
						/>
					))}
				</div>
			) : (
				<ul className="space-y-2">
					{alerts.map((alert) => (
						<li
							key={alert.id}
							className={clsx(
								"rounded-xl border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900",
							)}
						>
							<div className="flex flex-wrap items-start justify-between gap-2">
								<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									{alert.title}
								</p>
								<Badge color={SEVERITY[alert.severity] || "zinc"}>
									{alert.severity}
								</Badge>
							</div>
							<Text className="mt-1 text-xs text-zinc-500">{alert.detail}</Text>
						</li>
					))}
				</ul>
			)}
		</section>
	);
}
