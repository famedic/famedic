import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import { InformationCircleIcon } from "@heroicons/react/20/solid";
import { ChevronDoubleRightIcon } from "@heroicons/react/16/solid";
import { MapPinIcon } from "@heroicons/react/20/solid";
import { preferredStoreSummaryPresentation } from "@/lib/laboratoryCartPreferredStoreUi";
import { hasDiscountValue } from "@/lib/laboratoryCheckoutOrderSummaryUi";

export default function OrderSummary({
	formattedSubtotal,
	formattedDiscount,
	formattedTotal,
	checkoutUrl,
	onCheckoutClick,
	summaryExtra = null,
	appointmentNotice = null,
	itemsCount = 0,
	preferredStore = null,
}) {
	const showDiscount = hasDiscountValue(formattedDiscount);
	const storeSummary = preferredStoreSummaryPresentation(preferredStore);

	return (
		<Card className="sticky top-6 space-y-5 px-4 py-6 sm:p-6 lg:top-24">
			{appointmentNotice?.title && (
				<div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800">
					<div className="flex gap-3">
						<InformationCircleIcon
							aria-hidden="true"
							className="size-5 shrink-0 text-famedic-light"
						/>
						<div>
							<Subheading>{appointmentNotice.title}</Subheading>
							<Text className="mt-1 text-sm">
								{appointmentNotice.message}
							</Text>
						</div>
					</div>
				</div>
			)}

			<div className="rounded-lg border border-zinc-200 bg-zinc-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/40">
				<Text className="text-sm text-zinc-600 dark:text-slate-400">
					{storeSummary.title}
				</Text>
				<div className="mt-1 flex items-start gap-2">
					{storeSummary.hasSelection && (
						<MapPinIcon className="mt-0.5 size-4 shrink-0 text-famedic-light" />
					)}
					<div>
						<Text className="text-sm font-medium text-zinc-900 dark:text-white">
							{storeSummary.primary}
						</Text>
						<Text className="text-sm text-zinc-600 dark:text-slate-400">
							{storeSummary.secondary}
						</Text>
					</div>
				</div>
			</div>

			{summaryExtra}

			<Heading level={2} className="text-lg">
				Resumen
			</Heading>

			<dl className="space-y-0">
				<div className="flex items-center justify-between gap-4 py-3">
					<dt>
						<Text>Subtotal</Text>
					</dt>
					<dd>
						<Text>{formattedSubtotal}</Text>
					</dd>
				</div>
				{showDiscount && (
					<>
						<Divider />
						<div className="flex items-center justify-between gap-4 py-3">
							<dt>
								<Text>Descuento</Text>
							</dt>
							<dd>
								<Text>
									{String(formattedDiscount).startsWith("-")
										? formattedDiscount
										: `-${String(formattedDiscount).replace(/^-/, "")}`}
								</Text>
							</dd>
						</div>
					</>
				)}
				<Divider />
				<div className="flex items-center justify-between gap-4 py-4">
					<dt>
						<Subheading className="text-xl">Total</Subheading>
					</dt>
					<dd>
						<Strong className="text-2xl text-famedic-dark dark:text-white">
							{formattedTotal}
						</Strong>
					</dd>
				</div>
			</dl>

			<Button
				href={checkoutUrl}
				onClick={onCheckoutClick}
				className="w-full !py-3"
			>
				<ChevronDoubleRightIcon />
				Continuar
				{process.env.NODE_ENV === "testing" && (
					<span className="ml-2 text-xs opacity-70">
						({itemsCount} items)
					</span>
				)}
			</Button>
		</Card>
	);
}
