import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";

const TONE = {
	emerald: "border-emerald-200 bg-emerald-50/80 dark:border-emerald-500/30 dark:bg-emerald-500/10",
	amber: "border-amber-200 bg-amber-50/80 dark:border-amber-500/30 dark:bg-amber-500/10",
	rose: "border-rose-200 bg-rose-50/80 dark:border-rose-500/30 dark:bg-rose-500/10",
};

export default function AutomationHealthCards({ health, roadmap = [] }) {
	const tone = TONE[health?.tone] || TONE.emerald;

	return (
		<div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
			<div className={`rounded-2xl border p-5 ${tone}`}>
				<div className="flex flex-wrap items-center gap-2">
					<p className="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">
						Automation Health
					</p>
					<Badge color={health?.tone === "rose" ? "rose" : health?.tone === "amber" ? "amber" : "lime"}>
						{health?.label || "—"}
					</Badge>
				</div>
				<div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
					<Stat label="Activos" value={health?.drivers_active} />
					<Stat label="Inactivos" value={health?.drivers_inactive} />
					<Stat
						label="En Dispatcher"
						value={health?.registered_order_drivers}
					/>
					<Stat label="Estado" value={health?.status} />
				</div>
			</div>

			<div className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
				<p className="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">
					Roadmap de canales
				</p>
				<div className="mt-3 flex flex-wrap gap-2">
					{roadmap.map((item) => (
						<Badge key={item.key} color="zinc">
							{item.label}
						</Badge>
					))}
				</div>
				<Text className="mt-3 text-xs text-zinc-500">
					Preparados para Email, WhatsApp, Push, Analytics, IA, Journey y
					Health sin tocar Checkout ni Fulfill.
				</Text>
			</div>
		</div>
	);
}

function Stat({ label, value }) {
	return (
		<div>
			<p className="text-[11px] uppercase tracking-wide text-zinc-500">{label}</p>
			<p className="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
				{value ?? "—"}
			</p>
		</div>
	);
}
