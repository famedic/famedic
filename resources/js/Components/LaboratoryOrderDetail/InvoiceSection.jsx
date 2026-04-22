import { useState } from "react";
import { usePage } from "@inertiajs/react";
import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Anchor } from "@/Components/Catalyst/text";
import RequestInvoiceModal from "@/Components/RequestInvoiceModal";

export default function InvoiceSection({ purchase }) {
	const { daysLeftToRequestInvoice } = usePage().props;
	const [showModal, setShowModal] = useState(false);

	const hasInvoice = Boolean(purchase?.invoice);
	const hasInvoiceRequest = Boolean(purchase?.invoice_request);
	const canRequestInvoice =
		!hasInvoice && !hasInvoiceRequest && daysLeftToRequestInvoice > 0;

	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-5">
			<div className="mb-4 flex min-w-0 flex-wrap items-start justify-between gap-2">
				<h3 className="min-w-0 flex-1 break-words text-base font-semibold text-zinc-900 dark:text-white">
					Facturas
				</h3>
				<Badge color={hasInvoice ? "green" : hasInvoiceRequest ? "blue" : "slate"} className="shrink-0">
					{hasInvoice ? "Disponible" : hasInvoiceRequest ? "Solicitada" : "Sin solicitar"}
				</Badge>
			</div>

			{hasInvoice && (
				<Anchor href={route("invoice", { invoice: purchase.invoice })} target="_blank">
					<Button outline className="w-full" type="button">
						Descargar factura
					</Button>
				</Anchor>
			)}

			{!hasInvoice && hasInvoiceRequest && (
				<p className="break-words text-sm text-zinc-500 dark:text-slate-400">
					Tu factura esta en proceso. Te notificaremos cuando este lista.
				</p>
			)}

			{canRequestInvoice && (
				<div className="space-y-2">
					<Button className="w-full" type="button" onClick={() => setShowModal(true)}>
						Solicitar factura
					</Button>
					<p className="break-words text-xs text-zinc-500 dark:text-slate-400">
						Te quedan {daysLeftToRequestInvoice} días para solicitar la factura.
					</p>
				</div>
			)}

			{!hasInvoice && !hasInvoiceRequest && !canRequestInvoice && (
				<p className="break-words text-sm text-zinc-500 dark:text-slate-400">
					El periodo para solicitar factura ya termino.
				</p>
			)}

			<RequestInvoiceModal
				purchase={purchase}
				isOpen={showModal}
				storeRoute={route("laboratory-purchases.invoice-request", {
					laboratory_purchase: purchase,
				})}
				close={() => setShowModal(false)}
			/>
		</Card>
	);
}
