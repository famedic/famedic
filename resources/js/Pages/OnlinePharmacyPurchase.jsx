export default function OnlinePharmacyPurchase({ onlinePharmacyPurchase }) {
	return (
		<SettingsLayout title="Pedido de farmacia">
			<Card className="space-y-8 p-6 lg:space-y-10 lg:p-12">
				<Purchase purchase={onlinePharmacyPurchase} />
			</Card>
		</SettingsLayout>
	);
}

import SettingsLayout from "@/Layouts/SettingsLayout";
import Purchase from "@/Components/Purchase";
import Card from "@/Components/Card";
