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
								? "border-famedic-500 bg-famedic-50 text-famedic-800 shadow-sm dark:border-famedic-300 dark:bg-famedic-700 dark:text-white"
								: "border-zinc-200 text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
						}`}
					>
						{tab.label}
					</button>
				);
			})}
		</nav>
	);
}
