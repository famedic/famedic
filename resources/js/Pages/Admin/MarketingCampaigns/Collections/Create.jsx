import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import MarketingCampaignCollectionForm from "../Components/MarketingCampaignCollectionForm";

export default function MarketingCampaignCollectionsCreate({
	campaign,
	brands = {},
	productSearchUrl,
}) {
	const { data, setData, post, processing, errors, transform } = useForm({
		name: "",
		public_title: "",
		public_description: "",
		laboratory_brand: "",
		is_active: true,
		laboratory_test_ids: [],
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
			post(
				route("admin.marketing-campaigns.collections.store", {
					marketing_campaign: campaign.id,
				}),
			);
		}
	};

	return (
		<AdminLayout title={`Nueva colección · ${campaign.name}`}>
			<div className="mx-auto max-w-3xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Nueva colección</Heading>
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
					processing={processing}
					onSubmit={submit}
					submitLabel="Crear colección"
				/>
			</div>
		</AdminLayout>
	);
}
