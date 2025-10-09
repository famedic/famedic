import { ArrowRightIcon } from "@heroicons/react/20/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import Card from "@/Components/Card";

export default function VendorPaymentBanner({
	selectedPurchasesDetails,
	onContinue,
}) {
	if (selectedPurchasesDetails.selectedPurchases.length === 0) {
		return null;
	}

	return (
		<div className="sticky bottom-0 mt-8 flex justify-center">
			<Card
				onClick={onContinue}
				hoverable
				className="w-full !bg-famedic-lime/85 p-3"
			>
				<div className="space-y-3">
					<p className="text-sm font-semibold leading-6 text-famedic-darker">
						{selectedPurchasesDetails.selectedPurchases.length}{" "}
						{selectedPurchasesDetails.selectedPurchases.length === 1
							? "orden seleccionada"
							: "órdenes seleccionadas"}
					</p>
					<div className="flex flex-wrap gap-1">
						{selectedPurchasesDetails.selectedPurchases.map(
							(purchase) => (
								<Badge color="famedic-dark" key={purchase.id}>
									{purchase.gda_order_id ||
										purchase.vitau_order_id}
								</Badge>
							),
						)}
					</div>
				</div>
				<div className="mt-2 flex w-full justify-end">
					<Button
						type="button"
						color="famedic-dark"
						onClick={onContinue}
					>
						Continuar
						<ArrowRightIcon />
					</Button>
				</div>
			</Card>
		</div>
	);
}
