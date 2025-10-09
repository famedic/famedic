import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import { TableCell, TableRow } from "@/Components/Catalyst/table";
import {
	CheckCircleIcon,
	CheckIcon,
	ClockIcon,
	NoSymbolIcon,
	PhoneIcon,
	XCircleIcon,
	CommandLineIcon,
} from "@heroicons/react/16/solid";
import { QrCodeIcon } from "@heroicons/react/20/solid";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";

export default function OnlinePharmacyPurchaseTableRow({
	onlinePharmacyPurchase,
}) {
	return (
		<TableRow
			key={onlinePharmacyPurchase.id}
			href={route(
				"admin.online-pharmacy-purchases.show",
				onlinePharmacyPurchase.id,
			)}
			title={`Pedido #${onlinePharmacyPurchase.vitau_order_id}`}
		>
			<TableCell>
				<div className="text-zinc-500 dark:text-slate-600">
					<div className="flex gap-3">
						<div>
							{onlinePharmacyPurchase.deleted_at ? (
								<XCircleIcon className="size-5 rounded-full fill-red-700 outline outline-1 outline-offset-1 outline-zinc-300 dark:fill-red-400 dark:outline-zinc-500" />
							) : (
								<CheckCircleIcon className="size-5 rounded-full fill-green-700 outline outline-1 outline-offset-1 outline-zinc-300 dark:fill-green-200 dark:outline-zinc-500" />
							)}
						</div>
						<div>
							<Badge color="sky">
								<QrCodeIcon className="size-4" />
								{onlinePharmacyPurchase.vitau_order_id}
							</Badge>

							<br />
							{onlinePharmacyPurchase.formatted_created_at}
							<Text>
								{
									onlinePharmacyPurchase
										.online_pharmacy_purchase_items.length
								}{" "}
								producto
								{onlinePharmacyPurchase
									.online_pharmacy_purchase_items.length > 1
									? "s"
									: ""}
							</Text>
							<span className="flex items-center gap-2">
								<Text>
									<Strong>
										{onlinePharmacyPurchase.formatted_total}
									</Strong>
								</Text>
								{onlinePharmacyPurchase.transactions &&
									onlinePharmacyPurchase.transactions.length >
										0 && (
										<PaymentMethodBadge
											transaction={
												onlinePharmacyPurchase
													.transactions[0]
											}
										/>
									)}
							</span>
						</div>
					</div>
				</div>
			</TableCell>

			<TableCell className="text-zinc-500 dark:text-slate-600">
				<Text>
					<Strong>{onlinePharmacyPurchase.full_name}</Strong>
				</Text>
				<div className="flex items-center gap-1">
					<PhoneIcon className="size-4 fill-zinc-400 dark:fill-slate-600" />
					{onlinePharmacyPurchase.phone}
				</div>
			</TableCell>

			<TableCell className="text-right">
				<div className="flex items-center justify-end gap-1">
					Factura
					{onlinePharmacyPurchase.invoice ? (
						<CheckIcon className="size-4 fill-famedic-light" />
					) : onlinePharmacyPurchase.invoice_request ? (
						<ClockIcon className="size-4 fill-famedic-light" />
					) : (
						<NoSymbolIcon className="size-4 fill-slate-500" />
					)}
				</div>
				<div className="flex items-center justify-end gap-1">
					Pago a proveedor
					{onlinePharmacyPurchase.vendor_payments &&
					onlinePharmacyPurchase.vendor_payments.length ? (
						<CheckIcon className="size-4 fill-famedic-light" />
					) : (
						<NoSymbolIcon className="size-4 fill-slate-500" />
					)}
				</div>
				{onlinePharmacyPurchase.dev_assistance_requests.length > 0 && (
					<div className="flex items-center justify-end gap-1">
						Asistencia técnica
						{onlinePharmacyPurchase.dev_assistance_requests.some(
							(r) => !r.resolved_at,
						) ? (
							<CommandLineIcon className="size-4 animate-pulse fill-famedic-light" />
						) : (
							<Badge color="slate" className="text-xs">
								{
									onlinePharmacyPurchase
										.dev_assistance_requests.length
								}
							</Badge>
						)}
					</div>
				)}
			</TableCell>
		</TableRow>
	);
}
