import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import MarketingCampaignCollectionForm from "../Components/MarketingCampaignCollectionForm";

export default function MarketingCampaignCollectionsCreate({
	campaign,
	brands = {},
	productSearchUrl,
	maxCollectionItems = 50,
}) {
	const { data, setData, post, processing, errors, transform } = useForm({
		name: "",
		public_title: "",
		public_description: "",
		laboratory_brand: "",
		is_active: true,
		laboratory_test_ids: [],
		return_to_campaign: false,
	});

	transform((form) => ({
		...form,
		public_title: form.public_title?.trim() || form.name?.trim() || "",
		public_description: form.public_description?.trim()
			? form.public_description
			: null,
		laboratory_test_ids: form.laboratory_test_ids || [],
		return_to_campaign: Boolean(form.return_to_campaign),
	}));

	const submit = (event, { returnToCampaign = false } = {}) => {
		event.preventDefault();
		if (processing) return;

		post(
			route("admin.marketing-campaigns.collections.store", {
				marketing_campaign: campaign.id,
			}),
			{
				onBefore: (visit) => {
					visit.data.return_to_campaign = returnToCampaign;
				},
			},
		);
	};

	return (
		<AdminLayout title={`Nueva colección · ${campaign.name}`}>
			<div className="mx-auto max-w-3xl space-y-6">
				<div>
					<Heading>Colección de estudios</Heading>
					<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
						Campaña: {campaign.name}
					</Text>
				</div>

				<MarketingCampaignCollectionForm
					campaign={campaign}
					data={data}
					setData={setData}
					errors={errors}
					brands={brands}
					productSearchUrl={productSearchUrl}
					maxCollectionItems={maxCollectionItems}
					processing={processing}
					onSubmit={submit}
					onCancel={() =>
						router.visit(
							route("admin.marketing-campaigns.show", campaign.id),
						)
					}
					submitLabel="Guardar colección"
				/>
			</div>
		</AdminLayout>
	);
}
