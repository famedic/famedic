export default function CouponSectionCard({
	title,
	description,
	actions,
	children,
	className = "",
	bodyClassName = "",
}) {
	return (
		<section
			className={[
				"overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900",
				className,
			].join(" ")}
		>
			{(title || description || actions) && (
				<div className="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-200 px-4 py-4 dark:border-zinc-700 sm:px-6">
					<div className="min-w-0">
						{title && (
							<h2 className="font-poppins text-base font-semibold text-zinc-950 dark:text-white">
								{title}
							</h2>
						)}
						{description && (
							<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{description}</p>
						)}
					</div>
					{actions ? <div className="flex shrink-0 flex-wrap gap-2">{actions}</div> : null}
				</div>
			)}
			<div className={["px-4 py-4 sm:px-6", bodyClassName].join(" ")}>{children}</div>
		</section>
	);
}
