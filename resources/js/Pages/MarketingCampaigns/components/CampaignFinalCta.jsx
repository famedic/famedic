import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

export default function CampaignFinalCta({ catalogUrl, brandStoresUrl, primaryAction, actionButtonProps }) {
	if (!catalogUrl && !brandStoresUrl) return null;

	return (
		<section className="rounded-lg bg-zinc-50 px-6 py-8 text-center dark:bg-zinc-900">
			<Heading level={3}>Listo para continuar</Heading>
			<Text className="mx-auto mt-3 max-w-2xl text-zinc-600 dark:text-zinc-400">
				Explora el catálogo completo o visita una sucursal para más información.
			</Text>
			<div className="mt-6 flex flex-wrap justify-center gap-3">
				{catalogUrl && (
					<Button {...actionButtonProps(catalogUrl, { color: "lime" })}>
						{primaryAction?.label || "Ver estudios de la marca"}
					</Button>
				)}
				{brandStoresUrl && (
					<Button {...actionButtonProps(brandStoresUrl, { outline: true })}>Consultar sucursales</Button>
				)}
			</div>
		</section>
	);
}
