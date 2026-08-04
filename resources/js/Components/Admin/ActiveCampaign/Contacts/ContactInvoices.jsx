import { ContactSectionShell, ItemCard, MetaRow } from "./ContactSectionShell";

export default function ContactInvoices({
	ready = false,
	loading = false,
	payload = null,
	onRequest,
}) {
	return (
		<ContactSectionShell
			title="Facturas"
			description="CFDI ligados a compras del customer."
			truth="disponible"
			ready={ready}
			loading={loading}
			items={payload?.items}
			emptyMessage="Sin facturas registradas."
			onRequest={onRequest}
		>
			{(items) => (
				<div className="space-y-2">
					{items.map((row) => (
						<ItemCard
							key={row.id}
							title={`Factura #${row.id}`}
							badge={row.status}
							badgeColor={
								row.status === "Completada" ? "emerald" : "amber"
							}
						>
							<MetaRow label="Estado" value={row.status} />
							<MetaRow label="RFC" value={row.rfc} />
							<MetaRow label="Fecha" value={row.date} />
							<MetaRow label="Monto" value={row.amount} />
						</ItemCard>
					))}
				</div>
			)}
		</ContactSectionShell>
	);
}
