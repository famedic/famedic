import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import clsx from "clsx";

export default function CheckoutInlineTotals({
	details = [],
	title = "Resumen del pago",
	className,
}) {
	if (!details.length) {
		return null;
	}

	return (
		<div
			className={clsx(
				"rounded-lg border border-zinc-200 bg-zinc-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40",
				className,
			)}
		>
			<Subheading className="text-base">{title}</Subheading>
			<dl className="mt-3">
				{details.map((row, index) => {
					const isTotal = index === details.length - 1;

					return (
						<div
							key={row.label}
							className={clsx(
								"flex items-center justify-between gap-3 py-2",
								isTotal &&
									"mt-1 border-t border-zinc-200 pt-3 dark:border-slate-600",
							)}
						>
							<dt className="min-w-0">
								<Text
									className={clsx(
										isTotal && "font-semibold text-zinc-900 dark:text-zinc-100",
									)}
								>
									{row.label}
								</Text>
							</dt>
							<dd className="shrink-0">
								{isTotal ? (
									<Strong className="text-zinc-900 dark:text-zinc-100">
										{row.value}
									</Strong>
								) : (
									<Text>{row.value}</Text>
								)}
							</dd>
						</div>
					);
				})}
			</dl>
		</div>
	);
}
