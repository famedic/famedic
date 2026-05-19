import clsx from "clsx";
import { Button } from "@/Components/Catalyst/button";
import {
	CheckIcon,
	ChevronRightIcon,
	ShieldCheckIcon,
	BoltIcon,
	BuildingLibraryIcon,
	XMarkIcon,
} from "@heroicons/react/24/outline";

const STEPPER_STEPS = [
	{ id: 1, label: "Nuevo perfil" },
	{ id: 2, label: "Revisar datos" },
	{ id: 3, label: "Confirmar" },
];

export function TaxProfileModalCloseButton({ onClose, disabled }) {
	return (
		<button
			type="button"
			onClick={onClose}
			disabled={disabled}
			aria-label="Cerrar"
			className={clsx(
				"absolute right-0 top-0 z-10 flex h-9 w-9 items-center justify-center rounded-lg",
				"text-slate-400 transition-colors duration-200",
				"hover:bg-slate-100 hover:text-slate-600",
				"dark:hover:bg-slate-800 dark:hover:text-slate-200",
				"focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500",
				disabled && "pointer-events-none opacity-40",
			)}
		>
			<XMarkIcon className="h-5 w-5" />
		</button>
	);
}

export function TaxProfileFormStepper({ activeStep }) {
	return (
		<nav aria-label="Progreso del formulario" className="pr-10">
			<ol className="flex items-center gap-2 sm:gap-3">
				{STEPPER_STEPS.map((item, index) => {
					const isActive = activeStep === item.id;
					const isCompleted = activeStep > item.id;
					const isLast = index === STEPPER_STEPS.length - 1;

					return (
						<li key={item.id} className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
							<div className="flex min-w-0 flex-col items-center gap-1.5 sm:flex-row sm:gap-2.5">
								<span
									className={clsx(
										"flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-all duration-300 sm:h-9 sm:w-9",
										isCompleted &&
											"bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30",
										isActive &&
											"bg-blue-500 text-white shadow-[0_0_0_1px_rgba(59,130,246,0.6),0_0_20px_-2px_rgba(59,130,246,0.55)] ring-2 ring-blue-400/50",
										!isActive &&
											!isCompleted &&
											"bg-slate-100 text-slate-400 ring-1 ring-slate-200/80 dark:bg-slate-800 dark:text-slate-500 dark:ring-slate-700",
									)}
								>
									{isCompleted ? (
										<CheckIcon className="h-4 w-4" strokeWidth={2.5} />
									) : (
										item.id
									)}
								</span>
								<span
									className={clsx(
										"truncate text-center text-[11px] font-medium leading-tight sm:text-left sm:text-xs",
										isActive && "text-slate-900 dark:text-white",
										isCompleted && "text-slate-600 dark:text-slate-300",
										!isActive &&
											!isCompleted &&
											"text-slate-400 dark:text-slate-500",
									)}
								>
									{item.label}
								</span>
							</div>
							{!isLast && (
								<div
									className={clsx(
										"hidden h-px min-w-[1rem] flex-1 sm:block",
										isCompleted
											? "bg-blue-500/50"
											: "bg-slate-200 dark:bg-slate-700",
									)}
									aria-hidden
								/>
							)}
						</li>
					);
				})}
			</ol>
		</nav>
	);
}

export function TaxProfilePageHeading({ title, subtitle }) {
	return (
		<div className="space-y-1">
			<h2 className="text-xl font-semibold tracking-tight text-slate-900 dark:text-white sm:text-2xl">
				{title}
			</h2>
			{subtitle && (
				<p className="text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>
			)}
		</div>
	);
}

