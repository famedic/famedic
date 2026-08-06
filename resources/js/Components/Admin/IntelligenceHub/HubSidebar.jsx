/**
 * Navegación horizontal interna de una Suite (tabs / pills).
 * Sustituye al sidebar para módulos de inteligencia.
 */
export default function HubSidebar({ modules = [], activeId }) {
	return (
		<nav className="flex gap-1 overflow-x-auto rounded-2xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/60">
			{modules.map((module) => {
				const isActive = activeId === module.id;
				const disabled = module.status === "coming_soon" || !module.href;

				if (disabled) {
					return (
						<span
							key={module.id}
							className="shrink-0 cursor-not-allowed rounded-xl px-3 py-2 text-xs font-medium text-zinc-400"
							title="Próximamente"
						>
							{module.title}
						</span>
					);
				}

				return (
					<a
						key={module.id}
						href={module.href}
						className={`shrink-0 rounded-xl px-3 py-2 text-xs font-semibold transition ${
							isActive
								? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white"
								: "text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
						}`}
					>
						{module.title}
					</a>
				);
			})}
		</nav>
	);
}
