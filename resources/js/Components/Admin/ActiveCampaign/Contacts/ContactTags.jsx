import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";

/**
 * Tags reales desde ActiveCampaignMirrorService (snapshot.tags).
 */
export default function ContactTags({
	ready = false,
	loading = false,
	mirror = null,
}) {
	const status = mirror?.status;
	const tags = Array.isArray(mirror?.tags) ? mirror.tags : [];
	const errored = status === "error" || status === "missing";
	const truth = !ready || loading ? "proxy" : errored ? "proxy" : "disponible";

	return (
		<ContactDrawerSection
			title="Tags"
			description={
				ready && !loading && !errored
					? `${tags.length} tag${tags.length === 1 ? "" : "s"} en ActiveCampaign`
					: "Tags del contacto en ActiveCampaign."
			}
			truth={truth}
		>
			{!ready || loading ? (
				<div className="flex flex-wrap gap-2" aria-busy="true">
					{Array.from({ length: 4 }).map((_, i) => (
						<div
							key={i}
							className="h-6 w-20 animate-pulse rounded-md bg-zinc-100 dark:bg-zinc-800"
						/>
					))}
				</div>
			) : errored ? (
				<Text className="text-sm text-zinc-500">
					{mirror?.message ||
						"No fue posible obtener la información de ActiveCampaign."}
				</Text>
			) : tags.length === 0 ? (
				<div className="rounded-lg border border-dashed border-zinc-200 bg-zinc-50/80 px-4 py-6 text-center dark:border-zinc-700 dark:bg-zinc-950/40">
					<p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
						Sin tags en ActiveCampaign
					</p>
					<Text className="mt-1 text-xs text-zinc-500">
						Cuando se asignen tags al contacto, aparecerán aquí.
					</Text>
				</div>
			) : (
				<div className="flex flex-wrap gap-2">
					{tags.map((tag) => (
						<Badge
							key={tag.contact_tag_id || tag.tag_id || tag.name}
							color="sky"
							className="!text-[11px]"
						>
							{tag.name || `Tag #${tag.tag_id}`}
						</Badge>
					))}
				</div>
			)}
		</ContactDrawerSection>
	);
}
