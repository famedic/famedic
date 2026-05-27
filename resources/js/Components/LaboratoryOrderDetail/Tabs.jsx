const tabs = [
	{ key: "patient", label: "Paciente" },
	{ key: "instructions", label: "Instrucciones" },
	{ key: "invoice", label: "Facturas" },
];

export default function Tabs({ activeTab, onChange }) {
	return (
		<nav
			className="-mx-0.5 flex min-w-0 gap-2 overflow-x-auto overscroll-x-contain px-0.5 pb-0.5 [-webkit-overflow-scrolling:touch] sm:flex-wrap sm:overflow-x-visible sm:pb-0"
			aria-label="Secciones del pedido"
		>
			{tabs.map((tab) => {
				const isActive = activeTab === tab.key;
				return (
					<button
						key={tab.key}
						type="button"
						onClick={() => onChange(tab.key)}
						aria-current={isActive ? "page" : undefined}
						className={`shrink-0 rounded-full border px-3 py-2 text-sm font-medium whitespace-nowrap transition sm:px-4 ${
							isActive
								? "border-famedic-lime bg-famedic-lime font-semibold text-famedic-dark shadow-md ring-2 ring-famedic-lime/40"
								: "border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-300 dark:hover:border-slate-500 dark:hover:bg-slate-800"
						}`}
					>
						{tab.label}
					</button>
				);
			})}
		</nav>
	);
}
