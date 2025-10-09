export default function CheckoutSelectionCard({
	heading,
	IconComponent,
	children,
	className,
	greenIcon = false,
	...props
}) {
	return (
		<Card
			hoverable
			className={clsx(
				"flex h-full w-full flex-col p-4 hover:ring-1 md:aspect-[16/9]",
				className,
			)}
			{...props}
		>
			<div className="pointer-events-none h-full w-full">
				<div className="flex h-full items-start gap-2">
					{IconComponent && (
						<IconComponent
							className={clsx(
								"mt-1.5 size-4 flex-shrink-0 sm:mt-1",
								greenIcon
									? "fill-green-500"
									: "fill-zinc-300 dark:fill-slate-600",
							)}
						/>
					)}
					<div className="h-full w-full">
						{heading && (
							<Subheading className="mb-2 line-clamp-1">
								{heading}
							</Subheading>
						)}
						{children}
					</div>
				</div>
			</div>
		</Card>
	);
}

import { Subheading } from "@/Components/Catalyst/heading";
import clsx from "clsx";
import Card from "@/Components/Card";
