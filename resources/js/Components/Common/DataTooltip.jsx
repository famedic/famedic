import clsx from "clsx";

/**
 * Tooltip CSS-only (sin librerías). Hover / focus muestra el panel.
 */
export default function DataTooltip({ children, content, className = "" }) {
	if (!content) {
		return children;
	}

	return (
		<span className={clsx("group/tooltip relative inline-flex max-w-full", className)}>
			{children}
			<span
				role="tooltip"
				className="pointer-events-none absolute bottom-[calc(100%+8px)] left-1/2 z-50 w-64 -translate-x-1/2 scale-95 rounded-xl border border-zinc-200 bg-white p-3 text-left opacity-0 shadow-xl ring-1 ring-zinc-950/5 transition duration-150 group-hover/tooltip:scale-100 group-hover/tooltip:opacity-100 group-focus-within/tooltip:scale-100 group-focus-within/tooltip:opacity-100 dark:border-zinc-700 dark:bg-zinc-900 dark:ring-white/10"
			>
				{typeof content === "string" ? (
					<p className="text-[11px] leading-relaxed text-zinc-600 dark:text-zinc-300">
						{content}
					</p>
				) : (
					content
				)}
				<span className="absolute left-1/2 top-full h-2 w-2 -translate-x-1/2 -translate-y-1/2 rotate-45 border-b border-r border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" />
			</span>
		</span>
	);
}

export function DataTooltipRows({ rows = [] }) {
	return (
		<dl className="space-y-1.5">
			{rows.map((row) => (
				<div key={row.label} className="grid grid-cols-[5.5rem_1fr] gap-2 text-[11px]">
					<dt className="font-medium text-zinc-400">{row.label}</dt>
					<dd className="font-medium text-zinc-800 dark:text-zinc-100">{row.value}</dd>
				</div>
			))}
		</dl>
	);
}
