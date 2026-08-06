import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";
import { ItemCard, MetaRow } from "./ContactSectionShell";

function statusColor(status) {
	if (status === "Activa") return "emerald";
	if (status === "Completada") return "sky";
	if (status === "Inactiva") return "zinc";
	return "amber";
}

/**
 * Automatizaciones reales desde ActiveCampaignMirrorService (snapshot.automations).
 */
export default function ContactAutomations({
	ready = false,
	loading = false,
	mirror = null,
}) {
	const status = mirror?.status;
	const automations = Array.isArray(mirror?.automations)
		? mirror.automations
		: [];
	const errored = status === "error" || status === "missing";
	const truth = !ready || loading ? "proxy" : errored ? "proxy" : "disponible";

	return (
		<ContactDrawerSection
			title="Automatizaciones"
			description="Flujos ActiveCampaign en los que participa el contacto."
			truth={truth}
		>
			{!ready || loading ? (
				<div className="space-y-2" aria-busy="true">
					{Array.from({ length: 2 }).map((_, i) => (
						<div
							key={i}
							className="h-20 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800"
						/>
					))}
				</div>
			) : errored ? (
				<Text className="text-sm text-zinc-500">
					{mirror?.message ||
						"No fue posible obtener la información de ActiveCampaign."}
				</Text>
			) : automations.length === 0 ? (
				<div className="rounded-lg border border-dashed border-zinc-200 bg-zinc-50/80 px-4 py-6 text-center dark:border-zinc-700 dark:bg-zinc-950/40">
					<p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
						Sin automatizaciones
					</p>
					<Text className="mt-1 text-xs text-zinc-500">
						Cuando el contacto entre a un flujo, se mostrará aquí.
					</Text>
				</div>
			) : (
				<div className="space-y-2">
					{automations.map((row) => (
						<ItemCard
							key={row.id || row.name}
							title={row.name}
							badge={row.status}
							badgeColor={statusColor(row.status)}
						>
							<MetaRow label="Estado" value={row.status || "—"} />
							<MetaRow label="Ingreso" value={row.entered_at || "—"} />
							<MetaRow
								label="Actualización"
								value={row.updated_at || "—"}
							/>
							{row.complete_value != null ? (
								<MetaRow
									label="Progreso"
									value={`${row.complete_value}%`}
								/>
							) : null}
						</ItemCard>
					))}
				</div>
			)}
		</ContactDrawerSection>
	);
}
