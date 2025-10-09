import clsx from "clsx";
import Card from "@/Components/Card";

export default function SettingsCard({
	className,
	children,
	actions,
	as = "li",
}) {
	return (
		<Card
			as={as}
			className={clsx(
				className,
				"flex w-full max-w-xs flex-col justify-between p-4",
			)}
		>
			<div>{children}</div>
			{actions && (
				<div className="mt-4 flex justify-end gap-4">{actions}</div>
			)}
		</Card>
	);
}
