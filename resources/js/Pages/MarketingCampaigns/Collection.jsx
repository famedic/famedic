import { InformationCircleIcon } from "@heroicons/react/20/solid";
import FamedicLayout from "@/Layouts/FamedicLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import Card from "@/Components/Card";
import EmptyListCard from "@/Components/EmptyListCard";

function CollectionProductCard({ product }) {
	const discountPercentage =
		product.public_price_cents > 0
			? Math.round(
					((product.public_price_cents - product.famedic_price_cents) /
						product.public_price_cents) *
						100,
				)
			: 0;

	return (
		<Card className="flex h-full flex-col justify-between gap-4 p-5">
			<div className="space-y-2">
				<Subheading>{product.name}</Subheading>
				{product.other_name && (
					<Text className="text-sm text-zinc-500">
						{product.other_name}
					</Text>
				)}
				<div className="flex flex-wrap gap-2">
					{product.category && (
						<Badge color="zinc">{product.category}</Badge>
					)}
					{product.requires_appointment && (
						<Badge color="sky">
							<InformationCircleIcon className="size-4" />
							Requiere cita
						</Badge>
					)}
				</div>
			</div>
			<div className="space-y-3">
				<div>
					<Text>
						<Strong>{product.formatted_famedic_price}</Strong>
					</Text>
					{discountPercentage > 0 && (
						<Text className="text-sm text-zinc-500 line-through">
							{product.formatted_public_price}
						</Text>
					)}
				</div>
				<Button href={product.product_url} color="lime" className="w-full">
					Ver estudio
				</Button>
			</div>
		</Card>
	);
}

export default function MarketingCampaignCollection({
	campaign_name,
	public_title,
	public_description,
	brand,
	products = [],
	catalog_url,
	brand_selection_url,
	add_all_available = false,
}) {
	return (
		<FamedicLayout title={public_title || "Colección"}>
			<div className="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
				<div className="space-y-3">
					{campaign_name && (
						<Text className="text-sm text-zinc-500">
							{campaign_name}
						</Text>
					)}
					<Heading>{public_title}</Heading>
					{public_description && (
						<Text className="max-w-3xl text-zinc-600 dark:text-zinc-400">
							{public_description}
						</Text>
					)}
					<div className="flex flex-wrap items-center gap-3">
						{brand?.label && (
							<Badge color="sky">{brand.label}</Badge>
						)}
						<Button href={catalog_url} outline>
							Ver catálogo {brand?.label || ""}
						</Button>
						<Button href={brand_selection_url} plain>
							Ver estudios disponibles
						</Button>
					</div>
				</div>

				<section className="space-y-4">
					<div className="flex flex-wrap items-end justify-between gap-3">
						<Subheading>Estudios incluidos</Subheading>
						{!add_all_available && (
							<Text className="text-sm text-zinc-500">
								“Agregar todos” estará disponible próximamente.
							</Text>
						)}
					</div>

					{products.length === 0 ? (
						<EmptyListCard
							heading="Colección vacía"
							message="Por ahora no hay estudios en esta colección. Puedes explorar el catálogo de la marca."
						/>
					) : (
						<div className="grid gap-6 md:grid-cols-2 lg:gap-8 xl:grid-cols-3">
							{products.map((product) => (
								<CollectionProductCard
									key={product.id}
									product={product}
								/>
							))}
						</div>
					)}
				</section>
			</div>
		</FamedicLayout>
	);
}
