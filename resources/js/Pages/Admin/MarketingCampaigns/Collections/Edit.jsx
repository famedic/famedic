import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import MarketingCampaignCollectionForm from "../Components/MarketingCampaignCollectionForm";

function normalizeBrand(value) {
	if (value == null) return "";
	if (typeof value === "object") {
		return String(value.value ?? value.name ?? "");
	}
	return String(value);
}

export default function MarketingCampaignCollectionsEdit({
	campaign,
	collection,
	brands = {},
	productSearchUrl,
	maxCollectionItems = 50,
	selectedItems = [],
	usingLinks = [],
	usingLinksCount = 0,
}) {
	const { data, setData, put, processing, errors, transform } = useForm({
		name: collection.name || "",
		public_title: collection.public_title || "",
		public_description: collection.public_description || "",
		laboratory_brand: normalizeBrand(collection.laboratory_brand),
		is_active: Boolean(collection.is_active),
		laboratory_test_ids: selectedItems.map((item) => item.id),
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

		put(
			route("admin.marketing-campaigns.collections.update", {
				marketing_campaign: campaign.id,
				marketing_campaign_collection: collection.id,
			}),
			{
				onBefore: (visit) => {
					visit.data.return_to_campaign = returnToCampaign;
				},
			},
		);
	};

	return (
		<AdminLayout title={`Editar colección · ${collection.name}`}>
			<div className="mx-auto max-w-3xl space-y-6">
				<div>
					<Heading>Editar colección de estudios</Heading>
					<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
						Campaña: {campaign.name}
					</Text>
				</div>

				<MarketingCampaignCollectionForm
					campaign={campaign}
					collection={collection}
					data={data}
					setData={setData}
					errors={errors}
					brands={brands}
					productSearchUrl={productSearchUrl}
					maxCollectionItems={maxCollectionItems}
					initialItems={selectedItems}
					processing={processing}
					onSubmit={submit}
					onCancel={() =>
						router.visit(
							route("admin.marketing-campaigns.show", campaign.id),
						)
					}
					submitLabel="Guardar cambios"
					showUsingLinks
					usingLinks={usingLinks}
					usingLinksCount={usingLinksCount}
				/>
			</div>
		</AdminLayout>
	);
}
