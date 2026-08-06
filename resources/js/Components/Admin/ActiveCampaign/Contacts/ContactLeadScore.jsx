import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";
import { MetaRow } from "./ContactSectionShell";

function classificationColor(label) {
	if (label === "Excelente") return "emerald";
	if (label === "Bueno") return "sky";
	if (label === "En Riesgo") return "amber";
	return "rose";
}

/**
 * Lead Score desde ActiveCampaignMirrorService (snapshot.leadScore).
 */
export default function ContactLeadScore({
	ready = false,
	loading = false,
	mirror = null,
}) {
	const status = mirror?.status;
	const lead = mirror?.lead_score || null;
	const owner = mirror?.owner || null;
	const errored = status === "error" || status === "missing";
	const truth = !ready || loading ? "proxy" : errored ? "proxy" : "disponible";

	return (
		<ContactDrawerSection
			title="Lead Score"
			description="Puntuación del contacto en ActiveCampaign."
			truth={truth}
		>
			{!ready || loading ? (
				<div className="h-24 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800" />
			) : errored ? (
				<Text className="text-sm text-zinc-500">
					{mirror?.message ||
						"No fue posible obtener la información de ActiveCampaign."}
				</Text>
			) : !lead ? (
				<Text className="text-sm text-zinc-500">Sin lead score.</Text>
			) : (
				<div className="space-y-3">
					<div className="flex flex-wrap items-end justify-between gap-3">
						<div>
							<p className="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
								{lead.total}
							</p>
							<Text className="text-xs text-zinc-500">Score total</Text>
						</div>
						<Badge color={classificationColor(lead.classification)}>
							{lead.classification}
						</Badge>
					</div>
					<div className="space-y-1 rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-950/40">
						<MetaRow
							label="Score principal"
							value={
								lead.primary?.name
									? `${lead.primary.name} · ${lead.primary.score_value}`
									: lead.primary
										? String(lead.primary.score_value)
										: "—"
							}
						/>
						<MetaRow label="Actualización" value={lead.updated_at || "—"} />
					</div>
					{owner ? (
						<div className="space-y-1 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
							<p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
								Owner
							</p>
							<MetaRow label="Nombre" value={owner.name || "—"} />
							<MetaRow label="ID" value={String(owner.id)} />
							<MetaRow label="Email" value={owner.email || "—"} />
						</div>
					) : null}
				</div>
			)}
		</ContactDrawerSection>
	);
}
