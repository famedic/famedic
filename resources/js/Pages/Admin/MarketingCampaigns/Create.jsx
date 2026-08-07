import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import MarketingCampaignSetupWizard from "./wizard/MarketingCampaignSetupWizard";

export default function MarketingCampaignsCreate({
	statusOptions = {},
	linkStatusOptions = {},
	brands = {},
	categories = [],
	collections = [],
	productSearchUrl,
	utmPresets = [],
	promotionOptions = [],
}) {
	return (
		<AdminLayout title="Nueva campaña">
			<div className="mx-auto max-w-5xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Nueva campaña</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Asistente guiado para crear la campaña, el enlace y la
							landing en un solo flujo.
						</Text>
					</div>
					<Button
						href={route("admin.marketing-campaigns.index")}
						outline
					>
						Volver
					</Button>
				</div>

				<MarketingCampaignSetupWizard
					mode="campaign"
					statusOptions={statusOptions}
					linkStatusOptions={linkStatusOptions}
					brands={brands}
					categories={categories}
					collections={collections}
					productSearchUrl={productSearchUrl}
					utmPresets={utmPresets}
					promotionOptions={promotionOptions}
				/>
			</div>
		</AdminLayout>
	);
}
