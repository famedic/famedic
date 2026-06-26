import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { CreditCardIcon, ArrowDownTrayIcon } from "@heroicons/react/24/outline";

function PaymentRow({ label, value }) {
	return (
		<div className="flex items-start justify-between gap-4 border-b border-slate-100 py-3 last:border-b-0 dark:border-slate-800">
			<Text className="text-sm text-zinc-500">{label}</Text>
			<p className="max-w-[60%] break-all text-right text-sm font-medium text-zinc-800 dark:text-slate-100">
				{value ?? "—"}
			</p>
		</div>
	);
}

export default function MembershipPaymentCard({ payment, capabilities }) {
	if (!payment) {
		return null;
	}

	const statusColor = payment.statusKey === "success" ? "emerald" : "amber";

	return (
		<Card className="p-6 shadow-sm ring-1 ring-slate-100 sm:p-8">
			<div className="mb-4 flex items-start justify-between gap-4">
				<div className="flex items-center gap-3">
					<div className="flex size-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300">
						<CreditCardIcon className="size-5" />
					</div>
					<div>
						<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
							Información del pago
						</h3>
						<Text className="text-sm text-zinc-500">
							Detalle de tu última transacción.
						</Text>
					</div>
				</div>
				<Badge color={statusColor}>{payment.status}</Badge>
			</div>

			<div>
				<PaymentRow
					label="Número de transacción"
					value={payment.transactionNumber}
				/>
				<PaymentRow
					label="Proveedor de pago"
					value={payment.provider}
				/>
				<PaymentRow label="Método" value={payment.method} />
				<PaymentRow
					label="Autorización"
					value={payment.authorization}
				/>
				<PaymentRow label="Referencia" value={payment.reference} />
				<PaymentRow label="Fecha" value={payment.date} />
				<PaymentRow label="Hora" value={payment.time} />
				<PaymentRow label="Monto" value={payment.amount} />
			</div>

			{capabilities?.canDownloadReceipt && (
				<div className="mt-6">
					<Button
						outline
						disabled={!capabilities.receiptDownloadUrl}
						href={capabilities.receiptDownloadUrl ?? undefined}
						className="w-full sm:w-auto"
					>
						<ArrowDownTrayIcon className="size-4" />
						Descargar comprobante
					</Button>
					{!capabilities.receiptDownloadUrl && (
						<Text className="mt-2 text-xs text-zinc-400">
							Próximamente disponible.
						</Text>
					)}
				</div>
			)}
		</Card>
	);
}
