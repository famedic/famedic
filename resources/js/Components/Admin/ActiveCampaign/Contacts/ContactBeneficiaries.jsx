import { ContactSectionShell, ItemCard, MetaRow } from "./ContactSectionShell";

export default function ContactBeneficiaries({
	ready = false,
	loading = false,
	payload = null,
	onRequest,
}) {
	return (
		<ContactSectionShell
			title="Beneficiarios"
			description="Familiares vinculados a la cuenta (solo lectura)."
			truth="disponible"
			ready={ready}
			loading={loading}
			items={payload?.items}
			emptyMessage="Sin beneficiarios familiares."
			onRequest={onRequest}
		>
			{(items) => (
				<div className="space-y-2">
					{items.map((row) => (
						<ItemCard
							key={row.id}
							title={row.name}
							badge={row.status}
							badgeColor={
								row.status === "Activo" ? "emerald" : "zinc"
							}
						>
							<MetaRow label="Nombre" value={row.name} />
							<MetaRow label="Parentesco" value={row.kinship} />
							<MetaRow label="Estado" value={row.status} />
							<MetaRow label="Fecha alta" value={row.registered_at} />
						</ItemCard>
					))}
				</div>
			)}
		</ContactSectionShell>
	);
}
