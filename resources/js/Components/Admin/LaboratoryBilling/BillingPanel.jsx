import clsx from "clsx";
import { billingPanelClass } from "./billingUi";

export default function BillingPanel({
	as: Component = "section",
	className,
	children,
	...props
}) {
	return (
		<Component className={clsx(billingPanelClass, className)} {...props}>
			{children}
		</Component>
	);
}
