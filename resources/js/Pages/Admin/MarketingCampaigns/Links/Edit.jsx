import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import MarketingCampaignLinkForm from "../Components/MarketingCampaignLinkForm";
import {
	toDatetimeLocalValue,
	fromDatetimeLocalValue,
} from "../Components/MarketingCampaignDateRangeFields";

function normalizeEnum(value, fallback = "") {
	if (value == null) return fallback;
	if (typeof value === "object") {
		return String(value.value ?? value.name ?? fallback);
	}
	return String(value);
}

export default function MarketingCampaignLinksEdit({
	campaign,
	link,
	statusOptions = {},
	brands = {},
	categories = [],
	collections = [],
	productSearchUrl,
	aliases = [],
}) {
	const { data, setData, put, processing, errors, transform } = useForm({
		name: link.name || "",
		slug: link.slug || "",
		status: normalizeEnum(link.status, "draft"),
		target_type: normalizeEnum(link.target_type, "brand"),
		target_payload: link.target_payload || {},
		utm_source: link.utm_source || "",
		utm_medium: link.utm_medium || "",
		utm_campaign: link.utm_campaign || "",
		utm_term: link.utm_term || "",
		utm_content: link.utm_content || "",
		starts_at: toDatetimeLocalValue(link.starts_at),
		ends_at: toDatetimeLocalValue(link.ends_at),
	});

	transform((form) => ({
		...form,
		starts_at: fromDatetimeLocalValue(form.starts_at),
		ends_at: fromDatetimeLocalValue(form.ends_at),
		utm_source: form.utm_source || null,
		utm_medium: form.utm_medium || null,
		utm_campaign: form.utm_campaign || null,
		utm_term: form.utm_term || null,
		utm_content: form.utm_content || null,
	}));

	const submit = (e) => {
		e.preventDefault();
		if (!processing) {
			put(
				route("admin.marketing-campaigns.links.update", {
					marketing_campaign: campaign.id,
					marketing_campaign_link: link.id,
				}),
			);
		}
	};

	const aliasList = aliases.length ? aliases : link.aliases || [];

	return (
		<AdminLayout title={`Editar enlace · ${link.name}`}>
			<div className="mx-auto max-w-3xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Editar enlace</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Campaña: {campaign.name}
						</Text>
					</div>
					<Button
						href={route(
							"admin.marketing-campaigns.show",
							campaign.id,
						)}
						outline
					>
						Volver a la campaña
					</Button>
				</div>

				<MarketingCampaignLinkForm
					data={data}
					setData={setData}
					errors={errors}
					statusOptions={statusOptions}
					brands={brands}
					categories={categories}
					collections={collections}
					productSearchUrl={productSearchUrl}
					aliases={aliasList}
					isEdit
					processing={processing}
					onSubmit={submit}
					submitLabel="Guardar enlace"
				/>
			</div>
		</AdminLayout>
	);
}
