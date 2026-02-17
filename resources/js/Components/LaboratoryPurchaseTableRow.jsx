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
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";
import EfevooPayBadge from "@/Components/EfevooPayBadge";
import OdessaBadge from "@/Components/OdessaBadge";

export default function LaboratoryPurchaseTableRow({
	laboratoryPurchase,
	showBrand = false,
}) {
	return (
		<TableRow
			key={laboratoryPurchase.id}
			href={route(
				"admin.laboratory-purchases.show",
				laboratoryPurchase.id,
			)}
			title={`Pedido #${laboratoryPurchase.gda_order_id}`}
		>
			<TableCell>
				<div className="text-zinc-500 dark:text-slate-600">
					<div className="flex gap-3">
						<div>
							{laboratoryPurchase.deleted_at ? (
								<XCircleIcon className="size-5 rounded-full fill-red-700 outline outline-1 outline-offset-1 outline-zinc-300 dark:fill-red-400 dark:outline-zinc-500" />
							) : (
								<CheckCircleIcon className="size-5 rounded-full fill-green-700 outline outline-1 outline-offset-1 outline-zinc-300 dark:fill-green-200 dark:outline-zinc-500" />
							)}
						</div>
						<div>
							<Badge color="sky">
								<QrCodeIcon className="size-4" />
								{laboratoryPurchase.gda_order_id}
							</Badge>

							<br />
							{laboratoryPurchase.formatted_created_at}
							<Text>
								{
									laboratoryPurchase.laboratory_purchase_items
										.length
								}{" "}
								estudio
								{laboratoryPurchase.laboratory_purchase_items
									.length > 1
									? "s"
									: ""}
							</Text>
							<span className="flex items-center gap-2">
								<Text>
									<Strong>
										{laboratoryPurchase.formatted_total}
									</Strong>
								</Text>
								{laboratoryPurchase.transactions &&
									laboratoryPurchase.transactions.length >
										0 && (
										<PaymentMethodBadge
											transaction={
												laboratoryPurchase
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
					<Strong>{laboratoryPurchase.full_name}</Strong>
				</Text>
				<div className="flex items-center gap-1">
					<PhoneIcon className="size-4 fill-zinc-400 dark:fill-slate-600" />
					{laboratoryPurchase.phone}
				</div>
				<div className="mt-1 flex items-center gap-2">
					<Badge color="slate">
						{laboratoryPurchase.formatted_gender}
					</Badge>
					<Badge color="slate">
						{laboratoryPurchase.formatted_birth_date}
					</Badge>
				</div>
			</TableCell>

			{showBrand && (
				<TableCell>
					<LaboratoryBrandCard
						src={
							"/images/gda/GDA-" +
							laboratoryPurchase.brand.toUpperCase() +
							".png"
						}
						className="w-32 p-4"
					/>
				</TableCell>
			)}

			<TableCell className="text-right">
				<div className="flex items-center justify-end gap-1">
					Resultados
					{laboratoryPurchase.results ? (
						<CheckIcon className="size-4 fill-famedic-light" />
					) : (
						<NoSymbolIcon className="size-4 fill-slate-500" />
					)}
				</div>
				<div className="flex items-center justify-end gap-1">
					Factura
					{laboratoryPurchase.invoice ? (
						<CheckIcon className="size-4 fill-famedic-light" />
					) : laboratoryPurchase.invoice_request ? (
						<ClockIcon className="size-4 fill-famedic-light" />
					) : (
						<NoSymbolIcon className="size-4 fill-slate-500" />
					)}
				</div>
				<div className="flex items-center justify-end gap-1">
					Pago a proveedor
					{laboratoryPurchase.vendor_payments.length ? (
						<CheckIcon className="size-4 fill-famedic-light" />
					) : (
						<NoSymbolIcon className="size-4 fill-slate-500" />
					)}
				</div>
				{laboratoryPurchase.dev_assistance_requests.length > 0 && (
					<div className="flex items-center justify-end gap-1">
						Asistencia técnica
						{laboratoryPurchase.dev_assistance_requests.some(
							(r) => !r.resolved_at,
						) ? (
							<CommandLineIcon className="size-4 animate-pulse fill-famedic-light" />
						) : (
							<Badge color="slate" className="text-xs">
								{
									laboratoryPurchase.dev_assistance_requests
										.length
								}
							</Badge>
						)}
					</div>
				)}
			</TableCell>
		</TableRow>
	);
}
