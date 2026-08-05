import clsx from "clsx";

const VARIANTS = {
	beta: {
		className:
			"border-[#C7D2FE] bg-[#EEF2FF] text-[#4338CA] dark:border-indigo-400/30 dark:bg-indigo-500/10 dark:text-indigo-300",
		label: "BETA",
	},
	comingSoon: {
		className:
			"border-zinc-200 bg-zinc-50 text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400",
		label: "COMING SOON",
	},
	new: {
		className:
			"border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-300",
		label: "NEW",
	},
	ai: {
		className:
			"border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-400/30 dark:bg-violet-500/10 dark:text-violet-300",
		label: "AI",
	},
};

/**
 * Badge elegante para navegación y módulos.
 * @param {'beta'|'comingSoon'|'new'|'ai'} variant
 */
export default function SidebarBadge({
	variant = "beta",
	children,
	className,
}) {
	const config = VARIANTS[variant] || VARIANTS.beta;

	return (
		<span
			className={clsx(
				"ml-auto inline-flex shrink-0 items-center rounded-full border px-2 py-1 text-[10px] font-semibold uppercase tracking-wide",
				config.className,
				className,
			)}
		>
			{children || config.label}
		</span>
	);
}

export { VARIANTS as SIDEBAR_BADGE_VARIANTS };
