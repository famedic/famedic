import { ContactSectionShell, ItemCard, MetaRow } from "./ContactSectionShell";

export default function ContactPurchases({
	ready = false,
	loading = false,
	payload = null,
	onRequest,
}) {
	return (
		<ContactSectionShell
			title="Compras"
			description="Compras de laboratorio y farmacia en Famedic."
			truth="disponible"
			ready={ready}
			loading={loading}
			items={payload?.items}
			emptyMessage="Sin compras registradas."
			onRequest={onRequest}
		>
			{(items) => (
				<div className="space-y-2">
					{items.map((row) => (
						<ItemCard
							key={row.id}
							title={row.type}
							badge={row.status}
							badgeColor={
								row.status === "Cancelada" ? "zinc" : "emerald"
							}
						>
							<MetaRow label="Fecha" value={row.date} />
							<MetaRow label="Monto" value={row.amount} />
							<MetaRow label="Origen" value={row.origin} />
							<MetaRow label="Estado" value={row.status} />
						</ItemCard>
					))}
				</div>
			)}
		</ContactSectionShell>
	);
}
