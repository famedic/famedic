import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import clsx from "clsx";

const LEVEL = {
	green: {
		ring: "ring-emerald-200 dark:ring-emerald-900",
		bg: "from-emerald-50 to-white dark:from-emerald-950/40 dark:to-zinc-900",
		badge: "emerald",
	},
	yellow: {
		ring: "ring-amber-200 dark:ring-amber-900",
		bg: "from-amber-50 to-white dark:from-amber-950/40 dark:to-zinc-900",
		badge: "amber",
	},
	red: {
		ring: "ring-rose-200 dark:ring-rose-900",
		bg: "from-rose-50 to-white dark:from-rose-950/40 dark:to-zinc-900",
		badge: "red",
	},
};

export default function HealthOverview({ overview }) {
	if (!overview) return null;
	const tone = LEVEL[overview.level] || LEVEL.yellow;

	return (
		<section
			className={clsx(
				"rounded-2xl border border-zinc-200 bg-gradient-to-br p-5 shadow-sm ring-1 dark:border-zinc-700",
				tone.bg,
				tone.ring,
			)}
		>
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div className="space-y-2">
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Estado general
					</p>
					<div className="flex flex-wrap items-center gap-3">
						<span className="text-3xl" aria-hidden="true">
							{overview.emoji}
						</span>
						<h2 className="font-poppins text-2xl font-semibold tracking-tight text-zinc-950 dark:text-white">
							{overview.label}
						</h2>
						<Badge color={tone.badge}>{overview.level}</Badge>
					</div>
					<Text className="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
						{overview.detail}
					</Text>
				</div>
			</div>

			<div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{(overview.signals || []).map((signal) => (
					<div
						key={signal.label}
						className="rounded-xl border border-zinc-200/80 bg-white/80 px-3 py-3 dark:border-zinc-700 dark:bg-zinc-950/40"
					>
						<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
							{signal.label}
						</p>
						<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							{signal.value}
						</p>
					</div>
				))}
			</div>
		</section>
	);
}
