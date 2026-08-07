import { useMemo, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { InformationCircleIcon, ChevronDownIcon } from "@heroicons/react/20/solid";
import FamedicLayout from "@/Layouts/FamedicLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import Card from "@/Components/Card";
import EmptyListCard from "@/Components/EmptyListCard";

function formatDate(value) {
	if (!value) return null;
	try {
		return new Date(value).toLocaleDateString("es-MX", {
			year: "numeric",
			month: "long",
			day: "numeric",
		});
	} catch {
		return null;
	}
}

function BrandLogo({ brand, showLogo }) {
	const [failed, setFailed] = useState(false);
	const logoUrl = brand?.logo_url;

	if (!showLogo || !brand) return null;

	if (!logoUrl || failed) {
		return (
			<div className="inline-flex h-12 items-center rounded-lg bg-zinc-100 px-4 dark:bg-zinc-800">
				<Text className="font-semibold">{brand.label || "Marca"}</Text>
			</div>
		);
	}

	return (
		<img
			src={logoUrl}
			alt={brand.label ? `Logo ${brand.label}` : "Logo de marca"}
			className="h-12 w-auto object-contain"
			onError={() => setFailed(true)}
		/>
	);
}

function ProductDetailRows({ product }) {
	const rows = [
		["Descripción", product.description],
		["Uso común", product.common_use],
		["Indicaciones", product.indications],
		["Elementos", product.elements],
	].filter(([, value]) => Boolean(value));

	const features = Array.isArray(product.feature_list)
		? product.feature_list.filter(Boolean)
		: [];

	if (rows.length === 0 && features.length === 0) {
		return (
			<Text className="text-sm text-zinc-500">
				No hay información adicional disponible.
			</Text>
		);
	}

	return (
		<div className="space-y-3 text-sm text-zinc-600 dark:text-zinc-400">
			{rows.map(([label, value]) => (
				<div key={label}>
					<Text className="font-medium text-zinc-800 dark:text-zinc-200">
						{label}
					</Text>
					<Text className="mt-1 whitespace-pre-line">{value}</Text>
				</div>
			))}
			{features.length > 0 && (
				<div>
					<Text className="font-medium text-zinc-800 dark:text-zinc-200">
						Características
					</Text>
					<ul className="mt-1 list-disc space-y-1 pl-5">
						{features.map((feature) => (
							<li key={feature}>{feature}</li>
						))}
					</ul>
				</div>
			)}
		</div>
	);
}

function LandingProductCard({
	product,
	showPrices,
	cart,
	canAddToCart,
	isInCart,
	onAdd,
	adding,
	cartMessage,
}) {
	const [expanded, setExpanded] = useState(false);
	const requiresAuth = Boolean(cart?.requires_auth);

	return (
		<Card className="flex h-full flex-col justify-between gap-4 p-5">
			<div className="space-y-2">
				<Subheading>{product.name}</Subheading>
				{product.other_name && (
					<Text className="text-sm text-zinc-500">
						{product.other_name}
					</Text>
				)}
				{product.short_description && (
					<Text className="line-clamp-3 text-sm text-zinc-600 dark:text-zinc-400">
						{product.short_description}
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
				{showPrices && (
					<div>
						<Text>
							<Strong>{product.formatted_famedic_price}</Strong>
						</Text>
						{product.public_price_cents >
							product.famedic_price_cents && (
							<Text className="text-sm text-zinc-500 line-through">
								{product.formatted_public_price}
							</Text>
						)}
					</div>
				)}

				<details
					open={expanded}
					onToggle={(event) => setExpanded(event.target.open)}
					className="rounded-lg border border-zinc-200 dark:border-zinc-700"
				>
					<summary className="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-sm font-medium">
						Más información
						<ChevronDownIcon
							className={`size-4 transition ${expanded ? "rotate-180" : ""}`}
						/>
					</summary>
					<div className="border-t border-zinc-200 px-3 py-3 dark:border-zinc-700">
						<ProductDetailRows product={product} />
					</div>
				</details>

				{canAddToCart && (
					<div className="space-y-2">
						{requiresAuth ? (
							<Button
								href={cart.login_url}
								outline
								className="w-full"
							>
								Inicia sesión para agregar
							</Button>
						) : (
							<Button
								color="lime"
								className="w-full"
								disabled={adding}
								onClick={() => onAdd(product)}
							>
								{adding
									? "Agregando…"
									: isInCart
										? "Ya está en tu carrito"
										: "Agregar"}
							</Button>
						)}
						{cartMessage && (
							<Text className="text-sm text-zinc-600 dark:text-zinc-400">
								{cartMessage}
							</Text>
						)}
						{!requiresAuth && isInCart && product.brand && (
							<Button
								href={route("laboratory.shopping-cart", {
									laboratory_brand: product.brand,
								})}
								outline
								className="w-full"
							>
								Ver carrito
							</Button>
						)}
					</div>
				)}

				<Button href={product.detail_url} outline className="w-full">
					Ver estudio
				</Button>
			</div>
		</Card>
	);
}

export default function MarketingCampaignLanding({
	campaign,
	content,
	brand,
	category,
	products = [],
	related_products = [],
	related_categories = [],
	stores_url,
	primary_action,
	secondary_action,
	cart,
	can_add_to_cart = false,
	empty_message,
}) {
	const { props } = usePage();
	const laboratoryCarts = props.laboratoryCarts || {};
	const [addingId, setAddingId] = useState(null);
	const [cartMessages, setCartMessages] = useState({});

	const starts = formatDate(campaign?.starts_at);
	const ends = formatDate(campaign?.ends_at);
	const catalogUrl = brand?.catalog_url || primary_action?.url;
	const brandStoresUrl = brand?.stores_url || stores_url;

	const cartTestIdsByBrand = useMemo(() => {
		const map = {};
		Object.entries(laboratoryCarts).forEach(([brandKey, items]) => {
			map[brandKey] = new Set(
				(items || []).map((item) =>
					String(item.laboratory_test_id ?? item.laboratoryTest?.id),
				),
			);
		});
		return map;
	}, [laboratoryCarts]);

	const isProductInCart = (product) => {
		const brandKey = product.brand;
		if (!brandKey) return false;
		return cartTestIdsByBrand[brandKey]?.has(String(product.id)) || false;
	};

	const handleAddToCart = (product) => {
		if (!cart?.add_url || cart?.requires_auth || !product.brand) {
			return;
		}

		if (isProductInCart(product)) {
			setCartMessages((current) => ({
				...current,
				[product.id]: "Este estudio ya está en tu carrito.",
			}));
			return;
		}

		setAddingId(product.id);
		setCartMessages((current) => ({ ...current, [product.id]: null }));

		router.post(
			cart.add_url,
			{
				laboratory_test: product.id,
				laboratory_brand: product.brand,
			},
			{
				preserveScroll: true,
				onSuccess: () => {
					setCartMessages((current) => ({
						...current,
						[product.id]: "Estudio agregado a tu carrito.",
					}));
				},
				onError: (errors) => {
					setCartMessages((current) => ({
						...current,
						[product.id]:
							errors.laboratory_test ||
							errors.laboratory_brand ||
							"No se pudo agregar el estudio.",
					}));
				},
				onFinish: () => setAddingId(null),
			},
		);
	};

	const renderProductGrid = (items, heading) => (
		<section className="space-y-4">
			<Subheading>{heading}</Subheading>
			{items.length === 0 ? null : (
				<div className="grid gap-6 md:grid-cols-2 lg:gap-8 xl:grid-cols-3">
					{items.map((product) => (
						<LandingProductCard
							key={`${heading}-${product.id}`}
							product={product}
							showPrices={Boolean(content?.show_prices)}
							cart={cart}
							canAddToCart={can_add_to_cart}
							isInCart={isProductInCart(product)}
							onAdd={handleAddToCart}
							adding={addingId === product.id}
							cartMessage={cartMessages[product.id]}
						/>
					))}
				</div>
			)}
		</section>
	);

	return (
		<FamedicLayout title={content?.title || "Campaña"}>
			<div className="mx-auto max-w-7xl space-y-12 px-4 py-8 sm:px-6 lg:px-8">
				<section className="grid gap-8 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
					<div className="space-y-4">
						{content?.show_brand_logo && brand && (
							<BrandLogo
								brand={brand}
								showLogo={Boolean(content?.show_brand_logo)}
							/>
						)}
						{content?.eyebrow && (
							<Badge color="lime">{content.eyebrow}</Badge>
						)}
						<Heading>{content?.title}</Heading>
						{content?.subtitle && (
							<Subheading className="text-zinc-600 dark:text-zinc-300">
								{content.subtitle}
							</Subheading>
						)}
						{content?.description && (
							<Text className="max-w-2xl whitespace-pre-line text-zinc-600 dark:text-zinc-400">
								{content.description}
							</Text>
						)}
						{content?.show_campaign_dates && (starts || ends) && (
							<Text className="text-sm text-zinc-500">
								Vigencia
								{starts ? `: desde ${starts}` : ""}
								{ends ? ` hasta ${ends}` : ""}
							</Text>
						)}
						{category?.name && (
							<Badge color="sky">{category.name}</Badge>
						)}

						{brand && (
							<div className="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
								<div className="flex flex-wrap items-center gap-4">
									<BrandLogo
										brand={brand}
										showLogo={content?.show_brand_logo}
									/>
									<div>
										<Text className="font-semibold">
											{brand.label}
										</Text>
										{Array.isArray(brand.states) &&
											brand.states.length > 0 && (
												<Text className="mt-1 text-sm text-zinc-500">
													Disponible en:{" "}
													{brand.states.join(", ")}
												</Text>
											)}
									</div>
								</div>
								<div className="mt-4 flex flex-wrap gap-3">
									{catalogUrl && (
										<Button href={catalogUrl} color="lime">
											Ver estudios de la marca
										</Button>
									)}
									{brandStoresUrl && (
										<Button href={brandStoresUrl} outline>
											Consultar sucursales
										</Button>
									)}
								</div>
							</div>
						)}

						<div className="flex flex-wrap gap-3 pt-2">
							{primary_action?.url && (
								<Button href={primary_action.url} color="lime">
									{primary_action.label}
								</Button>
							)}
							{secondary_action?.url && (
								<Button href={secondary_action.url} outline>
									{secondary_action.label}
								</Button>
							)}
						</div>
					</div>

					{content?.hero_image ? (
						<div className="overflow-hidden rounded-2xl bg-zinc-100 dark:bg-zinc-800">
							<img
								src={content.hero_image}
								alt={content.hero_image_alt || content.title || ""}
								className="h-full max-h-96 w-full object-cover"
							/>
						</div>
					) : (
						<div className="hidden min-h-64 rounded-2xl bg-gradient-to-br from-famedic-lime/30 to-sky-100 dark:from-zinc-800 dark:to-zinc-700 lg:block" />
					)}
				</section>

				<section className="space-y-4">
					<Subheading>Estudios destacados</Subheading>
					{products.length === 0 ? (
						<EmptyListCard
							heading="Sin estudios"
							message={
								empty_message ||
								"No hay estudios disponibles en esta campaña por el momento."
							}
						/>
					) : (
						<div className="grid gap-6 md:grid-cols-2 lg:gap-8 xl:grid-cols-3">
							{products.map((product) => (
								<LandingProductCard
									key={product.id}
									product={product}
									showPrices={Boolean(content?.show_prices)}
									cart={cart}
									canAddToCart={can_add_to_cart}
									isInCart={isProductInCart(product)}
									onAdd={handleAddToCart}
									adding={addingId === product.id}
									cartMessage={cartMessages[product.id]}
								/>
							))}
						</div>
					)}
				</section>

				{related_products.length > 0 &&
					renderProductGrid(
						related_products,
						"También te puede interesar",
					)}

				{related_categories.length > 0 && (
					<section className="space-y-4">
						<Subheading>Categorías relacionadas</Subheading>
						<div className="flex flex-wrap gap-3">
							{related_categories.map((item) => (
								<Button key={item.url} href={item.url} outline>
									{item.name}
								</Button>
							))}
						</div>
					</section>
				)}

				{content?.gallery?.length > 0 && (
					<section className="space-y-4">
						<Subheading>Galería</Subheading>
						<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
							{content.gallery.map((image, index) => (
								<div
									key={`${image.url}-${index}`}
									className="overflow-hidden rounded-2xl bg-zinc-100 dark:bg-zinc-800"
								>
									<img
										src={image.url}
										alt={image.alt || ""}
										className="aspect-[4/3] w-full object-cover"
									/>
								</div>
							))}
						</div>
					</section>
				)}

				{(primary_action?.url || catalogUrl) && (
					<section className="rounded-2xl bg-zinc-50 px-6 py-8 text-center dark:bg-zinc-900">
						<Heading level={3}>¿Listo para continuar?</Heading>
						<Text className="mx-auto mt-3 max-w-2xl text-zinc-600 dark:text-zinc-400">
							Explora el catálogo completo o visita una sucursal
							para más información.
						</Text>
						<div className="mt-6 flex flex-wrap justify-center gap-3">
							{catalogUrl && (
								<Button href={catalogUrl} color="lime">
									{primary_action?.label ||
										"Ver estudios de la marca"}
								</Button>
							)}
							{brandStoresUrl && (
								<Button href={brandStoresUrl} outline>
									Consultar sucursales
								</Button>
							)}
						</div>
					</section>
				)}
			</div>
		</FamedicLayout>
	);
}
