import LaboratoryShoppingCartLayout from "@/Layouts/LaboratoryShoppingCartLayout";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text, TextLink } from "@/Components/Catalyst/text";
import { useDeleteLaboratoryCartItem } from "@/Hooks/useDeleteLaboratoryCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import BalanceCreditCard from "@/Components/Coupons/BalanceCreditCard";
import LaboratoryCompatibleStoresSection from "@/Components/LaboratoryCompatibleStoresSection";
import PreferredStorePromptDialog from "@/Components/LaboratoryCart/PreferredStorePromptDialog";
import { Badge } from "@/Components/Catalyst/badge";
import { shouldPromptForPreferredStore } from "@/lib/laboratoryCartPreferredStoreUi";
import { useCallback, useEffect, useRef, useState } from "react";

const sendGA4Event = (eventName, ecommerceData, debugInfo = {}) => {
	if (typeof window === "undefined") {
		console.warn("GA4: Window no disponible (SSR)");
		return;
	}

	window.dataLayer = window.dataLayer || [];
	window.dataLayer.push({ ecommerce: null });
	window.dataLayer.push({
		event: eventName,
		ecommerce: {
			...ecommerceData,
			items: Array.isArray(ecommerceData.items)
				? ecommerceData.items
				: [ecommerceData.items].filter(Boolean),
		},
	});

	console.groupCollapsed(`🛒 GA4 Cart Event: ${eventName}`);
	console.log("📦 Evento completo:", { event: eventName, ecommerceData });
	console.log("📍 Debug info:", debugInfo);
	console.groupEnd();

	if (process.env.NODE_ENV === "testing") {
		showCartDebugNotification(eventName, ecommerceData);
	}

	return { event: eventName, ecommerce: ecommerceData };
};

const showCartDebugNotification = (eventName, data) => {
	if (typeof window === "undefined") return;

	document
		.querySelectorAll(".ga4-cart-notification")
		.forEach((node) => node.remove());

	const notification = document.createElement("div");
	notification.className = "ga4-cart-notification";
	notification.style.cssText = `
		position: fixed;
		top: 20px;
		right: 20px;
		background: #34A853;
		color: white;
		padding: 12px 16px;
		border-radius: 8px;
		z-index: 99999;
		font-family: system-ui;
		font-size: 14px;
		box-shadow: 0 4px 12px rgba(0,0,0,0.15);
		max-width: 300px;
	`;

	const itemCount = data.items?.length || 0;
	const totalValue = data.value || 0;

	notification.innerHTML = `
		<div style="font-weight: bold; margin-bottom: 4px;">🛒 ${eventName}</div>
		<div style="font-size: 12px; opacity: 0.9;">
			${itemCount} ${itemCount === 1 ? "producto" : "productos"} · $${totalValue.toFixed(2)} MXN
		</div>
	`;

	document.body.appendChild(notification);
	setTimeout(() => notification.remove(), 3000);
};

