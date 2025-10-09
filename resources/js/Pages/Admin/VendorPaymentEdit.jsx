import VendorPaymentLayout from "@/Layouts/VendorPaymentLayout";
import { useState } from "react";

export default function VendorPaymentEdit({
	vendorPayment,
	purchases,
	selectedPurchasesDetails,
	filters,
}) {
	const [selectedPurchasesIds, setSelectedPurchasesIds] = useState(
		selectedPurchasesDetails.selectedPurchases.map((p) => p.id),
	);

	const isLaboratoryType = route().current().includes("laboratory-purchases");
	const heading = `Editar ${isLaboratoryType ? "pago a GDA" : "pago a Vitau"}`;

	return (
		<VendorPaymentLayout
			heading={heading}
			purchases={purchases}
			selectedPurchasesDetails={selectedPurchasesDetails}
			selectedPurchasesIds={selectedPurchasesIds}
			setSelectedPurchasesIds={setSelectedPurchasesIds}
			filters={filters}
			vendorPayment={vendorPayment}
		></VendorPaymentLayout>
	);
}
