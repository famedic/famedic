import { ContactSectionShell, ItemCard, MetaRow } from "./ContactSectionShell";

export default function ContactMemberships({
	ready = false,
	loading = false,
	payload = null,
	onRequest,
}) {
	return (
		<ContactSectionShell
			title="Membresías"
			description="Suscripciones de atención médica."
			truth="disponible"
			ready={ready}
			loading={loading}
			items={payload?.items}
			emptyMessage="Sin membresías registradas."
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
								row.status === "Activa" ? "emerald" : "zinc"
							}
						>
							<MetaRow label="Estado" value={row.status} />
							<MetaRow label="Tipo" value={row.type} />
							<MetaRow label="Inicio" value={row.start_date} />
							<MetaRow label="Expiración" value={row.end_date} />
							<MetaRow label="Renovaciones" value={row.renewals} />
							<MetaRow
								label="Beneficios activos"
								value={row.active_benefits}
							/>
						</ItemCard>
					))}
				</div>
			)}
		</ContactSectionShell>
	);
}
