import { clsx } from "clsx";

export function Container({ className, children }) {
	return (
		<div className={clsx(className, "mx-auto max-w-7xl")}>{children}</div>
	);
}
