import clsx from "clsx";

export function SidebarNavSection({ label, children, rail = false, className }) {
	return (
		<div className={clsx("space-y-1", className)} data-slot="nav-section">
			{label && !rail ? (
				<p className="mb-1.5 mt-5 px-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-zinc-400 first:mt-0 dark:text-zinc-500">
					{label}
				</p>
			) : null}
			{label && rail ? (
				<div
					className="mx-auto my-2 h-px w-6 bg-zinc-200 dark:bg-zinc-800"
					aria-hidden="true"
					title={label}
				/>
			) : null}
			<div className="flex flex-col gap-0.5">{children}</div>
		</div>
	);
}

export function SidebarNavDivider({ className, rail = false }) {
	return (
		<div
			className={clsx(
				rail ? "mx-auto my-2 h-px w-6 bg-zinc-200 dark:bg-zinc-800" : "my-3 border-t border-zinc-200/80 dark:border-zinc-800",
				className,
			)}
			aria-hidden="true"
		/>
	);
}