export function TaxProfileEntryModeCard({
	selected,
	onSelect,
	icon: Icon,
	title,
	subtitle,
	features,
	accent = "blue",
}) {
	const accentStyles = {
		blue: {
			selected:
				"border-blue-500/80 bg-blue-500/[0.06] shadow-[0_0_0_1px_rgba(59,130,246,0.45),0_0_28px_-6px_rgba(59,130,246,0.4)]",
			icon: "bg-blue-500/15 text-blue-400 ring-blue-500/25",
			badge: "bg-blue-500/15 text-blue-300",
		},
		emerald: {
			selected:
				"border-emerald-500/70 bg-emerald-500/[0.06] shadow-[0_0_0_1px_rgba(16,185,129,0.4),0_0_28px_-6px_rgba(16,185,129,0.25)]",
			icon: "bg-emerald-500/15 text-emerald-400 ring-emerald-500/25",
			badge: "bg-emerald-500/15 text-emerald-300",
		},
	};

	const styles = accentStyles[accent] ?? accentStyles.blue;

	return (
		<button
			type="button"
			onClick={onSelect}
			className={clsx(
				"group relative flex w-full flex-col rounded-xl border p-4 text-left transition-all duration-300 sm:p-5",
				"border-slate-200/80 bg-white/50 backdrop-blur-sm",
				"hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg hover:shadow-slate-900/5",
				"dark:border-slate-700/80 dark:bg-slate-800/30 dark:hover:border-slate-600 dark:hover:shadow-black/20",
				"focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500",
				selected && styles.selected,
			)}
		>
			{selected && (
				<span
					className={clsx(
						"absolute right-3 top-3 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide",
						styles.badge,
					)}
				>
					Seleccionado
				</span>
			)}

			<div className="flex items-start gap-3 sm:gap-4">
				<span
					className={clsx(
						"flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1 transition-transform duration-300 group-hover:scale-105",
						styles.icon,
					)}
				>
					<Icon className="h-5 w-5" />
				</span>
				<div className="min-w-0 flex-1 pr-16 sm:pr-20">
					<h3 className="text-base font-semibold text-slate-900 dark:text-white">
						{title}
					</h3>
					<p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
						{subtitle}
					</p>
				</div>
			</div>

			<ul className="mt-4 space-y-2 border-t border-slate-200/60 pt-4 dark:border-slate-700/60">
				{features.map((feature) => (
					<li
						key={feature}
						className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300"
					>
						<span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-500 dark:text-emerald-400">
							<CheckIcon className="h-2.5 w-2.5" strokeWidth={3} />
						</span>
						{feature}
					</li>
				))}
			</ul>
		</button>
	);
}

export function TaxProfileCompactAlert({ children }) {
	return (
		<div
			className={clsx(
				"flex items-start gap-2.5 rounded-lg border px-3 py-2.5 sm:px-4 sm:py-3",
				"border-amber-500/20 bg-amber-500/[0.06]",
				"dark:border-amber-500/25 dark:bg-amber-500/[0.08]",
			)}
			role="note"
		>
			<span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-amber-500/15 text-amber-600 dark:text-amber-400">
				<svg
					className="h-3.5 w-3.5"
					fill="none"
					viewBox="0 0 24 24"
					strokeWidth={2}
					stroke="currentColor"
					aria-hidden
				>
					<path
						strokeLinecap="round"
						strokeLinejoin="round"
						d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
					/>
				</svg>
			</span>
			<p className="text-xs leading-relaxed text-amber-900/90 dark:text-amber-100/90 sm:text-sm">
				{children}
			</p>
		</div>
	);
}

const TRUST_ITEMS = [
	{
		icon: ShieldCheckIcon,
		title: "Datos seguros",
		description: "Tu información está protegida",
	},
	{
		icon: BoltIcon,
		title: "Proceso rápido",
		description: "Completa en minutos",
	},
	{
		icon: BuildingLibraryIcon,
		title: "Verificado por SAT",
		description: "Información 100% válida",
	},
];

export function TaxProfileTrustIndicators() {
	return (
		<div className="grid grid-cols-1 gap-2 border-t border-slate-200/80 pt-5 dark:border-slate-700/80 sm:grid-cols-3 sm:gap-3">
			{TRUST_ITEMS.map(({ icon: Icon, title, description }) => (
				<div
					key={title}
					className={clsx(
						"flex items-center gap-2.5 rounded-lg px-3 py-2.5",
						"bg-slate-50/80 ring-1 ring-slate-200/60",
						"dark:bg-slate-800/40 dark:ring-slate-700/60",
					)}
				>
					<span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-500/10 text-blue-500 dark:text-blue-400">
						<Icon className="h-4 w-4" />
					</span>
					<div className="min-w-0">
						<p className="text-xs font-semibold text-slate-800 dark:text-slate-200">
							{title}
						</p>
						<p className="truncate text-[11px] text-slate-500 dark:text-slate-400">
							{description}
						</p>
					</div>
				</div>
			))}
		</div>
	);
}

export function TaxProfileModalFooter({
	onCancel,
	onContinue,
	cancelLabel = "Cancelar",
	continueLabel = "Continuar",
	continueDisabled = false,
	continueType = "button",
	children,
}) {
	return (
		<div
			className={clsx(
				"mt-6 flex flex-col-reverse gap-3 border-t border-slate-200/80 pt-5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700/80",
			)}
		>
			<Button
				type="button"
				plain
				onClick={onCancel}
				className="!px-4 !py-2.5 text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
			>
				{cancelLabel}
			</Button>
			<div className="flex flex-col gap-2 sm:flex-row sm:items-center">
				{children}
				<Button
					type={continueType}
					onClick={onContinue}
					disabled={continueDisabled}
					className={clsx(
						"!px-6 !py-2.5 font-medium transition-all duration-200",
						"shadow-sm hover:shadow-md hover:shadow-blue-500/20",
						"disabled:opacity-50 disabled:shadow-none",
					)}
				>
					{continueLabel}
					<ChevronRightIcon className="ml-1.5 h-4 w-4" />
				</Button>
			</div>
		</div>
	);
}
