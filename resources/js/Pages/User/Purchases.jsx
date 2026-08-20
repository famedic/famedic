import { GradientHeading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import SettingsLayout from "@/Layouts/SettingsLayout";
import EmptyPendingPurchases from "@/Pages/User/Components/EmptyPendingPurchases";
import PendingPurchaseCard from "@/Pages/User/Components/PendingPurchaseCard";

export default function Purchases({ pendingPurchases = [] }) {
	const purchases = Array.isArray(pendingPurchases) ? pendingPurchases : [];
	const hasPurchases = purchases.length > 0;

	return (
		<SettingsLayout title="Compras pendientes">
			<section className="space-y-4">
				<div>
					<GradientHeading noDivider>
						Recupera tu compra
					</GradientHeading>
					<Text className="mt-3 max-w-2xl">
						{hasPurchases
							? "Retoma tus compras pendientes y continúa justo donde te quedaste."
							: "Tienes productos en tus carritos guardados listos para continuar."}
					</Text>
				</div>
			</section>

			{hasPurchases ? (
				<section className="space-y-4" aria-label="Compras pendientes">
					{purchases.map((purchase, index) => (
						<PendingPurchaseCard
							key={
								purchase.key ??
								`${purchase.type ?? "purchase"}-${index}`
							}
							purchase={purchase}
						/>
					))}
				</section>
			) : (
				<EmptyPendingPurchases />
			)}
		</SettingsLayout>
	);
}
