import { useMemo, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import FamedicLayout from "@/Layouts/FamedicLayout";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { resolveLandingTemplate } from "./templates";

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
	preview = null,
}) {
	const { props } = usePage();
	const laboratoryCarts = props.laboratoryCarts || {};
	const [addingId, setAddingId] = useState(null);
	const [removingId, setRemovingId] = useState(null);
	const [cartMessages, setCartMessages] = useState({});
	const isAdminPreview = Boolean(preview?.admin);
	const Template = resolveLandingTemplate(content?.landing_template);

	const starts = formatDate(campaign?.starts_at);
	const ends = formatDate(campaign?.ends_at);
	const catalogUrl = brand?.catalog_url || primary_action?.url;
	const brandStoresUrl = brand?.stores_url || stores_url;
	const canUseActions = !isAdminPreview;
	const canAddToCart = can_add_to_cart && canUseActions;

	const cartTestIdsByBrand = useMemo(() => {
		const map = {};
		Object.entries(laboratoryCarts).forEach(([brandKey, items]) => {
			map[brandKey] = new Set(
				(items || []).map((item) =>
					String(item.laboratory_test_id ?? item.laboratory_test?.id ?? item.laboratoryTest?.id),
				),
			);
		});
		return map;
	}, [laboratoryCarts]);

	const findProductCartItem = (product) => {
		const brandKey = product.brand;
		if (!brandKey) return null;

		return (laboratoryCarts?.[brandKey] || []).find((item) =>
			String(item.laboratory_test_id ?? item.laboratory_test?.id ?? item.laboratoryTest?.id) === String(product.id),
		) || null;
	};

	const isProductInCart = (product) => {
		const brandKey = product.brand;
		if (!brandKey) return false;
		return cartTestIdsByBrand[brandKey]?.has(String(product.id)) || false;
	};

	const handleAddToCart = (product) => {
		if (isAdminPreview || !cart?.add_url || cart?.requires_auth || !product.brand) {
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

	const handleRemoveFromCart = (product) => {
		const cartItem = findProductCartItem(product);

		if (isAdminPreview || !cartItem?.id) {
			return;
		}

		setRemovingId(product.id);
		setCartMessages((current) => ({ ...current, [product.id]: null }));

		router.delete(
			route("laboratory-cart-items.destroy", {
				laboratory_cart_item: cartItem.id,
			}),
			{
				preserveScroll: true,
				onSuccess: () => {
					setCartMessages((current) => ({
						...current,
						[product.id]: "Estudio eliminado del carrito.",
					}));
				},
				onError: () => {
					setCartMessages((current) => ({
						...current,
						[product.id]: "No se pudo quitar el estudio del carrito.",
					}));
				},
				onFinish: () => setRemovingId(null),
			},
		);
	};

	const actionButtonProps = (url, extra = {}) =>
		canUseActions && url
			? { href: url, ...extra }
			: {
					type: "button",
					disabled: true,
					"aria-disabled": true,
					onClick: (event) => event.preventDefault(),
					...extra,
				};

	const productCardProps = (product) => ({
		showPrices: Boolean(content?.show_prices),
		cart,
		canAddToCart,
		isInCart: isProductInCart(product),
		onAdd: handleAddToCart,
		onRemove: handleRemoveFromCart,
		adding: addingId === product.id,
		removing: removingId === product.id,
		cartMessage: cartMessages[product.id],
	});

	return (
		<FamedicLayout title={content?.title || "Campaña"}>
			<div className="mx-auto max-w-[1280px] space-y-16 px-4 py-6 sm:px-6 lg:space-y-20 lg:px-10 lg:py-10">
				{isAdminPreview && (
					<section className="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 dark:border-sky-900 dark:bg-sky-950/40">
						<Text className="text-sm font-medium text-sky-900 dark:text-sky-100">
							{preview.label || "Vista previa administrativa"}: versión
							guardada. Las acciones, cookies y tracking están
							desactivados.
						</Text>
						{preview.back_url && (
							<Button href={preview.back_url} outline className="mt-3">
								Volver a editar
							</Button>
						)}
					</section>
				)}

				<Template
					content={content}
					brand={brand}
					category={category}
					starts={starts}
					ends={ends}
					startsAt={campaign?.starts_at}
					endsAt={campaign?.ends_at}
					catalogUrl={catalogUrl}
					brandStoresUrl={brandStoresUrl}
					primaryAction={primary_action}
					secondaryAction={secondary_action}
					products={products}
					relatedProducts={related_products}
					relatedCategories={related_categories}
					emptyMessage={empty_message}
					productCardProps={productCardProps}
					actionButtonProps={actionButtonProps}
				/>
			</div>
		</FamedicLayout>
	);
}
