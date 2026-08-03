/** Tokens visuales alineados con Monitoreo · Carritos y el admin Catalyst. */

export const billingPanelClass =
	"rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-600/80 dark:bg-zinc-800/90";

export const billingMutedTextClass = "text-sm text-zinc-500 dark:text-zinc-400";

export const billingSecondaryTextClass =
	"text-xs text-zinc-500 dark:text-zinc-400";

export const billingChartUiClass =
	"text-zinc-600 dark:text-zinc-400 [&_.recharts-cartesian-axis-tick-value]:!fill-zinc-600 dark:[&_.recharts-cartesian-axis-tick-value]:!fill-zinc-400 [&_.recharts-legend-item-text]:!text-zinc-700 dark:[&_.recharts-legend-item-text]:!text-zinc-300";

export const billingChartGridClass =
	"[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-600/50 [&_.recharts-cartesian-grid-vertical_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-vertical_>_line]:stroke-zinc-600/50 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-zinc-300";

export const billingValueToneClass = {
	default: "text-zinc-900 dark:text-zinc-50",
	amber: "text-amber-600 dark:text-amber-300",
	red: "text-red-600 dark:text-red-300",
	sky: "text-blue-600 dark:text-sky-300",
	lime: "text-famedic-darker dark:text-famedic-lime",
	zinc: "text-zinc-900 dark:text-zinc-50",
};
