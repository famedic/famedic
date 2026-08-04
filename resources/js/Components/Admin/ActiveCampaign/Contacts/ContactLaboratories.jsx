import { ContactSectionShell, ItemCard, MetaRow } from "./ContactSectionShell";

export default function ContactLaboratories({
	ready = false,
	loading = false,
	payload = null,
	onRequest,
}) {
	return (
		<ContactSectionShell
			title="Laboratorios"
			description="Órdenes de laboratorio del paciente."
			truth="disponible"
			ready={ready}
			loading={loading}
			items={payload?.items}
			emptyMessage="Sin compras de laboratorio."
			onRequest={onRequest}
		>
			{(items) => (
				<div className="space-y-2">
					{items.map((row) => (
						<ItemCard
							key={row.id}
							title={row.provider}
							badge={row.status}
							badgeColor={
								row.status === "Cancelada" ? "zinc" : "blue"
							}
						>
							<MetaRow label="Fecha" value={row.date} />
							<MetaRow label="Estado" value={row.status} />
							<MetaRow
								label="Resultados"
								value={row.results_available}
							/>
							<MetaRow label="Proveedor" value={row.provider} />
							<MetaRow label="Monto" value={row.amount} />
						</ItemCard>
					))}
				</div>
			)}
		</ContactSectionShell>
	);
}
