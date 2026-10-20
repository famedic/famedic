import { useState } from "react";
import {
	BeakerIcon,
	BoltIcon,
	HeartIcon,
	InformationCircleIcon,
	MagnifyingGlassCircleIcon,
	SignalIcon,
	SparklesIcon,
	ViewfinderCircleIcon,
} from "@heroicons/react/20/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";

function discountPercent(product) {
	if (!product?.public_price_cents || product.public_price_cents <= product.famedic_price_cents) {
		return null;
	}

	return Math.round(((product.public_price_cents - product.famedic_price_cents) / product.public_price_cents) * 100);
}

function productCategoryIcon(category) {
	const normalized = String(category || "")
		.normalize("NFD")
		.replace(/[\u0300-\u036f]/g, "")
		.toLowerCase();

	if (normalized.includes("ultrason")) return SignalIcon;
	if (normalized.includes("resonancia")) return SparklesIcon;
	if (normalized.includes("tomograf")) return ViewfinderCircleIcon;
	if (normalized.includes("rayos") || normalized.includes("radiograf") || normalized.includes("x-ray")) return BoltIcon;
	if (normalized.includes("mastograf")) return HeartIcon;
	if (normalized.includes("cardio")) return HeartIcon;
	if (normalized.includes("analisis") || normalized.includes("clinico") || normalized.includes("laboratorio")) {
		return BeakerIcon;
	}

	return MagnifyingGlassCircleIcon;
}

function ProductVisual({ product, className = "" }) {
	const [failed, setFailed] = useState(false);
	const imageUrl =
		product.image_preview_url || product.image_url || product.image || null;
	const Icon = productCategoryIcon(product.category);

	if (imageUrl && !failed) {
		return (
			<img
				src={imageUrl}
				alt={product.image_alt || product.name || ""}
				className={`h-full w-full object-cover ${className}`}
				onError={() => setFailed(true)}
			/>
		);
	}

	return (
		<div
			className={`flex h-full w-full items-center justify-center bg-zinc-50 text-center dark:bg-zinc-800/70 ${className}`}
			aria-hidden="true"
		>
			<div className="flex size-16 items-center justify-center rounded-2xl bg-white text-famedic-dark shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-900 dark:text-lime-300 dark:ring-white/10">
				<Icon className="size-8" />
			</div>
		</div>
	);
}

const CONVERSION_HIGHLIGHTS = [
	"Más solicitado",
	"Ideal para tu salud",
	"Cuidado integral",
];

function ProductBadges({ product }) {
	return (
		<div className="flex flex-wrap gap-2">
			{product.category && <Badge color="zinc">{product.category}</Badge>}
			{product.requires_appointment && (
				<Badge color="sky">
					<InformationCircleIcon className="size-4" />
					Requiere cita
				</Badge>
			)}
		</div>
	);
}

const cardSurface =
	"bg-white text-famedic-darker shadow-sm ring-1 ring-zinc-200 dark:bg-slate-900/90 dark:text-white dark:ring-white/10";
const cardSurfaceCompact =
	"bg-white text-famedic-darker ring-1 ring-zinc-200 dark:bg-slate-900/90 dark:text-white dark:ring-white/10";
const titleClasses =
	"font-poppins font-semibold text-famedic-darker dark:text-white";
const descriptionClasses =
	"text-zinc-600 dark:text-zinc-300";
const mutedClasses =
	"text-zinc-500 dark:text-zinc-400";

function PriceBlock({ product, showPrices }) {
	const discount = discountPercent(product);

	if (!showPrices) return null;

	return (
		<div className="space-y-1">
			<Text>
				<Strong className="text-lg text-famedic-darker dark:text-white">{product.formatted_famedic_price}</Strong>
			</Text>
			{product.public_price_cents > product.famedic_price_cents && (
				<div className="flex flex-wrap items-center gap-2">
					<Text className="text-sm text-zinc-500 line-through dark:text-zinc-400">{product.formatted_public_price}</Text>
					{discount && <Badge color="lime">{discount}% menos</Badge>}
				</div>
			)}
		</div>
	);
}

