import clsx from "clsx";

export function Divider({ soft = false, className, ...props }) {
	return (
		<hr
			role="presentation"
			{...props}
			className={clsx(
				className,
				"w-full border-t",
				soft && "border-zinc-950/5 dark:border-slate-800/30",
				!soft && "border-zinc-950/10 dark:border-slate-800",
			)}
		/>
	);
}
