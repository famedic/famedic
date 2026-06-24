import clsx from "clsx";

export default function QuickPrompts({
	prompts,
	onSelect,
	disabled = false,
	compact = false,
}) {
	return (
		<div
			className={clsx(
				"flex flex-wrap gap-2",
				compact
					? "border-t border-zinc-200/80 bg-zinc-50/80 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/40 sm:px-6"
					: "justify-center",
			)}
		>
			{prompts.map((prompt) => (
				<button
					key={prompt.label}
					type="button"
					disabled={disabled}
					onClick={() => onSelect(prompt.question)}
					className={clsx(
						"rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-700 shadow-sm transition",
						"hover:border-famedic-light/40 hover:bg-famedic-light/5 hover:text-famedic-dark",
						"disabled:cursor-not-allowed disabled:opacity-50",
						"dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-famedic-light/30 dark:hover:bg-famedic-light/10",
						compact && "text-xs",
					)}
				>
					{prompt.label}
				</button>
			))}
		</div>
	);
}