function AddToCartButton({
	product,
	cart,
	canAddToCart,
	isInCart,
	onAdd,
	onRemove,
	adding,
	removing,
	actionButtonProps,
	label = "Agregar",
	className = "w-full",
}) {
	if (!canAddToCart) return null;

	const requiresAuth = Boolean(cart?.requires_auth);
	const getActionButtonProps = actionButtonProps ?? ((href, extraProps = {}) => ({ href, ...extraProps }));

	if (requiresAuth) {
		return (
			<Button {...getActionButtonProps(cart.login_url, { outline: true, className })}>
				Inicia sesión para agregar
			</Button>
		);
	}

	if (isInCart) {
		return (
			<Button
				type="button"
				outline
				className={className}
				disabled={removing}
				onClick={() => onRemove?.(product)}
			>
				{removing ? "Quitando..." : "Quitar del carrito"}
			</Button>
		);
	}

	return (
		<Button
			type="button"
			color="lime"
			className={className}
			disabled={adding}
			onClick={() => onAdd(product)}
		>
			{adding ? "Agregando..." : label}
		</Button>
	);
}

function DetailsLink({ product, actionButtonProps, className = "" }) {
	const getActionButtonProps = actionButtonProps ?? ((href, extraProps = {}) => ({ href, ...extraProps }));

	if (!product.detail_url) return null;

	return (
		<a
			{...getActionButtonProps(product.detail_url)}
			className={`text-sm font-semibold text-famedic-dark underline-offset-4 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime dark:text-lime-300 ${className}`}
		>
			Ver detalles
		</a>
	);
}

