import clsx from "clsx";

export default function NewResultBadge({ className = "", compact = false }) {
	return (
		<span
			className={clsx(
				"inline-flex items-center gap-1 rounded-full font-semibold ring-1",
				"bg-emerald-100 text-emerald-900 ring-emerald-200",
				"dark:bg-emerald-950/40 dark:text-emerald-100 dark:ring-emerald-800",
				compact ? "px-2 py-0.5 text-[11px]" : "px-2.5 py-1 text-xs",
				className,
			)}
			title="Hay resultados actualizados desde tu última consulta"
		>
			<span aria-hidden>🟢</span>
			Nuevo resultado
		</span>
	);
}
