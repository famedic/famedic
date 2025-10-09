import clsx from "clsx";
import { Divider } from "./divider";

export function Heading({ className, level = 1, ...props }) {
	let Element = `h${level}`;

	return (
		<Element
			{...props}
			className={clsx(
				className,
				"font-poppins text-2xl/8 font-semibold text-famedic-darker sm:text-xl/8 dark:text-white",
			)}
		/>
	);
}

export function Subheading({ className, level = 2, ...props }) {
	let Element = `h${level}`;

	return (
		<Element
			{...props}
			className={clsx(
				className,
				"font-poppins text-base/7 font-semibold text-famedic-darker dark:text-white",
			)}
		/>
	);
}

export function GradientHeading({ className, level = 1, noDivider, ...props }) {
	let Element = `h${level}`;

	return (
		<>
			<Element
				{...props}
				className={clsx(
					className,
					"bg-gradient-to-br from-famedic-darker to-famedic-darker bg-clip-text font-poppins text-5xl/[3.8rem] font-medium tracking-tight text-transparent lg:text-6xl/[4.5rem] dark:from-white dark:to-white",
				)}
			/>
			{!noDivider && <Divider className="mb-6 mt-4" />}
		</>
	);
}
