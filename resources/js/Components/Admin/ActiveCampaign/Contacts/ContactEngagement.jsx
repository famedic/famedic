import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";
import { MetaRow } from "./ContactSectionShell";

/**
 * Engagement email derivado del snapshot (sentcnt + activities).
 */
export default function ContactEngagement({
	ready = false,
	loading = false,
	mirror = null,
}) {
	const status = mirror?.status;
	const engagement = mirror?.engagement || null;
	const errored = status === "error" || status === "missing";
	const truth = !ready || loading ? "proxy" : errored ? "proxy" : "disponible";

	return (
		<ContactDrawerSection
			title="Engagement"
			description="Aperturas, clicks y envíos de email en ActiveCampaign."
			truth={truth}
		>
			{!ready || loading ? (
				<div className="h-28 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800" />
			) : errored ? (
				<Text className="text-sm text-zinc-500">
					{mirror?.message ||
						"No fue posible obtener la información de ActiveCampaign."}
				</Text>
			) : !engagement ? (
				<Text className="text-sm text-zinc-500">No disponible</Text>
			) : (
				<div className="space-y-1 rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-950/40">
					<MetaRow label="Emails enviados" value={engagement.emails_sent} />
					<MetaRow label="Último open" value={engagement.last_open} />
					<MetaRow label="Último click" value={engagement.last_click} />
					<MetaRow label="Open rate" value={engagement.open_rate} />
					<MetaRow label="Click rate" value={engagement.click_rate} />
					<MetaRow label="Última campaña" value={engagement.last_campaign} />
				</div>
			)}
		</ContactDrawerSection>
	);
}
