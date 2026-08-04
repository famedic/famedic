export const DASHBOARD_COLORS = {
	blue: "#2563eb",
	green: "#059669",
	red: "#dc2626",
	orange: "#ea580c",
	purple: "#7c3aed",
	slate: "#64748b",
};

export const TONE_CLASSES = {
	blue: {
		icon: "bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300",
		bar: "bg-blue-500",
	},
	green: {
		icon: "bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300",
		bar: "bg-emerald-500",
	},
	red: {
		icon: "bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300",
		bar: "bg-rose-500",
	},
	orange: {
		icon: "bg-orange-50 text-orange-600 dark:bg-orange-950/40 dark:text-orange-300",
		bar: "bg-orange-500",
	},
	purple: {
		icon: "bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300",
		bar: "bg-violet-500",
	},
};

export function ChartCard({ title, description, children, className = "" }) {
	return (
		<div
			className={`rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 ${className}`}
		>
			<div className="mb-4 space-y-1">
				<h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					{title}
				</h3>
				{description ? (
					<p className="text-xs text-zinc-500 dark:text-zinc-400">
						{description}
					</p>
				) : null}
			</div>
			{children}
		</div>
	);
}

export const CHART_UI =
	"text-zinc-600 dark:text-zinc-400 [&_.recharts-cartesian-axis-tick-value]:!fill-zinc-600 dark:[&_.recharts-cartesian-axis-tick-value]:!fill-zinc-400";
