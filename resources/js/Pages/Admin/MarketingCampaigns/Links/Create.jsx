import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import MarketingCampaignLinkForm from "../Components/MarketingCampaignLinkForm";
import { fromDatetimeLocalValue } from "../Components/MarketingCampaignDateRangeFields";

export default function MarketingCampaignLinksCreate({
	campaign,
	statusOptions = {},
	targetTypeOptions = [],
	brands = {},
	categories = [],
	collections = [],
	productSearchUrl,
}) {
	const { data, setData, post, processing, errors, transform } = useForm({
		name: "",
		slug: "",
		status: "draft",
		target_type: "brand",
		target_payload: {},
		utm_source: "",
		utm_medium: "",
		utm_campaign: "",
		utm_term: "",
		utm_content: "",
		starts_at: "",
		ends_at: "",
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
			post(
				route("admin.marketing-campaigns.links.store", {
					marketing_campaign: campaign.id,
				}),
			);
		}
	};

	return (
		<AdminLayout title={`Nuevo enlace · ${campaign.name}`}>
			<div className="mx-auto max-w-3xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Nuevo enlace</Heading>
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
					targetTypeOptions={targetTypeOptions}
					brands={brands}
					categories={categories}
					collections={collections}
					productSearchUrl={productSearchUrl}
					processing={processing}
					onSubmit={submit}
					submitLabel="Crear enlace"
				/>
			</div>
		</AdminLayout>
	);
}
