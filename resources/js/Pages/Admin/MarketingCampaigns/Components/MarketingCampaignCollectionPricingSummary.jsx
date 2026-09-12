import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";
import {
	computeCollectionPricing,
	formatCents,
} from "./collectionPricing";

export default function MarketingCampaignCollectionPricingSummary({
	items = [],
}) {
	const pricing = computeCollectionPricing(items);

	if (!pricing.reliable) {
		return null;
	}

	return (
		<Card className="space-y-3 p-4">
			<Text className="font-semibold">Resumen informativo</Text>
			<div className="grid gap-3 sm:grid-cols-3">
				<div>
					<Text className="text-xs uppercase tracking-wide text-zinc-500">
						Suma Famedic
					</Text>
					<Text className="mt-1 font-medium">
						{formatCents(pricing.famedicTotal)}
					</Text>
				</div>
				<div>
					<Text className="text-xs uppercase tracking-wide text-zinc-500">
						Suma precio público
					</Text>
					<Text className="mt-1 font-medium">
						{formatCents(pricing.publicTotal)}
					</Text>
				</div>
				<div>
					<Text className="text-xs uppercase tracking-wide text-zinc-500">
						Ahorro estimado
					</Text>
					<Text className="mt-1 font-medium">
						{formatCents(pricing.savings)}
					</Text>
				</div>
			</div>
			<Text className="text-sm text-zinc-500">
				Esta colección no constituye un paquete ni aplica un precio
				especial. Los estudios se agregan y cobran individualmente.
			</Text>
		</Card>
	);
}