export default function CampaignProductCard({
	product,
	showPrices,
	cart,
	canAddToCart,
	isInCart,
	onAdd,
	onRemove,
	adding,
	removing,
	cartMessage,
	compact = false,
	actionButtonProps,
	variant = "conversion",
	position = 0,
}) {
	const description = product.short_description || product.description || product.common_use;

	if (variant === "editorial") {
		return (
			<article className={`flex h-full min-h-0 flex-col overflow-hidden rounded-2xl ${cardSurface}`}>
				<div className="aspect-[4/3] border-b border-zinc-100 dark:border-white/10">
					<ProductVisual product={product} />
				</div>
				<div className="flex flex-1 min-w-0 flex-col gap-4 p-5">
					<div className="min-w-0 space-y-2">
						<ProductBadges product={product} />
						<h3 className={`line-clamp-3 text-lg leading-7 ${titleClasses}`}>
							{product.name}
						</h3>
						{description && (
							<Text className={`line-clamp-3 text-sm leading-6 ${descriptionClasses}`}>{description}</Text>
						)}
					</div>
					<div className="mt-auto space-y-3">
						<PriceBlock product={product} showPrices={showPrices} />
						<AddToCartButton
							product={product}
							cart={cart}
							canAddToCart={canAddToCart}
							isInCart={isInCart}
							onAdd={onAdd}
							onRemove={onRemove}
							adding={adding}
							removing={removing}
							actionButtonProps={actionButtonProps}
							label="Agregar al carrito"
						/>
						<div className="flex items-center justify-between gap-3">
							<DetailsLink product={product} actionButtonProps={actionButtonProps} />
							{cartMessage && <Text className={`text-xs ${descriptionClasses}`}>{cartMessage}</Text>}
						</div>
					</div>
				</div>
			</article>
		);
	}

	if (variant === "conversion") {
		return (
			<article
				className={`flex h-full min-h-0 flex-col rounded-2xl p-5 ${
					compact ? cardSurfaceCompact : cardSurface
				}`}
			>
				<div className="flex items-start justify-between gap-4">
					<Badge color={position === 0 ? "sky" : "lime"}>
						{CONVERSION_HIGHLIGHTS[position % CONVERSION_HIGHLIGHTS.length]}
					</Badge>
					<div className="size-16 shrink-0 overflow-hidden rounded-2xl bg-zinc-50 ring-1 ring-zinc-200 dark:bg-zinc-800/70 dark:ring-white/10">
						<ProductVisual product={product} />
					</div>
				</div>
				<div className="mt-4 flex flex-1 flex-col gap-4">
					<div className="min-w-0 space-y-3">
						<h3 className={`line-clamp-3 text-lg leading-7 ${titleClasses}`}>
							{product.name}
						</h3>
						{product.other_name && <Text className={`text-sm ${mutedClasses}`}>{product.other_name}</Text>}
						{description && (
							<Text className={`line-clamp-3 text-sm leading-6 ${descriptionClasses}`}>{description}</Text>
						)}
						<ProductBadges product={product} />
					</div>
					<div className="mt-auto space-y-3 pt-1">
						<PriceBlock product={product} showPrices={showPrices} />
						<AddToCartButton
							product={product}
							cart={cart}
							canAddToCart={canAddToCart}
							isInCart={isInCart}
							onAdd={onAdd}
							onRemove={onRemove}
							adding={adding}
							removing={removing}
							actionButtonProps={actionButtonProps}
							label="Agregar"
						/>
						{cartMessage && <Text className={`text-sm ${descriptionClasses}`}>{cartMessage}</Text>}
						<DetailsLink product={product} actionButtonProps={actionButtonProps} className="inline-flex" />
					</div>
				</div>
			</article>
		);
	}

	if (variant === "catalog") {
		return (
			<article
				className={`flex h-full min-h-0 flex-col overflow-hidden rounded-2xl ${
					compact ? cardSurfaceCompact : cardSurface
				}`}
			>
				<div className="aspect-[16/9] border-b border-zinc-100 dark:border-white/10">
					<ProductVisual product={product} />
				</div>
				<div className="flex flex-1 flex-col gap-3 p-4">
					<div className="min-w-0 space-y-2">
						<h3 className={`line-clamp-2 text-base leading-6 ${titleClasses}`}>
							{product.name}
						</h3>
						{product.other_name && <Text className={`line-clamp-1 text-sm ${mutedClasses}`}>{product.other_name}</Text>}
						{description && (
							<Text className={`line-clamp-2 text-sm leading-6 ${descriptionClasses}`}>{description}</Text>
						)}
						<ProductBadges product={product} />
					</div>
					<div className="mt-auto space-y-3 pt-1">
						<PriceBlock product={product} showPrices={showPrices} />
						<AddToCartButton
							product={product}
							cart={cart}
							canAddToCart={canAddToCart}
							isInCart={isInCart}
							onAdd={onAdd}
							onRemove={onRemove}
							adding={adding}
							removing={removing}
							actionButtonProps={actionButtonProps}
							label="Agregar"
						/>
						{cartMessage && <Text className={`text-sm ${descriptionClasses}`}>{cartMessage}</Text>}
						<DetailsLink product={product} actionButtonProps={actionButtonProps} className="inline-flex" />
					</div>
				</div>
			</article>
		);
	}

	const visualHeight = "aspect-[16/10]";

	return (
		<article
			className={`flex h-full min-h-0 flex-col overflow-hidden rounded-2xl ${
				compact ? cardSurfaceCompact : cardSurface
			}`}
		>
			<div className={`${visualHeight} border-b border-zinc-100 dark:border-white/10`}>
				<ProductVisual product={product} />
			</div>
			<div className="flex flex-1 flex-col gap-4 p-5">
				<div className="min-w-0 space-y-3">
					<ProductBadges product={product} />
					<h3 className={`line-clamp-3 text-lg leading-7 ${titleClasses}`}>
						{product.name}
					</h3>
					{product.other_name && <Text className={`text-sm ${mutedClasses}`}>{product.other_name}</Text>}
					{description && (
						<Text className={`line-clamp-3 text-sm leading-6 ${descriptionClasses}`}>{description}</Text>
					)}
				</div>
				<div className="mt-auto space-y-3">
					<PriceBlock product={product} showPrices={showPrices} />
					<AddToCartButton
						product={product}
						cart={cart}
						canAddToCart={canAddToCart}
						isInCart={isInCart}
						onAdd={onAdd}
						onRemove={onRemove}
						adding={adding}
						removing={removing}
						actionButtonProps={actionButtonProps}
						label="Agregar"
					/>
					{cartMessage && <Text className={`text-sm ${descriptionClasses}`}>{cartMessage}</Text>}
					<DetailsLink product={product} actionButtonProps={actionButtonProps} className="inline-flex" />
				</div>
			</div>
		</article>
	);
}
