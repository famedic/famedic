import clsx from "clsx";
import { Button } from "@/Components/Catalyst/button";
import {
	CheckIcon,
	ChevronRightIcon,
	ShieldCheckIcon,
	BoltIcon,
	BuildingLibraryIcon,
	CpuChipIcon,
	InformationCircleIcon,
	PencilSquareIcon,
	SparklesIcon,
	UserIcon,
	XMarkIcon,
} from "@heroicons/react/24/outline";

const STEPPER_STEPS = [
	{ id: 1, label: "Persona física" },
	{ id: 2, label: "Método" },
	{ id: 3, label: "Revisar y guardar" },
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
	ctaLabel,
	accent = "blue",
	methodBadge = null,
}) {
	const accentStyles = {
		blue: {
			selected:
				"border-blue-500 bg-blue-50/80 ring-1 ring-blue-500/30 dark:border-blue-400 dark:bg-blue-500/10 dark:ring-blue-400/30",
			icon: "bg-blue-500/15 text-blue-600 ring-blue-500/25 dark:text-blue-300",
			check: "bg-blue-600 text-white dark:bg-blue-500",
			cta: "text-blue-700 dark:text-blue-300",
			badge:
				"bg-blue-100 text-blue-700 ring-blue-200 dark:bg-blue-500/15 dark:text-blue-200 dark:ring-blue-400/20",
		},
		emerald: {
			selected:
				"border-emerald-500 bg-emerald-50/80 ring-1 ring-emerald-500/30 dark:border-emerald-400 dark:bg-emerald-500/10 dark:ring-emerald-400/30",
			icon: "bg-emerald-500/15 text-emerald-600 ring-emerald-500/25 dark:text-emerald-300",
			check: "bg-emerald-600 text-white dark:bg-emerald-500",
			cta: "text-emerald-700 dark:text-emerald-300",
			badge:
				"bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-400/20",
		},
	};

	const styles = accentStyles[accent] ?? accentStyles.blue;

	return (
		<button
			type="button"
			onClick={onSelect}
			aria-pressed={selected}
			className={clsx(
				"group relative flex h-full min-h-0 w-full flex-col rounded-xl border p-4 text-left transition-all duration-200 sm:p-5",
				"border-slate-200 bg-white",
				"hover:border-slate-300 hover:bg-slate-50/80",
				"dark:border-slate-700 dark:bg-slate-800/40 dark:hover:border-slate-600 dark:hover:bg-slate-800/70",
				"focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500",
				selected ? styles.selected : "shadow-sm",
			)}
		>
			{selected && (
				<span
					className={clsx(
						"absolute right-3 top-3 flex h-6 w-6 items-center justify-center rounded-full",
						styles.check,
					)}
					aria-hidden
				>
					<CheckIcon className="h-3.5 w-3.5" strokeWidth={3} />
				</span>
			)}

			<div className="flex items-start gap-3 pr-8 sm:gap-3.5">
				<span
					className={clsx(
						"flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 sm:h-11 sm:w-11",
						styles.icon,
					)}
				>
					<Icon className="h-5 w-5" aria-hidden />
				</span>
				<div className="min-w-0 flex-1">
					{methodBadge && (
						<TaxProfileMethodBadge
							label={methodBadge.label}
							icon={methodBadge.icon}
							className={styles.badge}
						/>
					)}
					<h3 className="text-base font-semibold leading-snug text-slate-900 dark:text-white">
						{title}
					</h3>
					<p className="mt-1 text-sm leading-relaxed text-slate-500 dark:text-slate-400">
						{subtitle}
					</p>
				</div>
			</div>

			{features?.length > 0 && (
				<ul className="mt-4 space-y-2 border-t border-slate-200/70 pt-4 dark:border-slate-700/70">
					{features.map((feature) => (
						<li
							key={feature}
							className="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300"
						>
							<span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300">
								<CheckIcon className="h-2.5 w-2.5" strokeWidth={3} aria-hidden />
							</span>
							<span className="min-w-0 leading-snug">{feature}</span>
						</li>
					))}
				</ul>
			)}

			{ctaLabel && (
				<p
					className={clsx(
						"mt-auto pt-4 text-sm font-medium",
						selected ? styles.cta : "text-slate-500 dark:text-slate-400",
					)}
				>
					{ctaLabel}
				</p>
			)}
		</button>
	);
}

function TaxProfileMethodBadge({ label, icon, className }) {
	const Icon = icon === "manual" ? PencilSquareIcon : icon === "chip" ? CpuChipIcon : SparklesIcon;

	return (
		<span
			className={clsx(
				"mb-2 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold leading-none ring-1",
				className,
			)}
		>
			<Icon className="h-3.5 w-3.5" aria-hidden />
			{label}
		</span>
	);
}

