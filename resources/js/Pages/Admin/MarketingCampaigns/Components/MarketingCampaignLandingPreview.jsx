import { useState } from "react";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import Card from "@/Components/Card";

function BrandLogo({ brand, showLogo }) {
	const [failed, setFailed] = useState(false);
	const logoUrl = brand?.logo_url;

	if (!showLogo || !brand) return null;

	if (!logoUrl || failed) {
		return (
			<div className="inline-flex h-10 items-center rounded-lg bg-zinc-100 px-3 dark:bg-zinc-800">
				<Text className="text-sm font-semibold">
					{brand.label || "Marca"}
				</Text>
			</div>
		);
	}

	return (
		<img
			src={logoUrl}
			alt={brand.label ? `Logo ${brand.label}` : "Logo"}
			className="h-10 w-auto object-contain"
			onError={() => setFailed(true)}
		/>
	);
}

function HeroBlock({ heroUrl, alt, title }) {
	if (!heroUrl) {
		return (
			<div className="flex aspect-[21/9] items-center justify-center rounded-xl bg-gradient-to-br from-zinc-100 to-zinc-200 dark:from-zinc-800 dark:to-zinc-900">
				<Text className="text-sm text-zinc-500">
					Vista previa de imagen principal
				</Text>
			</div>
		);
	}

	return (
		<img
			src={heroUrl}
			alt={alt || title || "Imagen principal"}
			className="aspect-[21/9] w-full rounded-xl object-cover"
		/>
	);
}

export default function MarketingCampaignLandingPreview({
	content = {},
	brand = null,
	products = [],
	relatedProducts = [],
	gallery = [],
	primaryAction = {},
	secondaryAction = null,
	showPrices = true,
	showLogo = true,
}) {
	const [viewport, setViewport] = useState("desktop");

	const frameClass =
		viewport === "mobile"
			? "mx-auto max-w-sm rounded-[1.75rem] border-8 border-zinc-900 shadow-xl"
			: "rounded-xl border border-zinc-200 shadow-sm dark:border-zinc-700";

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					Vista previa aproximada de la landing pública.
				</Text>
				<div className="flex gap-2">
					<Button
						type="button"
						outline={viewport !== "desktop"}
						onClick={() => setViewport("desktop")}
					>
						Escritorio
					</Button>
					<Button
						type="button"
						outline={viewport !== "mobile"}
						onClick={() => setViewport("mobile")}
					>
						Móvil
					</Button>
				</div>
			</div>

			<div className={frameClass}>
				<div className="space-y-6 bg-white p-5 dark:bg-zinc-950">
					<HeroBlock
						heroUrl={content.hero_url}
						alt={content.hero_alt}
						title={content.public_title}
					/>

					<div className="space-y-3">
						<BrandLogo brand={brand} showLogo={showLogo} />
						{content.eyebrow && (
							<Text className="text-xs font-semibold uppercase tracking-wide text-famedic-dark dark:text-famedic-light">
								{content.eyebrow}
							</Text>
						)}
						<Heading>{content.public_title || "Título de campaña"}</Heading>
						{content.public_subtitle && (
							<Subheading>{content.public_subtitle}</Subheading>
						)}
						{content.public_description && (
							<Text className="text-zinc-600 dark:text-zinc-400">
								{content.public_description}
							</Text>
						)}
						<div className="flex flex-wrap gap-2">
							{primaryAction?.label && (
								<Badge color="lime">{primaryAction.label}</Badge>
							)}
							{secondaryAction?.label && (
								<Badge color="zinc">{secondaryAction.label}</Badge>
							)}
						</div>
					</div>

					<div className="space-y-3">
						<Subheading>Productos principales</Subheading>
						{products.length === 0 ? (
							<Text className="text-sm text-zinc-500">
								Los productos se resolverán según el destino
								configurado.
							</Text>
						) : (
							<div className="grid gap-3 sm:grid-cols-2">
								{products.slice(0, 4).map((product) => (
									<Card key={product.id} className="p-4">
										<Strong>{product.name}</Strong>
										{showPrices && product.price_label && (
											<Text className="mt-1 text-sm text-zinc-500">
												{product.price_label}
											</Text>
										)}
									</Card>
								))}
							</div>
						)}
					</div>

					{relatedProducts.length > 0 && (
						<div className="space-y-2">
							<Subheading>Relacionados</Subheading>
							<div className="flex flex-wrap gap-2">
								{relatedProducts.slice(0, 6).map((product) => (
									<Badge key={product.id} color="zinc">
										{product.name}
									</Badge>
								))}
							</div>
						</div>
					)}

					{gallery.length > 0 && (
						<div className="grid grid-cols-3 gap-2">
							{gallery.slice(0, 6).map((item, index) => (
								<div
									key={item.key || item.url || index}
									className="aspect-square overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800"
								>
									{item.url ? (
										<img
											src={item.url}
											alt={item.alt || ""}
											className="size-full object-cover"
										/>
									) : null}
								</div>
							))}
						</div>
					)}
				</div>
			</div>
		</div>
	);
}
