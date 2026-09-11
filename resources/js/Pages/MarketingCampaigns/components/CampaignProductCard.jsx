import { useState } from "react";
import { InformationCircleIcon, ChevronDownIcon } from "@heroicons/react/20/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";

function ProductDetailRows({ product }) {
	const rows = [
		["Descripción", product.description],
		["Uso común", product.common_use],
		["Indicaciones", product.indications],
		["Elementos", product.elements],
	].filter(([, value]) => Boolean(value));
	const features = Array.isArray(product.feature_list) ? product.feature_list.filter(Boolean) : [];

	if (rows.length === 0 && features.length === 0) {
		return <Text className="text-sm text-zinc-500">No hay información adicional disponible.</Text>;
	}

	return (
		<div className="space-y-3 text-sm text-zinc-600 dark:text-zinc-400">
			{rows.map(([label, value]) => (
				<div key={label}>
					<Text className="font-medium text-zinc-800 dark:text-zinc-200">{label}</Text>
					<Text className="mt-1 whitespace-pre-line">{value}</Text>
				</div>
			))}
			{features.length > 0 && (
				<div>
					<Text className="font-medium text-zinc-800 dark:text-zinc-200">Características</Text>
					<ul className="mt-1 list-disc space-y-1 pl-5">
						{features.map((feature) => <li key={feature}>{feature}</li>)}
					</ul>
				</div>
			)}
		</div>
	);
}

export default function CampaignProductCard({
	product,
	showPrices,
	cart,
	canAddToCart,
	isInCart,
	onAdd,
	adding,
	cartMessage,
	compact = false,
	actionButtonProps,
}) {
	const [expanded, setExpanded] = useState(false);
	const requiresAuth = Boolean(cart?.requires_auth);
	const getActionButtonProps = actionButtonProps ?? ((href, extraProps = {}) => ({ href, ...extraProps }));

	return (
		<Card className={`flex h-full flex-col justify-between gap-4 ${compact ? "p-4" : "p-5"}`}>
			<div className="space-y-2">
				<Subheading>{product.name}</Subheading>
				{product.other_name && <Text className="text-sm text-zinc-500">{product.other_name}</Text>}
				{product.short_description && (
					<Text className="line-clamp-3 text-sm text-zinc-600 dark:text-zinc-400">
						{product.short_description}
					</Text>
				)}
				<div className="flex flex-wrap gap-2">
					{product.category && <Badge color="zinc">{product.category}</Badge>}
					{product.requires_appointment && (
						<Badge color="sky">
							<InformationCircleIcon className="size-4" />
							Requiere cita
						</Badge>
					)}
				</div>
			</div>
			<div className="space-y-3">
				{showPrices && (
					<div>
						<Text><Strong>{product.formatted_famedic_price}</Strong></Text>
						{product.public_price_cents > product.famedic_price_cents && (
							<Text className="text-sm text-zinc-500 line-through">{product.formatted_public_price}</Text>
						)}
					</div>
				)}
				<details
					open={expanded}
					onToggle={(event) => setExpanded(event.target.open)}
					className="rounded-lg border border-zinc-200 dark:border-zinc-700"
				>
					<summary className="flex min-h-11 cursor-pointer list-none items-center justify-between px-3 py-2 text-sm font-medium">
						Más información
						<ChevronDownIcon className={`size-4 transition ${expanded ? "rotate-180" : ""}`} />
					</summary>
					<div className="border-t border-zinc-200 px-3 py-3 dark:border-zinc-700">
						<ProductDetailRows product={product} />
					</div>
				</details>
				{canAddToCart && (
					<div className="space-y-2">
						{requiresAuth ? (
							<Button {...getActionButtonProps(cart.login_url, { outline: true, className: "w-full" })}>
								Inicia sesión para agregar
							</Button>
						) : (
							<Button color="lime" className="w-full" disabled={adding} onClick={() => onAdd(product)}>
								{adding ? "Agregando..." : isInCart ? "Ya está en tu carrito" : "Agregar"}
							</Button>
						)}
						{cartMessage && <Text className="text-sm text-zinc-600 dark:text-zinc-400">{cartMessage}</Text>}
						{!requiresAuth && isInCart && product.brand && (
							<Button
								{...getActionButtonProps(route("laboratory.shopping-cart", { laboratory_brand: product.brand }), {
									outline: true,
									className: "w-full",
								})}
							>
								Ver carrito
							</Button>
						)}
					</div>
				)}
				<Button {...getActionButtonProps(product.detail_url, { outline: true, className: "w-full" })}>
					Ver estudio
				</Button>
			</div>
		</Card>
	);
}
