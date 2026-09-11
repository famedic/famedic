import { Text } from "@/Components/Catalyst/text";

export default function CampaignTrustStrip({ brand, storesUrl, actionButtonProps }) {
	return (
		<section className="grid gap-4 rounded-lg border border-emerald-100 bg-emerald-50 px-5 py-4 dark:border-emerald-900 dark:bg-emerald-950/30 md:grid-cols-3">
			<Text className="font-medium text-emerald-950 dark:text-emerald-100">Precios Famedic claros</Text>
			<Text className="font-medium text-emerald-950 dark:text-emerald-100">
				{brand?.label ? `Disponible con ${brand.label}` : "Laboratorios verificados"}
			</Text>
			{storesUrl ? (
				<a {...actionButtonProps(storesUrl, {})} className="font-medium text-emerald-950 underline underline-offset-4 dark:text-emerald-100">
					Consultar sucursales
				</a>
			) : (
				<Text className="font-medium text-emerald-950 dark:text-emerald-100">Compra guiada en línea</Text>
			)}
		</section>
	);
}
