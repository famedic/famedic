import { useEffect, useRef } from "react";
import { useForm } from "@inertiajs/react";
import VendorPaymentDialog from "@/Components/VendorPaymentDialog";
import VendorPaymentPurchaseCard from "@/Components/VendorPaymentPurchaseCard";
import PaginatedTable from "./Admin/PaginatedTable";
import EmptyListCard from "./EmptyListCard";

export default function VendorPaymentForm({
	vendorPayment,
	purchases,
	selectedPurchasesIds,
	setSelectedPurchasesIds,
	showDialog,
	setShowDialog,
	selectedPurchasesDetails,
}) {
	const currentRoute = route().current();
	const editMode = currentRoute.includes(".edit");
	const isLaboratory = currentRoute.includes("laboratory-purchases");

	const selectedPurchasesIdsRef = useRef(selectedPurchasesIds);

	useEffect(() => {
		selectedPurchasesIdsRef.current = selectedPurchasesIds;
	}, [selectedPurchasesIds]);

	const { data, transform, setData, post, processing, errors } = useForm({
		paid_at: vendorPayment?.paid_at_string || "",
		proof: null,
		purchase_ids: selectedPurchasesIds,
	});

	transform((data) => ({
		...data,
		...(editMode && { _method: "put" }),
		purchase_ids: selectedPurchasesIdsRef.current,
	}));

	const proofRoute = vendorPayment
		? route("vendor-payment", vendorPayment)
		: null;

	const getStoreRoute = () => {
		const prefix = isLaboratory
			? "laboratory-purchases"
			: "online-pharmacy-purchases";
		return route(`admin.${prefix}.vendor-payments.store`);
	};

	const getUpdateRoute = () => {
		const prefix = isLaboratory
			? "laboratory-purchases"
			: "online-pharmacy-purchases";
		return route(
			`admin.${prefix}.vendor-payments.update`,
			vendorPayment.id,
		);
	};

	const handleConfirm = () => {
		if (editMode) {
			post(getUpdateRoute(), {
				onSuccess: () => {
					setShowDialog(false);
				},
			});
		} else {
			post(getStoreRoute(), {
				onSuccess: () => {
					setShowDialog(false);
				},
			});
		}
	};

	return (
		<>
			{purchases.data && purchases.data.length > 0 ? (
				<PaginatedTable paginatedData={purchases}>
					{purchases.data.map((purchase) => (
						<VendorPaymentPurchaseCard
							key={purchase.id}
							purchase={purchase}
							isSelected={selectedPurchasesIds.includes(
								purchase.id,
							)}
							onToggle={() => {
								if (
									selectedPurchasesIds.includes(purchase.id)
								) {
									setSelectedPurchasesIds(
										selectedPurchasesIds.filter(
											(id) => id !== purchase.id,
										),
									);
								} else {
									setSelectedPurchasesIds([
										...selectedPurchasesIds,
										purchase.id,
									]);
								}
							}}
						/>
					))}
				</PaginatedTable>
			) : (
				<EmptyListCard />
			)}

			<VendorPaymentDialog
				open={showDialog}
				onClose={() => setShowDialog(false)}
				selectedPurchases={
					selectedPurchasesDetails?.selectedPurchases || []
				}
				paidAt={data.paid_at}
				onPaidAtChange={(value) => setData("paid_at", value)}
				proof={data.proof}
				onProofChange={(file) => {
					setData("proof", file);
				}}
				onConfirm={handleConfirm}
				errors={errors}
				processing={processing}
				vendorPayment={vendorPayment}
				proofRoute={proofRoute}
				formattedSubtotal={selectedPurchasesDetails?.formattedSubtotal}
				formattedCommission={
					selectedPurchasesDetails?.formattedCommission
				}
				formattedTotal={selectedPurchasesDetails?.formattedTotal}
			/>
		</>
	);
}
