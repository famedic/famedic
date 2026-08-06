import { ContactSectionShell, ItemCard, MetaRow } from "./ContactSectionShell";

const BUCKET_COLOR = {
	available: "emerald",
	used: "zinc",
	expired: "red",
	assigned: "sky",
};

export default function ContactCoupons({
	ready = false,
	loading = false,
	payload = null,
	onRequest,
}) {
	return (
		<ContactSectionShell
			title="Cupones"
			description="Cupones asignados al usuario de la cuenta."
			truth="disponible"
			ready={ready}
			loading={loading}
			items={payload?.items}
			emptyMessage="Sin cupones asignados."
			onRequest={onRequest}
		>
			{(items) => (
				<div className="space-y-2">
					{items.map((row) => (
						<ItemCard
							key={row.id}
							title={row.code}
							badge={row.bucket_label}
							badgeColor={BUCKET_COLOR[row.bucket] || "zinc"}
						>
							<MetaRow label="Estado" value={row.bucket_label} />
							<MetaRow label="Asignado" value={row.assigned_at} />
							<MetaRow label="Utilizado" value={row.used_at} />
							<MetaRow label="Expira" value={row.expires_at} />
							<MetaRow label="Saldo" value={row.remaining} />
						</ItemCard>
					))}
				</div>
			)}
		</ContactSectionShell>
	);
}
