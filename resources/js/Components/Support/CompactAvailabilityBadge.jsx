import clsx from "clsx";

export default function CompactAvailabilityBadge({ isAvailable, className }) {
	return (
		<span
			className={clsx(
				"inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold",
				isAvailable
					? "bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200/80 dark:bg-emerald-950/40 dark:text-emerald-200 dark:ring-emerald-800/50"
					: "bg-zinc-100 text-zinc-600 ring-1 ring-zinc-200/80 dark:bg-zinc-800/60 dark:text-zinc-400 dark:ring-zinc-700/50",
				className,
			)}
			role="status"
		>
			{isAvailable ? (
				<>
					<span
						className="size-1.5 rounded-full bg-emerald-500 motion-safe:animate-pulse"
						aria-hidden="true"
					/>
					En línea
				</>
			) : (
				<>
					<span
						className="size-1.5 rounded-full bg-zinc-400"
						aria-hidden="true"
					/>
					Fuera de horario
				</>
			)}
		</span>
	);
}
