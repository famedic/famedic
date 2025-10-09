export default function LaboratoryPurchase({ laboratoryPurchase }) {
	return (
		<SettingsLayout title="Pedido de laboratorio">
			<Card className="space-y-8 p-6 lg:space-y-10 lg:p-12">
				<Purchase purchase={laboratoryPurchase} isLabPurchase={true} />
			</Card>
		</SettingsLayout>
	);
}

import SettingsLayout from "@/Layouts/SettingsLayout";
import Purchase from "@/Components/Purchase";
import Card from "@/Components/Card";
