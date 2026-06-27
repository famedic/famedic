import { useEffect } from "react";
import LaboratoryOrderDetail from "@/Pages/LaboratoryOrderDetail";
export default function LaboratoryPurchase({
	laboratoryPurchase,
	isCancelled = false,
	confetti,
	latestSampleCollectionAt,
	latestResultsAt,
	hasSampleCollected,
	hasResultsAvailable,
	is_new_result = false,
}) {
	useEffect(() => {
		if (laboratoryPurchase && !window.ga4PurchaseSent) {
			window.dataLayer = window.dataLayer || [];

			const totalValue = laboratoryPurchase.total_cents / 100;
			const items =
				laboratoryPurchase.laboratory_purchase_items?.map((item, index) => ({
					item_id: item.gda_id || `lab_${item.id}`,
					item_name: item.name || "Laboratory Test",
					price: item.price_cents ? item.price_cents / 100 : 0,
					quantity: 1,
					item_category: "Laboratory Tests",
					item_brand: laboratoryPurchase.brand?.value || "laboratory",
					index,
				})) || [];

			window.dataLayer.push({
				event: "purchase",
				ecommerce: {
					transaction_id: laboratoryPurchase.id.toString(),
					value: totalValue,
					currency: "MXN",
					items,
				},
			});

			window.ga4PurchaseSent = true;
		}
	}, [laboratoryPurchase]);

	return (
		<LaboratoryOrderDetail
			laboratoryPurchase={laboratoryPurchase}
			isCancelled={isCancelled || Boolean(laboratoryPurchase?.deleted_at)}
			confetti={confetti}
			latestSampleCollectionAt={latestSampleCollectionAt}
			latestResultsAt={latestResultsAt}
			hasSampleCollected={hasSampleCollected}
			hasResultsAvailable={hasResultsAvailable}
			isNewResult={is_new_result}
		/>
	);
}
