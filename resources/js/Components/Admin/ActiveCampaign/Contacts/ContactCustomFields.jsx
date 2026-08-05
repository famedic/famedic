import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";
import { MetaRow } from "./ContactSectionShell";

/**
 * Campos personalizados relevantes desde ActiveCampaignMirrorService.
 */
export default function ContactCustomFields({
	ready = false,
	loading = false,
	mirror = null,
}) {
	const status = mirror?.status;
	const fields = Array.isArray(mirror?.fields) ? mirror.fields : [];
	const errored = status === "error" || status === "missing";
	const truth = !ready || loading ? "proxy" : errored ? "proxy" : "disponible";

	return (
		<ContactDrawerSection
			title="Campos personalizados"
			description="Campos relevantes del contacto en ActiveCampaign."
			truth={truth}
		>
			{!ready || loading ? (
				<div className="h-28 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800" />
			) : errored ? (
				<Text className="text-sm text-zinc-500">
					{mirror?.message ||
						"No fue posible obtener la información de ActiveCampaign."}
				</Text>
			) : fields.length === 0 ? (
				<div className="rounded-lg border border-dashed border-zinc-200 bg-zinc-50/80 px-4 py-6 text-center dark:border-zinc-700 dark:bg-zinc-950/40">
					<p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
						Sin campos relevantes
					</p>
					<Text className="mt-1 text-xs text-zinc-500">
						Solo se muestran Empresa, Socio, Ciudad, RFC, fechas, membresía,
						fuente, canal y referido.
					</Text>
				</div>
			) : (
				<div className="space-y-1 rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-950/40">
					{fields.map((field) => (
						<MetaRow
							key={field.field_value_id || field.field_id}
							label={field.title || field.perstag || `Campo #${field.field_id}`}
							value={
								field.value !== null && field.value !== ""
									? field.value
									: "—"
							}
						/>
					))}
				</div>
			)}
		</ContactDrawerSection>
	);
}
