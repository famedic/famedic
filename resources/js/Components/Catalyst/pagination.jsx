import clsx from "clsx";
import { Button } from "./button";
import { ArrowLeftIcon, ArrowRightIcon } from "@heroicons/react/16/solid";

export function Pagination({
	"aria-label": ariaLabel = "Page navigation",
	className,
	...props
}) {
	return (
		<nav
			aria-label={ariaLabel}
			{...props}
			className={clsx(className, "flex gap-x-2")}
		/>
	);
}

export function PaginationPrevious({
	href = null,
	className,
	children = "Anterior",
}) {
	return (
		<span className={clsx(className, "grow basis-0")}>
			<Button
				{...(href === null
					? { disabled: true, plain: true }
					: { href: href, outline: true })}
				aria-label="Previous page"
			>
				<ArrowLeftIcon />
				{children}
			</Button>
		</span>
	);
}

export function PaginationNext({
	href = null,
	className,
	children = "Siguiente",
}) {
	return (
		<span className={clsx(className, "flex grow basis-0 justify-end")}>
			<Button
				{...(href === null
					? { disabled: true, plain: true }
					: { href: href, outline: true })}
				color="famedic"
				aria-label="Next page"
			>
				{children}
				<ArrowRightIcon />
			</Button>
		</span>
	);
}

export function PaginationList({ className, ...props }) {
	return (
		<span
			{...props}
			className={clsx(className, "hidden items-baseline gap-x-2 lg:flex")}
		/>
	);
}

export function PaginationPage({ href, className, current = false, children }) {
	return (
		<Button
			href={href}
			plain={!current}
			outline={current}
			aria-label={`Page ${children}`}
			aria-current={current ? "page" : undefined}
			className={clsx(
				className,
				"min-w-[2.25rem] before:absolute before:-inset-px before:rounded-lg",
			)}
		>
			<span className="-mx-0.5">{children}</span>
		</Button>
	);
}

export function PaginationGap({
	className,
	children = <>&hellip;</>,
	...props
}) {
	return (
		<span
			aria-hidden="true"
			{...props}
			className={clsx(
				className,
				"w-[2.25rem] select-none text-center text-sm/6 font-semibold text-slate-950 dark:text-white",
			)}
		>
			{children}
		</span>
	);
}
