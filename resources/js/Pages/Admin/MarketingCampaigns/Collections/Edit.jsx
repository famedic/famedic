import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import MarketingCampaignCollectionForm from "../Components/MarketingCampaignCollectionForm";

function normalizeBrand(value) {
	if (value == null) return "";
	if (typeof value === "object") {
		return String(value.value ?? value.name ?? "");
	}
	return String(value);
}

function normalizeItems(collection) {
	if (Array.isArray(collection.selected_items)) {
		return collection.selected_items;
	}
	if (Array.isArray(collection.laboratory_tests)) {
		return collection.laboratory_tests;
	}
	if (Array.isArray(collection.items)) {
		return collection.items
			.map((item) => item.laboratory_test || item)
			.filter(Boolean);
	}
	return [];
}

export default function MarketingCampaignCollectionsEdit({
	campaign,
	collection,
	brands = {},
	productSearchUrl,
	selectedItems,
}) {
	const initialItems = selectedItems?.length
		? selectedItems
		: normalizeItems(collection);

	const { data, setData, put, processing, errors, transform } = useForm({
		name: collection.name || "",
		public_title: collection.public_title || "",
		public_description: collection.public_description || "",
		laboratory_brand: normalizeBrand(collection.laboratory_brand),
		is_active: Boolean(collection.is_active),
		laboratory_test_ids: initialItems.map((item) => item.id),
	});

	transform((form) => ({
		...form,
		public_description: form.public_description?.trim()
			? form.public_description
			: null,
		laboratory_test_ids: form.laboratory_test_ids || [],
	}));

	const submit = (e) => {
		e.preventDefault();
		if (!processing) {
			put(
				route("admin.marketing-campaigns.collections.update", {
					marketing_campaign: campaign.id,
					marketing_campaign_collection: collection.id,
				}),
			);
		}
	};

	return (
		<AdminLayout title={`Editar colección · ${collection.name}`}>
			<div className="mx-auto max-w-3xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Editar colección</Heading>
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

				<MarketingCampaignCollectionForm
					data={data}
					setData={setData}
					errors={errors}
					brands={brands}
					productSearchUrl={productSearchUrl}
					initialItems={initialItems}
					processing={processing}
					onSubmit={submit}
					submitLabel="Guardar colección"
				/>
			</div>
		</AdminLayout>
	);
}
