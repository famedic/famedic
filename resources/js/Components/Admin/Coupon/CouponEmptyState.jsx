import { Button } from "@/Components/Catalyst/button";

export default function CouponEmptyState({
	title,
	description,
	action,
	actionLabel,
	actionHref,
	onAction,
	icon: Icon,
}) {
	return (
		<div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 bg-zinc-50/50 px-6 py-14 text-center dark:border-zinc-600 dark:bg-zinc-900/40">
			{Icon ? (
				<Icon className="mb-4 size-10 text-zinc-400 dark:text-zinc-500" aria-hidden />
			) : null}
			<h3 className="font-poppins text-base font-semibold text-zinc-900 dark:text-white">
				{title}
			</h3>
			{description ? (
				<p className="mt-2 max-w-md text-sm text-zinc-600 dark:text-zinc-400">
					{description}
				</p>
			) : null}
			{action ? (
				<div className="mt-5">{action}</div>
			) : actionLabel && (actionHref || onAction) ? (
				<div className="mt-5">
					{actionHref ? (
						<Button href={actionHref}>{actionLabel}</Button>
					) : (
						<Button type="button" onClick={onAction}>
							{actionLabel}
						</Button>
					)}
				</div>
			) : null}
		</div>
	);
}
