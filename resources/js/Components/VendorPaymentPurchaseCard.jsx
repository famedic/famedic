import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Checkbox } from "@/Components/Catalyst/checkbox";
import { QrCodeIcon, InformationCircleIcon } from "@heroicons/react/20/solid";
import Card from "./Card";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";

export default function VendorPaymentPurchaseCard({
	purchase,
	isSelected,
	onToggle,
}) {
	const orderIdField = purchase.gda_order_id
		? "gda_order_id"
		: "vitau_order_id";
	const orderId = purchase[orderIdField];

	return (
		<Card
			hoverable
			className="flex flex-col gap-4 p-6 sm:flex-row sm:items-center"
			onClick={onToggle}
		>
			<div className="flex items-center">
				<Checkbox checked={isSelected} onChange={onToggle} />
			</div>

			<div className="flex min-w-0 flex-1 flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
				<div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center">
					<div
						className={`flex flex-col gap-2 ${purchase.brand ? "items-center" : "items-start"}`}
					>
						{purchase.brand && (
							<LaboratoryBrandCard
								src={`/images/gda/GDA-${purchase.brand.toUpperCase()}.png`}
								className="size-12"
							/>
						)}
						<Badge color="sky">
							<QrCodeIcon className="size-4" />
							{orderId}
						</Badge>
					</div>

					<div className="flex flex-col gap-2">
						<div className="flex flex-wrap items-baseline gap-2">
							<Text>
								<Strong className="text-lg">
									{purchase.formatted_total}
								</Strong>
							</Text>
							{purchase.formatted_commission !== "$0.00 MXN" && (
								<Text className="text-xs text-slate-500 dark:text-slate-600">
									- {purchase.formatted_commission} comisión
								</Text>
							)}
						</div>
						<div className="flex flex-wrap items-center gap-2">
							<Text className="text-sm text-slate-500 dark:text-slate-600">
								{purchase.formatted_created_at}
							</Text>
							{purchase.transactions?.[0] && (
								<PaymentMethodBadge
									transaction={purchase.transactions[0]}
								/>
							)}
						</div>
					</div>
				</div>

				{purchase.vendor_payments &&
					purchase.vendor_payments.length > 0 && (
						<Badge color="slate" className="w-fit text-xs">
							<InformationCircleIcon className="size-4" />
							Tiene pagos anteriores
						</Badge>
					)}
			</div>
		</Card>
	);
}
