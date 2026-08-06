import clsx from "clsx";
import { Text } from "@/Components/Catalyst/text";

const TONE = {
	green: "text-emerald-700 dark:text-emerald-400",
	red: "text-rose-700 dark:text-rose-400",
	orange: "text-amber-700 dark:text-amber-400",
	blue: "text-sky-700 dark:text-sky-400",
	slate: "text-zinc-900 dark:text-zinc-50",
};

export default function AutomationKpiGrid({
	kpis = [],
	columnsClassName = "grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4",
}) {
	return (
		<div className={columnsClassName}>
			{kpis.map((kpi) => (
				<div
					key={kpi.id}
					className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
						{kpi.label}
					</p>
					<p
						className={clsx(
							"mt-1 text-2xl font-semibold tabular-nums tracking-tight",
							TONE[kpi.tone] || TONE.slate,
						)}
					>
						{kpi.value == null || kpi.value === "" ? "—" : kpi.value}
					</p>
					{kpi.hint ? (
						<Text className="mt-1 text-[11px] leading-snug text-zinc-400">
							{kpi.hint}
						</Text>
					) : null}
				</div>
			))}
		</div>
	);
}
