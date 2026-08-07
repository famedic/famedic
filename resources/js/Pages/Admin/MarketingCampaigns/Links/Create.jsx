import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import MarketingCampaignSetupWizard from "../wizard/MarketingCampaignSetupWizard";

export default function MarketingCampaignLinksCreate({
	campaign,
	statusOptions = {},
	linkStatusOptions = {},
	brands = {},
	categories = [],
	collections = [],
	productSearchUrl,
	utmPresets = [],
	promotionOptions = [],
	maxCollectionItems = 50,
}) {
	return (
		<AdminLayout title={`Nuevo enlace · ${campaign.name}`}>
			<div className="mx-auto max-w-5xl space-y-8">
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

				<MarketingCampaignSetupWizard
					mode="link"
					campaign={campaign}
					statusOptions={linkStatusOptions.length ? linkStatusOptions : statusOptions}
					linkStatusOptions={linkStatusOptions}
					brands={brands}
					categories={categories}
					collections={collections}
					productSearchUrl={productSearchUrl}
					utmPresets={utmPresets}
					promotionOptions={promotionOptions}
					maxCollectionItems={maxCollectionItems ?? 50}
				/>
			</div>
		</AdminLayout>
	);
}