export default function LaboratoryShoppingCart({
	laboratoryBrand,
	laboratoryCarts,
	total = 0,
	formattedTotal,
	formattedSubtotal,
	formattedDiscount,
	balanceCouponsCents = 0,
	availableBalanceCoupons = [],
	cartTotalCents = 0,
	balanceCreditPresentation = null,
}) {
	const {
		laboratoryCartItemToDelete,
		setLaboratoryCartItemToDelete,
		destroyLaboratoryCartItem,
		processing,
	} = useDeleteLaboratoryCartItem();

	const [eventLog, setEventLog] = useState({
		view_cart_sent: false,
		last_remove_event: null,
		last_checkout_event: null,
	});
	const [selectedStore, setSelectedStore] = useState(null);
	const [showPreferredStorePrompt, setShowPreferredStorePrompt] =
		useState(false);
	const openPreferredStoreSelectorRef = useRef(null);
	const registerOpenPreferredStoreSelector = useCallback((openSelector) => {
		openPreferredStoreSelectorRef.current = openSelector;
	}, []);

	const extractPriceValue = (priceString) => {
		if (!priceString || priceString === "$0.00") return 0;

		const numericString = priceString.replace(/[^0-9.,]/g, "");
		if (!numericString) return 0;

		let normalized = numericString;
		if (normalized.includes(",") && normalized.includes(".")) {
			normalized = normalized.replace(/,/g, "");
		} else if (normalized.includes(",")) {
			normalized = normalized.replace(",", ".");
		}

		const value = parseFloat(normalized);
		return Number.isNaN(value) ? 0 : value;
	};

	useEffect(() => {
		if (eventLog.view_cart_sent) {
			return;
		}

		const timer = setTimeout(() => {
			const cartItems = laboratoryCarts?.[laboratoryBrand.value] || [];

			if (cartItems.length === 0) {
				return;
			}

			const totalValue =
				extractPriceValue(formattedTotal) ||
				cartItems.reduce((sum, item) => {
					return (
						sum +
						(item.laboratory_test?.famedic_price_cents || 0) / 100
					);
				}, 0);

			const items = cartItems.map((item, index) => {
				const test = item.laboratory_test;
				const itemValue = test.famedic_price_cents / 100;
				const publicValue = (test.public_price_cents || 0) / 100;
				const discount = Math.max(0, publicValue - itemValue);

				return {
					item_id:
						test.id?.toString() ||
						`lab_test_${test.id || index}`,
					item_name: test.name || "Estudio de laboratorio",
					affiliation: "Famedic Store",
					coupon: "",
					discount,
					index,
					item_brand: laboratoryBrand.name || "Laboratorio",
					item_category: test.category || "Laboratory Tests",
					item_list_id: "shopping_cart",
					item_list_name: "Carrito de compras",
					price: itemValue,
					quantity: 1,
				};
			});

			sendGA4Event(
				"view_cart",
				{
					currency: "MXN",
					value: totalValue,
					items,
				},
				{
					itemCount: cartItems.length,
					brand: laboratoryBrand.value,
				},
			);

			setEventLog((prev) => ({ ...prev, view_cart_sent: true }));
		}, 1000);

		return () => clearTimeout(timer);
	}, [
		laboratoryCarts,
		laboratoryBrand,
		formattedTotal,
		eventLog.view_cart_sent,
	]);

	const handleItemRemove = (laboratoryCartItem, index) => {
		if (!laboratoryCartItem?.laboratory_test) {
			return;
		}

		const test = laboratoryCartItem.laboratory_test;
		const itemValue = test.famedic_price_cents / 100;
		const publicValue = (test.public_price_cents || 0) / 100;
		const discount = Math.max(0, publicValue - itemValue);

		const now = Date.now();
		if (
			eventLog.last_remove_event &&
			now - eventLog.last_remove_event < 1000
		) {
			return;
		}

		sendGA4Event(
			"remove_from_cart",
			{
				currency: "MXN",
				value: itemValue,
				items: [
					{
						item_id: test.id?.toString() || `lab_test_${test.id}`,
						item_name: test.name,
						affiliation: "Famedic Store",
						discount,
						index,
						item_brand: laboratoryBrand.name || "Laboratorio",
						item_category: test.category || "Laboratory Tests",
						item_list_id: "shopping_cart",
						item_list_name: "Carrito de compras",
						price: itemValue,
						quantity: 1,
					},
				],
			},
			{ testId: test.id, itemIndex: index },
		);

		setEventLog((prev) => ({ ...prev, last_remove_event: now }));
		setLaboratoryCartItemToDelete(laboratoryCartItem);
	};

	const proceedToCheckout = useCallback(() => {
		const cartItems = laboratoryCarts?.[laboratoryBrand.value] || [];

		if (cartItems.length === 0) {
			return;
		}

		const now = Date.now();
		if (
			eventLog.last_checkout_event &&
			now - eventLog.last_checkout_event < 2000
		) {
			return;
		}

		const totalValue =
			extractPriceValue(formattedTotal) ||
			cartItems.reduce((sum, item) => {
				return (
					sum + (item.laboratory_test?.famedic_price_cents || 0) / 100
				);
			}, 0);

		const items = cartItems.map((item, index) => {
			const test = item.laboratory_test;
			const itemValue = test.famedic_price_cents / 100;
			const publicValue = (test.public_price_cents || 0) / 100;
			const discount = Math.max(0, publicValue - itemValue);

			return {
				item_id: test.id?.toString() || `lab_test_${test.id}`,
				item_name: test.name,
				affiliation: "Famedic Store",
				discount,
				index,
				item_brand: laboratoryBrand.name || "Laboratorio",
				item_category: test.category || "Laboratory Tests",
				item_list_id: "shopping_cart",
				item_list_name: "Carrito de compras",
				price: itemValue,
				quantity: 1,
			};
		});

		const hasDiscount =
			formattedDiscount &&
			formattedDiscount !== "$0.00" &&
			formattedDiscount !== "-$0.00";

		sendGA4Event(
			"begin_checkout",
			{
				currency: "MXN",
				value: totalValue,
				items,
				coupon: hasDiscount ? "famedic_discount" : "",
			},
			{
				itemCount: cartItems.length,
				totalValue,
			},
		);

		setEventLog((prev) => ({ ...prev, last_checkout_event: now }));

		window.location.href = route("laboratory.checkout", {
			laboratory_brand: laboratoryBrand.value,
			step: "patient",
		});
	}, [
		eventLog.last_checkout_event,
		formattedDiscount,
		formattedTotal,
		laboratoryBrand.name,
		laboratoryBrand.value,
		laboratoryCarts,
	]);

	const handleCheckoutClick = useCallback(
		(event) => {
			event?.preventDefault?.();

			const cartItems = laboratoryCarts?.[laboratoryBrand.value] || [];

			if (cartItems.length === 0) {
				event?.stopPropagation?.();
				return;
			}

			if (shouldPromptForPreferredStore(selectedStore)) {
				setShowPreferredStorePrompt(true);
				return;
			}

			proceedToCheckout();
		},
		[laboratoryBrand.value, laboratoryCarts, proceedToCheckout, selectedStore],
	);

	const cartItems = laboratoryCarts?.[laboratoryBrand.value] || [];
	const hasAppointmentItems = cartItems.some(
		(item) => item.laboratory_test?.requires_appointment,
	);

	const productDataList = cartItems.map((item, index) => ({
		id: item.laboratory_test?.id,
		brand: laboratoryBrand.name,
		category: item.laboratory_test?.category,
		index,
	}));

	const studyItems = cartItems.map((laboratoryCartItem, index) => {
		const test = laboratoryCartItem.laboratory_test;

		return {
			id: laboratoryCartItem.id,
			name: test.name,
			formattedPrice: test.formatted_famedic_price,
			requiresAppointment: Boolean(test.requires_appointment),
			onRemove: () => handleItemRemove(laboratoryCartItem, index),
		};
	});

	const checkoutUrl = route("laboratory.checkout", {
		laboratory_brand: laboratoryBrand.value,
		step: "patient",
	});

	const addMoreHref = route("laboratory-tests", {
		laboratory_brand: laboratoryBrand.value,
	});

	return (
		<>
			<LaboratoryShoppingCartLayout
				title="Carrito de laboratorio"
				header={
					<div className="mb-6 sm:mb-8">
						<LaboratoryBrandCard
							src={`/images/gda/${laboratoryBrand.imageSrc}`}
							className="mb-4 w-48 p-3 sm:w-56"
						/>
						<h1 className="text-2xl font-semibold tracking-tight text-famedic-dark sm:text-3xl dark:text-white">
							Carrito de laboratorio
						</h1>
						<Text className="mt-2 text-zinc-600 dark:text-slate-400">
							Revisa tus estudios antes de continuar.
						</Text>
					</div>
				}
				studyItems={studyItems}
				emptyItemsContent={
					<>
						<Subheading>No hay estudios en tu carrito</Subheading>
						<Text className="mt-2 w-full">
							Te invitamos a{" "}
							<TextLink
								href={addMoreHref}
								onClick={() => {
									sendGA4Event("select_item", {
										item_list_id: "empty_cart_suggestion",
										item_list_name:
											"Sugerencias carrito vacío",
										items: [
											{
												item_id: "explore_studies",
												item_name: "Explorar estudios",
												index: 0,
											},
										],
									});
								}}
							>
								explorar los estudios de {laboratoryBrand.name}
							</TextLink>
						</Text>
					</>
				}
				formattedSubtotal={formattedSubtotal}
				formattedDiscount={formattedDiscount}
				formattedTotal={formattedTotal}
				checkoutUrl={checkoutUrl}
				onCheckoutClick={handleCheckoutClick}
				addMoreHref={addMoreHref}
				addMoreLabel="+ Agregar más estudios"
				appointmentNotice={
					hasAppointmentItems
						? {
								title: "Necesitarás una cita",
								message:
									"Algunos estudios requieren cita. La coordinamos contigo después de completar tu compra.",
							}
						: null
				}
				summaryExtra={
					<BalanceCreditCard
						variant="cart"
						balanceCreditPresentation={balanceCreditPresentation}
						balanceCouponsCents={balanceCouponsCents}
						availableBalanceCoupons={availableBalanceCoupons}
						cartTotalCents={cartTotalCents || total}
					/>
				}
				currency="MXN"
				productDataList={productDataList}
				preferredStore={selectedStore}
			>
				<LaboratoryCompatibleStoresSection
					cartItemsCount={cartItems.length}
					laboratoryBrand={laboratoryBrand}
					selectedStore={selectedStore}
					onSelectedStoreChange={setSelectedStore}
					onRegisterOpenSelector={registerOpenPreferredStoreSelector}
				/>

				{process.env.NODE_ENV === "testing" && (
					<div className="mt-8 rounded-lg border border-blue-200 bg-blue-50 p-4">
						<Subheading className="flex items-center gap-2 text-blue-800">
							Debug Carrito GA4
							<Badge color="blue">
								{eventLog.view_cart_sent ? "view_cart ✓" : "view_cart …"}
							</Badge>
						</Subheading>
					</div>
				)}
			</LaboratoryShoppingCartLayout>

			<PreferredStorePromptDialog
				open={showPreferredStorePrompt}
				onClose={() => setShowPreferredStorePrompt(false)}
				onChooseStore={() => {
					setShowPreferredStorePrompt(false);
					openPreferredStoreSelectorRef.current?.();
				}}
				onContinueWithoutStore={() => {
					setShowPreferredStorePrompt(false);
					proceedToCheckout();
				}}
			/>

			<DeleteConfirmationModal
				isOpen={!!laboratoryCartItemToDelete}
				close={() => setLaboratoryCartItemToDelete(null)}
				title="Quitar del carrito"
				description={`¿Estás seguro de que deseas quitar "${laboratoryCartItemToDelete?.laboratory_test.name}" del carrito?`}
				processing={processing}
				destroy={() => {
					if (laboratoryCartItemToDelete) {
						destroyLaboratoryCartItem();
						setEventLog((prev) => ({
							...prev,
							view_cart_sent: false,
						}));
					}
				}}
			/>
		</>
	);
}
