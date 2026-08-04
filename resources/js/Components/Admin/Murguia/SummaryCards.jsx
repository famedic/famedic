import { Text } from "@/Components/Catalyst/text";

const CARD_ITEMS = [
	{ key: "total_customers", label: "Total customers" },
	{ key: "with_certificate_account", label: "Con certificate account" },
	{ key: "local_membership_active", label: "Membresía activa (local)" },
	{ key: "subscription_vigente", label: "Suscripción vigente" },
	{ key: "expired", label: "Vencidos" },
	{ key: "no_subscription", label: "Sin suscripción" },
	{ key: "odessa", label: "Odessa" },
	{ key: "regular", label: "Regular" },
	{ key: "familiar", label: "Familiar" },
	{ key: "trial", label: "Trial / Pruebas" },
	{ key: "institutional", label: "Institucional" },
	{ key: "family_dependents", label: "Familiares / dependientes" },
	{ key: "murguia_synced", label: "Sincronizados Murguía" },
	{ key: "murguia_sync_error", label: "Error sync Murguía" },
	{ key: "no_lab_purchases", label: "Sin compras de laboratorio" },
];

export default function SummaryCards({ summary }) {
	return (
		<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
			{CARD_ITEMS.map((item) => (
				<div
					key={item.key}
					className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
				>
					<Text className="text-xs text-zinc-500 dark:text-zinc-400">
						{item.label}
					</Text>
					<p className="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">
						{Number(summary?.[item.key] ?? 0).toLocaleString("es-MX")}
					</p>
				</div>
			))}
		</div>
	);
}
