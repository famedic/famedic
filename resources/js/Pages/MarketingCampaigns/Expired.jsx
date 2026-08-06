import FamedicLayout from "@/Layouts/FamedicLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";

export default function MarketingCampaignExpired({ catalog_url }) {
	return (
		<FamedicLayout title="Campaña finalizada">
			<div className="mx-auto flex max-w-lg flex-col items-center gap-6 px-4 py-16 text-center">
				<Heading>Campaña finalizada</Heading>
				<Text>Esta campaña ha finalizado.</Text>
				<Button href={catalog_url} color="lime">
					Ver estudios disponibles
				</Button>
			</div>
		</FamedicLayout>
	);
}
