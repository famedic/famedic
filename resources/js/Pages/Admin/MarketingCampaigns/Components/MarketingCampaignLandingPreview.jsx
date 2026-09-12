import { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { resolveLandingTemplate } from "@/Pages/MarketingCampaigns/templates";

const VIEWPORTS = {
	desktop: {
		label: "Desktop",
		width: 1440,
		height: 900,
		help: "Renderiza con viewport lógico 1440 x 900.",
	},
	mobile: {
		label: "Mobile",
		width: 390,
		height: 844,
		help: "Renderiza con viewport lógico 390 x 844.",
	},
};

function formatCents(cents) {
	if (cents == null || cents === "") {
		return null;
	}

	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
	}).format(Number(cents) / 100);
}

function formatPreviewProduct(product) {
	const famedicPriceCents = product.famedic_price_cents ?? 0;
	const publicPriceCents = product.public_price_cents ?? famedicPriceCents;
	const famedicLabel =
		product.formatted_famedic_price ||
		product.price_label ||
		formatCents(famedicPriceCents) ||
		"$0.00 MXN";
	const publicLabel =
		product.formatted_public_price ||
		formatCents(publicPriceCents) ||
		famedicLabel;

	return {
		...product,
		formatted_famedic_price: famedicLabel,
		formatted_public_price: publicLabel,
		public_price_cents: publicPriceCents,
		famedic_price_cents: famedicPriceCents,
		detail_url: "#",
	};
}

function copyPreviewStyles(targetDocument) {
	if (!targetDocument) return;

	targetDocument.head.querySelectorAll("[data-preview-style]").forEach((node) => node.remove());

	document
		.querySelectorAll('link[rel="stylesheet"], style')
		.forEach((node) => {
			const clone = node.cloneNode(true);
			clone.setAttribute("data-preview-style", "true");
			targetDocument.head.appendChild(clone);
		});
}

