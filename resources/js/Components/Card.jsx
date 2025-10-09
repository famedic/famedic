import { Link } from "@inertiajs/react";
import clsx from "clsx";

export function cardClasses(className, hoverable) {
	return clsx(
		"relative rounded-lg bg-white outline-famedic-darker dark:outline-white shadow ring-1 ring-slate-200 dark:bg-slate-900  dark:ring-slate-800 cursor-default",
		hoverable &&
			"hover:sm:outline outline-[1.5px] outline-offset-[6px] outline-famedic-darker dark:outline-white active:bg-zinc-100 dark:active:bg-slate-800",
		className,
	);
}

export default function Card({
	children,
	className,
	hoverable,
	as: Component = "div",
	...props
}) {
	const classes = cardClasses(className, hoverable);

	return "href" in props ? (
		<Link className={classes} {...props}>
			{children}
		</Link>
	) : (
		<Component className={classes} {...props}>
			{children}
		</Component>
	);
}
