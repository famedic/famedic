import VendorPaymentLayout from "@/Layouts/VendorPaymentLayout";
import { useState } from "react";

export default function LaboratoryVendorPaymentCreate({
	purchases,
	selectedPurchasesDetails,
	filters,
}) {
	const [selectedPurchasesIds, setSelectedPurchasesIds] = useState(
		selectedPurchasesDetails.selectedPurchases.map((p) => p.id),
	);

	return (
		<VendorPaymentLayout
			heading="Crear pago a GDA"
			purchases={purchases}
			selectedPurchasesDetails={selectedPurchasesDetails}
			selectedPurchasesIds={selectedPurchasesIds}
			setSelectedPurchasesIds={setSelectedPurchasesIds}
			filters={filters}
		></VendorPaymentLayout>
	);
}