export function TaxProfileCompactAlert({ children, tone = "amber" }) {
	const tones = {
		amber: {
			wrap: "border-amber-500/20 bg-amber-500/[0.06] dark:border-amber-500/25 dark:bg-amber-500/[0.08]",
			icon: "bg-amber-500/15 text-amber-600 dark:text-amber-400",
			text: "text-amber-900/90 dark:text-amber-100/90",
		},
		blue: {
			wrap: "border-blue-500/20 bg-blue-500/[0.06] dark:border-blue-500/25 dark:bg-blue-500/[0.08]",
			icon: "bg-blue-500/15 text-blue-600 dark:text-blue-400",
			text: "text-blue-900/90 dark:text-blue-100/90",
		},
		red: {
			wrap: "border-red-500/20 bg-red-500/[0.06] dark:border-red-500/25 dark:bg-red-500/[0.08]",
			icon: "bg-red-500/15 text-red-600 dark:text-red-400",
			text: "text-red-900/90 dark:text-red-100/90",
		},
	};
	const styles = tones[tone] ?? tones.amber;
	const AlertIcon = tone === "blue" ? InformationCircleIcon : ExclamationTriangleInlineIcon;

	return (
		<div
			className={clsx(
				"flex items-start gap-2.5 rounded-lg border px-3 py-2.5 sm:px-4 sm:py-3",
				styles.wrap,
			)}
			role="note"
		>
			<span
				className={clsx(
					"mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md",
					styles.icon,
				)}
			>
				<AlertIcon className="h-3.5 w-3.5" aria-hidden />
			</span>
			<div className={clsx("text-xs leading-relaxed sm:text-sm", styles.text)}>
				{children}
			</div>
		</div>
	);
}

function ExclamationTriangleInlineIcon({ className, ...props }) {
	return (
		<svg
			className={className}
			fill="none"
			viewBox="0 0 24 24"
			strokeWidth={2}
			stroke="currentColor"
			{...props}
		>
			<path
				strokeLinecap="round"
				strokeLinejoin="round"
				d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
			/>
		</svg>
	);
}

export function TaxProfilePhysicalPersonNotice() {
	return (
		<div
			className="flex items-start gap-3 rounded-lg border border-blue-100 bg-blue-50/70 px-4 py-3 text-blue-900 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-100"
			role="note"
		>
			<span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-300">
				<CheckIcon className="h-3.5 w-3.5" strokeWidth={3} aria-hidden />
			</span>
			<p className="text-sm leading-relaxed">
				Famedic puede facturar perfiles fiscales registrados como persona física.
			</p>
		</div>
	);
}

export function TaxProfilePersonTypeCard({
	selected,
	onSelect,
	title,
	subtitle,
	tone = "blue",
}) {
	const toneStyles = {
		blue: {
			selected:
				"border-blue-500 bg-blue-50/80 ring-1 ring-blue-500/30 dark:border-blue-400 dark:bg-blue-500/10 dark:ring-blue-400/30",
			icon: "bg-blue-500/15 text-blue-600 ring-blue-500/25 dark:text-blue-300",
			check: "bg-blue-600 text-white dark:bg-blue-500",
		},
		slate: {
			selected:
				"border-slate-400 bg-slate-50 ring-1 ring-slate-400/30 dark:border-slate-500 dark:bg-slate-800/70 dark:ring-slate-500/30",
			icon: "bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:ring-slate-600",
			check: "bg-slate-700 text-white dark:bg-slate-500",
		},
	};
	const styles = toneStyles[tone] ?? toneStyles.blue;

	return (
		<button
			type="button"
			onClick={onSelect}
			aria-pressed={selected}
			className={clsx(
				"group relative flex h-full min-h-[7.5rem] w-full items-start gap-3 rounded-xl border p-4 text-left transition-all duration-200 sm:p-5",
				"border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/80",
				"dark:border-slate-700 dark:bg-slate-800/40 dark:hover:border-slate-600 dark:hover:bg-slate-800/70",
				"focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500",
				selected ? styles.selected : "shadow-sm",
			)}
		>
			<span
				className={clsx(
					"flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1",
					styles.icon,
				)}
			>
				<UserIcon className="h-5 w-5" aria-hidden />
			</span>
			<span className="min-w-0 flex-1">
				<span className="block text-base font-semibold leading-snug text-slate-900 dark:text-white">
					{title}
				</span>
				<span className="mt-1 block text-sm leading-relaxed text-slate-500 dark:text-slate-400">
					{subtitle}
				</span>
			</span>
			{selected && (
				<span
					className={clsx(
						"absolute right-3 top-3 flex h-6 w-6 items-center justify-center rounded-full",
						styles.check,
					)}
					aria-hidden
				>
					<CheckIcon className="h-3.5 w-3.5" strokeWidth={3} />
				</span>
			)}
		</button>
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
		title: "Proceso guiado",
		description: "Revisa antes de guardar",
	},
	{
		icon: BuildingLibraryIcon,
		title: "Solo personas físicas",
		description: "Facturación a tu nombre",
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