function PreviewViewportFrame({ children, viewportConfig, scale = 1 }) {
	const iframeRef = useRef(null);
	const [mountNode, setMountNode] = useState(null);

	const attachFrame = () => {
		const frameDocument = iframeRef.current?.contentDocument;
		if (!frameDocument) return;

		copyPreviewStyles(frameDocument);
		frameDocument.documentElement.classList.remove("dark");
		frameDocument.body.className = "m-0 bg-white text-zinc-950";
		setMountNode(frameDocument.getElementById("preview-root"));
	};

	useEffect(() => {
		attachFrame();
	}, []);

	useEffect(() => {
		const frameDocument = iframeRef.current?.contentDocument;
		copyPreviewStyles(frameDocument);
	}, [children, viewportConfig.width, viewportConfig.height]);

	return (
		<>
			<iframe
				ref={iframeRef}
				title={`Preview ${viewportConfig.label}`}
				srcDoc="<!doctype html><html><head><base href='/'></head><body><div id='preview-root'></div></body></html>"
				onLoad={attachFrame}
				className="block border-0 bg-white"
				style={{
					width: `${viewportConfig.width}px`,
					height: `${viewportConfig.height}px`,
					transform: `scale(${scale})`,
					transformOrigin: "top left",
				}}
			/>
			{mountNode && createPortal(
				<div className="min-h-full bg-white text-zinc-950">
					<div className="mx-auto max-w-[1280px] space-y-16 px-4 py-6 sm:px-6 lg:space-y-20 lg:px-10 lg:py-10">
						{children}
					</div>
				</div>,
				mountNode,
			)}
		</>
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
	landingTemplate = "conversion",
}) {
	const [viewport, setViewport] = useState("desktop");
	const [hidden, setHidden] = useState(false);
	const [fullscreen, setFullscreen] = useState(false);
	const [availableWidth, setAvailableWidth] = useState(0);
	const [fullscreenSize, setFullscreenSize] = useState({ width: 0, height: 0 });
	const frameHostRef = useRef(null);
	const fullscreenHostRef = useRef(null);
	const Template = resolveLandingTemplate(content.landing_template || landingTemplate);
	const viewportConfig = VIEWPORTS[viewport] || VIEWPORTS.desktop;
	const scale = Math.min(
		1,
		availableWidth > 0 ? availableWidth / viewportConfig.width : 1,
	);
	const outerHeight = Math.ceil(viewportConfig.height * scale);
	const fullscreenScale = Math.min(
		1,
		fullscreenSize.width > 0 ? fullscreenSize.width / viewportConfig.width : 1,
		fullscreenSize.height > 0 ? fullscreenSize.height / viewportConfig.height : 1,
	);
	const fullscreenOuterWidth = Math.ceil(viewportConfig.width * fullscreenScale);
	const fullscreenOuterHeight = Math.ceil(viewportConfig.height * fullscreenScale);

	useEffect(() => {
		if (!frameHostRef.current || typeof ResizeObserver === "undefined") {
			return;
		}

		const observer = new ResizeObserver(([entry]) => {
			setAvailableWidth(entry.contentRect.width);
		});
		observer.observe(frameHostRef.current);

		return () => observer.disconnect();
	}, []);

	useEffect(() => {
		if (!fullscreen || !fullscreenHostRef.current || typeof ResizeObserver === "undefined") {
			return;
		}

		const observer = new ResizeObserver(([entry]) => {
			setFullscreenSize({
				width: entry.contentRect.width,
				height: entry.contentRect.height,
			});
		});
		observer.observe(fullscreenHostRef.current);

		return () => observer.disconnect();
	}, [fullscreen]);
	const normalizedContent = {
		eyebrow: content.eyebrow,
		title: content.public_title || "Título de campaña",
		subtitle: content.public_subtitle,
		description: content.public_description,
		hero_image: content.hero_url,
		hero_image_alt: content.hero_alt,
		show_prices: showPrices,
		show_brand_logo: showLogo,
		gallery,
		landing_template: content.landing_template || landingTemplate,
		editorial: content.editorial || {},
	};
	const normalizedProducts = useMemo(
		() => products.map(formatPreviewProduct),
		[products],
	);
	const normalizedRelated = useMemo(
		() => relatedProducts.map(formatPreviewProduct),
		[relatedProducts],
	);
	const noopActionProps = () => ({
		type: "button",
		disabled: true,
		"aria-disabled": true,
		onClick: (event) => event.preventDefault(),
	});
	const productCardProps = () => ({
		showPrices,
		cart: null,
		canAddToCart: false,
		isInCart: false,
		onAdd: () => {},
		adding: false,
		cartMessage: null,
	});
	const previewBody = (
		<Template
			content={normalizedContent}
			brand={brand}
			category={null}
			starts={null}
			ends={null}
			catalogUrl="#"
			brandStoresUrl="#"
			primaryAction={{
				label: primaryAction?.label || "Ver estudios",
				url: "#",
			}}
			secondaryAction={
				secondaryAction?.label
					? { label: secondaryAction.label, url: "#" }
					: null
			}
			products={normalizedProducts}
			relatedProducts={normalizedRelated}
			relatedCategories={[]}
			emptyMessage="Los productos se resolverán según el destino configurado."
			productCardProps={productCardProps}
			actionButtonProps={noopActionProps}
		/>
	);

	if (hidden) {
		return (
			<div className="rounded-xl border border-dashed border-zinc-300 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
				<div className="flex flex-wrap items-center justify-between gap-3">
					<Text className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
						Vista previa oculta
					</Text>
					<Button type="button" outline onClick={() => setHidden(false)}>
						Mostrar preview
					</Button>
				</div>
			</div>
		);
	}

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<Text className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
					Vista previa en vivo · cambios sin guardar
				</Text>
				<div className="flex flex-wrap gap-2">
					{Object.entries(VIEWPORTS).map(([key, option]) => (
						<Button
							key={key}
							type="button"
							outline={viewport !== key}
							onClick={() => setViewport(key)}
							title={option.help}
						>
							{option.label}
						</Button>
					))}
					<Button
						type="button"
						outline
						onClick={() => setFullscreen(true)}
						title="Abre el preview en un modal amplio para inspección."
					>
						Abrir grande
					</Button>
					<Button type="button" plain onClick={() => setHidden(true)}>
						Ocultar
					</Button>
				</div>
			</div>

			<div
				ref={frameHostRef}
				className="overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
			>
				<div
					className={viewport === "mobile" ? "mx-auto overflow-hidden rounded-[2rem] border-8 border-zinc-900 bg-white shadow-xl" : "overflow-hidden rounded-lg bg-white shadow-sm"}
					style={{
						width: `${viewportConfig.width * scale}px`,
						height: `${outerHeight}px`,
					}}
				>
					<PreviewViewportFrame viewportConfig={viewportConfig} scale={scale}>
						{previewBody}
					</PreviewViewportFrame>
				</div>
			</div>

			{fullscreen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/70 p-4">
					<div className="flex h-[95vh] w-full max-w-[1500px] flex-col gap-4 rounded-xl bg-white p-4 shadow-2xl dark:bg-zinc-950">
						<div className="flex flex-wrap items-center justify-between gap-3">
							<Text className="font-semibold">
								Preview {viewportConfig.label} · {viewportConfig.width} x {viewportConfig.height}
							</Text>
							<div className="flex flex-wrap gap-2">
								{Object.entries(VIEWPORTS).map(([key, option]) => (
									<Button
										key={key}
										type="button"
										outline={viewport !== key}
										onClick={() => setViewport(key)}
									>
										{option.label}
									</Button>
								))}
								<Button type="button" outline onClick={() => setFullscreen(false)}>
									Cerrar
								</Button>
							</div>
						</div>
						<div
							ref={fullscreenHostRef}
							className="min-h-0 flex-1 overflow-auto rounded-lg bg-zinc-100 p-4 dark:bg-zinc-900"
						>
							<div
								className={viewport === "mobile" ? "mx-auto overflow-hidden rounded-[2rem] border-8 border-zinc-900 bg-white shadow-xl" : "mx-auto overflow-hidden rounded-lg bg-white shadow-xl"}
								style={{
									width: `${fullscreenOuterWidth}px`,
									height: `${fullscreenOuterHeight}px`,
								}}
							>
								<PreviewViewportFrame viewportConfig={viewportConfig} scale={fullscreenScale}>
									{previewBody}
								</PreviewViewportFrame>
							</div>
						</div>
					</div>
				</div>
			)}
		</div>
	);
}
