import FamedicLayout from "@/Layouts/FamedicLayout";
import SideBar from "@/Layouts/FamedicLayout/SideBar";
import NavBar from "@/Layouts/FamedicLayout/NavBar";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { XMarkIcon, InformationCircleIcon } from "@heroicons/react/20/solid";
import { ChevronDoubleRightIcon } from "@heroicons/react/16/solid";
import { CheckIcon, PhotoIcon } from "@heroicons/react/24/solid";
import Card from "@/Components/Card";
import { useEffect } from "react"; // Importar useEffect

// ==================== FUNCIÓN DEBUG GA4 ====================
const sendGA4Event = (eventName, ecommerceData, debugInfo = {}) => {
	if (typeof window === 'undefined') {
		console.warn('GA4: Window no disponible (SSR)');
		return;
	}
	
	window.dataLayer = window.dataLayer || [];
	
	// Crear evento limpio para GA4
	const ga4Event = {
		event: eventName,
		ecommerce: {
			...ecommerceData,
			// Asegurar que items sea un array
			items: Array.isArray(ecommerceData.items) ? ecommerceData.items : []
		}
	};
	
	window.dataLayer.push(ga4Event);
	
	// Debug detallado
	console.groupCollapsed(`🛒 GA4 Cart Layout Event: ${eventName}`);
	console.log('📦 Evento completo:', ga4Event);
	console.log('🔍 ecommerce data:', ecommerceData);
	console.log('📍 Debug info:', debugInfo);
	console.log('📊 dataLayer length:', window.dataLayer.length);
	console.groupEnd();
	
	// Notificación en desarrollo
	if (process.env.NODE_ENV === 'testing' || process.env.NODE_ENV === 'development') {
		showCartLayoutDebugNotification(eventName, ecommerceData);
	}
};

// Notificación específica para carrito layout
const showCartLayoutDebugNotification = (eventName, data) => {
	if (typeof window === 'undefined') return;
	
	const notification = document.createElement('div');
	notification.style.cssText = `
		position: fixed;
		top: 80px;
		right: 20px;
		background: #FBBC04;
		color: white;
		padding: 12px 16px;
		border-radius: 8px;
		z-index: 99998;
		font-family: system-ui;
		font-size: 14px;
		box-shadow: 0 4px 12px rgba(0,0,0,0.15);
		max-width: 300px;
		animation: slideInRight 0.3s ease;
	`;
	
	const itemCount = data.items?.length || 0;
	const totalValue = data.value || 0;
	
	notification.innerHTML = `
		<div style="font-weight: bold; margin-bottom: 4px;">📋 Layout: ${eventName}</div>
		<div style="font-size: 12px; opacity: 0.9;">
			Desde ShoppingCartLayout<br/>
			${itemCount} ${itemCount === 1 ? 'item' : 'items'}
		</div>
	`;
	
	document.body.appendChild(notification);
	
	setTimeout(() => {
		notification.style.animation = 'slideOutRight 0.3s ease';
		setTimeout(() => notification.remove(), 300);
	}, 2000);
};

// Agregar estilos CSS para animaciones
if (typeof window !== 'undefined' && process.env.NODE_ENV === 'testing' || process.env.NODE_ENV === 'development') {
	const styleId = 'ga4-cart-layout-styles';
	if (!document.getElementById(styleId)) {
		const style = document.createElement('style');
		style.id = styleId;
		style.textContent = `
			@keyframes slideInRight {
				from { transform: translateX(100%); opacity: 0; }
				to { transform: translateX(0); opacity: 1; }
			}
			@keyframes slideOutRight {
				from { transform: translateX(0); opacity: 1; }
				to { transform: translateX(100%); opacity: 0; }
			}
		`;
		document.head.appendChild(style);
	}
}
// =======================================================================

export default function ShoppingCartLayout({
	title,
	header,
	items,
	summaryDetails,
	summaryInfoMessage,
	checkoutUrl,
	emptyItemsContent,
	children,
	// =========== NUEVAS PROPS PARA GA4 ===========
	onCheckoutClick, // Callback para manejar checkout click
	currency = 'MXN', // Moneda por defecto
	productDataList = [], // Lista de datos de productos para GA4
	// =============================================
}) {
	
	// =========== DEBUG: Información del layout ===========
	useEffect(() => {
		console.group('📋 ShoppingCartLayout Montado');
		console.log('📦 Items en carrito:', items.length);
		console.log('💰 Summary details:', summaryDetails);
		console.log('🔗 Checkout URL:', checkoutUrl);
		console.log('🎯 onCheckoutClick disponible:', typeof onCheckoutClick === 'function');
		console.groupEnd();
	}, [items, checkoutUrl, onCheckoutClick]);
	
	// =========== EVENTO: remove_from_cart desde layout ===========
	const handleRemoveItem = (item, index) => {
		console.log(`🗑️ Eliminando item ${index} desde layout`);
		
		// Extraer información del item para GA4
		const priceValue = extractPriceValue(item.price);
		const discountValue = item.discountedPrice ? extractPriceValue(item.discountedPrice) : 0;
		const discountAmount = discountValue - priceValue;
		
		// Preparar datos del item
		const ga4Item = {
			item_id: productDataList[index]?.id?.toString() || `item_${index}`,
			item_name: item.heading || 'Producto sin nombre',
			affiliation: 'Famedic Store',
			discount: discountAmount > 0 ? discountAmount : 0,
			index: index,
			item_brand: productDataList[index]?.brand || 'Famedic',
			item_category: productDataList[index]?.category || 'Laboratory Tests',
			item_variant: productDataList[index]?.variant || '',
			price: priceValue,
			quantity: item.quantity || 1,
			item_list_id: 'shopping_cart',
			item_list_name: 'Carrito de compras'
		};
		
		// Enviar evento remove_from_cart
		sendGA4Event('remove_from_cart', {
			currency: currency,
			value: priceValue * (item.quantity || 1),
			items: [ga4Item]
		}, {
			action: 'cart_layout_removal',
			itemIndex: index,
			itemName: item.heading?.substring(0, 30)
		});
		
		// Llamar al callback original
		if (item.onDestroy) {
			item.onDestroy();
		} else {
			console.warn('⚠️ Item no tiene callback onDestroy');
		}
	};
	
	// =========== FUNCIÓN HELPER: Extraer valor numérico del precio ===========
	const extractPriceValue = (priceString) => {
		if (!priceString) return 0;
		// Extraer números, puntos y comas
		const numericString = priceString.replace(/[^0-9.,]/g, '');
		// Reemplazar comas por puntos si es necesario
		const normalized = numericString.replace(',', '.');
		const value = parseFloat(normalized);
		return isNaN(value) ? 0 : value;
	};
	
	// =========== CALCULAR VALOR TOTAL PARA EVENTOS ===========
	const calculateTotalValue = () => {
		let total = 0;
		items.forEach((item, index) => {
			const priceValue = extractPriceValue(item.price);
			total += priceValue * (item.quantity || 1);
		});
		return total;
	};
	
	// =========== MANEJADOR DE CHECKOUT ===========
	const handleCheckout = (e) => {
		e.preventDefault();
		
		console.log('🚀 Checkout clickeado desde layout');
		
		const totalValue = calculateTotalValue();
		
		// Preparar items para evento begin_checkout
		const ga4Items = items.map((item, index) => {
			const priceValue = extractPriceValue(item.price);
			const discountValue = item.discountedPrice ? extractPriceValue(item.discountedPrice) : 0;
			const discountAmount = discountValue - priceValue;
			
			return {
				item_id: productDataList[index]?.id?.toString() || `item_${index}`,
				item_name: item.heading || 'Producto sin nombre',
				affiliation: 'Famedic Store',
				discount: discountAmount > 0 ? discountAmount : 0,
				index: index,
				item_brand: productDataList[index]?.brand || 'Famedic',
				item_category: productDataList[index]?.category || 'Laboratory Tests',
				item_variant: productDataList[index]?.variant || '',
				price: priceValue,
				quantity: item.quantity || 1,
				item_list_id: 'shopping_cart',
				item_list_name: 'Carrito de compras'
			};
		});
		
		// Extraer descuento del summary
		let coupon = '';
		const discountDetail = summaryDetails.find(detail => 
			detail.label?.toLowerCase().includes('descuento')
		);
		if (discountDetail && discountDetail.value !== '$0.00') {
			coupon = 'discount_applied';
		}
		
		// Enviar evento begin_checkout
		sendGA4Event('begin_checkout', {
			currency: currency,
			value: totalValue,
			items: ga4Items,
			coupon: coupon
		}, {
			step: 'checkout_from_layout',
			itemCount: items.length,
			totalValue: totalValue,
			hasDiscount: !!coupon
		});
		
		// Llamar al callback si existe
		if (onCheckoutClick) {
			console.log('📞 Llamando a onCheckoutClick callback');
			onCheckoutClick();
		}
		
		// Navegar al checkout
		console.log(`📍 Navegando a: ${checkoutUrl}`);
		window.location.href = checkoutUrl;
	};

	return (
		<>
			<FamedicLayout
				title={title}
				navbar={<NavBar />}
				sidebar={<SideBar />}
			>
				{header}

				<div className="lg:grid lg:grid-cols-12 lg:items-start lg:gap-x-12 xl:gap-x-16">
					<section className="lg:col-span-7">
						<ul role="list">
							{items.length > 0 ? (
								items.map((item, index) => (
									<CartItem
										key={index}
										imgSrc={item.imgSrc}
										showDefaultImage={item.showDefaultImage}
										heading={item.heading}
										description={item.description}
										indications={item.indications}
										features={item.features || []}
										price={item.price}
										discountedPrice={item.discountedPrice}
										discountPercentage={
											item.discountPercentage
										}
										infoMessage={item.infoMessage}
										quantity={item.quantity}
										destroyCartItem={() => handleRemoveItem(item, index)}
										// Pasar datos adicionales para debug
										itemIndex={index}
										productData={productDataList[index]}
									/>
								))
							) : (
								<li className="py-6 sm:py-10">
									{emptyItemsContent}
								</li>
							)}
						</ul>
					</section>

					{items.length > 0 && (
						<CartSummary
							cartDetails={summaryDetails}
							checkoutUrl={checkoutUrl}
							infoMessage={summaryInfoMessage}
							onCheckoutClick={handleCheckout}
							itemsCount={items.length}
							totalValue={calculateTotalValue()}
							currency={currency}
						/>
					)}
				</div>

				{/* Debug panel solo en desarrollo */}
				{process.env.NODE_ENV === 'testing' && items.length > 0 && (
					<div className="mt-8 rounded-lg border border-yellow-200 bg-yellow-50 p-4">
						<Subheading className="text-yellow-800">🛒 Debug ShoppingCartLayout</Subheading>
						<div className="mt-2 grid grid-cols-1 gap-2 text-sm md:grid-cols-4">
							<div>
								<strong>Items totales:</strong> {items.length}
							</div>
							<div>
								<strong>Valor calculado:</strong> ${calculateTotalValue().toFixed(2)}
							</div>
							<div>
								<strong>Moneda:</strong> {currency}
							</div>
							<div>
								<strong>Checkout URL:</strong> {checkoutUrl ? '✅' : '❌'}
							</div>
							<div className="md:col-span-4">
								<button
									onClick={() => {
										console.group('🔍 Debug Manual ShoppingCartLayout');
										console.log('Items:', items);
										console.log('Product Data:', productDataList);
										console.log('dataLayer:', window.dataLayer);
										console.groupEnd();
									}}
									className="mt-2 rounded bg-yellow-100 px-3 py-1 text-xs text-yellow-800 hover:bg-yellow-200"
								>
									Mostrar debug en consola
								</button>
							</div>
						</div>
					</div>
				)}

				{children}
			</FamedicLayout>
		</>
	);
}

function CartItem({
	heading,
	description = null,
	indications = null,
	features = [],
	price,
	infoMessage,
	imgSrc = null,
	showDefaultImage = true,
	discountedPrice = null,
	discountPercentage = null,
	destroyCartItem,
	quantity = null,
	// =========== NUEVAS PROPS PARA DEBUG ===========
	itemIndex = null,
	productData = null,
	// ==============================================
}) {
	
	// =========== DEBUG: Información del item ===========
	useEffect(() => {
		if (process.env.NODE_ENV === 'testing' || process.env.NODE_ENV === 'development') {
			console.log(`📦 CartItem [${itemIndex}]: "${heading?.substring(0, 30)}..."`);
			console.log(`💰 Precio: ${price}, Descuento: ${discountPercentage}%`);
		}
	}, [heading, price, discountPercentage, itemIndex]);
	
	const handleRemoveClick = () => {
		console.log(`🗑️ Botón eliminar clickeado para item ${itemIndex}: ${heading}`);
		
		if (destroyCartItem) {
			destroyCartItem();
		} else {
			console.error('❌ destroyCartItem no definido');
		}
	};

	return (
		<>
			<Divider />
			<li className="flex py-6 sm:py-10">
				{(showDefaultImage || imgSrc) && (
					<div className="flex-shrink-0">
						{imgSrc ? (
							<img
								src={imgSrc}
								className="size-24 rounded-md object-cover object-center sm:size-48"
								alt={heading}
							/>
						) : (
							<div className="flex size-24 items-center justify-center rounded-lg bg-zinc-100 sm:size-48 dark:bg-slate-800">
								<PhotoIcon className="h-full fill-zinc-200 dark:fill-slate-700" />
							</div>
						)}
					</div>
				)}
				<div
					className={`w-full ${imgSrc || showDefaultImage ? "ml-4 sm:ml-6" : ""}`}
				>
					<div className="relative flex sm:gap-x-6">
						<div className="w-full">
							<div className="pr-9">
								<Subheading className="mb-3">
									{quantity && (
										<Badge color="slate">{quantity}</Badge>
									)}{" "}
									{heading}
									{process.env.NODE_ENV === 'testing' && itemIndex !== null && (
										<Badge color="amber" className="ml-2">
											#{itemIndex}
										</Badge>
									)}
								</Subheading>

								{infoMessage && (
									<Badge color="sky" className="mb-3">
										<InformationCircleIcon
											aria-hidden="true"
											className="size-5 text-famedic-light"
										/>
										{infoMessage}
									</Badge>
								)}

								{description && (
									<Text className="sm:max-w-[80%]">
										{description}
									</Text>
								)}

								{indications && (
									<Text className="sm:max-w-[80%]">
										{indications}
									</Text>
								)}

								{/* Features list */}
								{features.length > 0 && (
									<ul className="mt-2 space-y-1">
										{features.map((feature, idx) => (
											<li
												key={idx}
												className="flex gap-2 text-sm text-zinc-700 dark:text-slate-200"
											>
												<CheckIcon className="mt-1 size-4 flex-shrink-0 text-famedic-light" />
												<Text>{feature}</Text>
											</li>
										))}
									</ul>
								)}
								
								{/* Debug info solo en desarrollo */}
								{process.env.NODE_ENV === 'testing' && productData && (
									<div className="mt-2 rounded bg-gray-100 p-2 text-xs">
										<strong>GA4 Data:</strong> ID: {productData.id}, Brand: {productData.brand}
									</div>
								)}
							</div>
							{discountedPrice && discountPercentage > 0 && (
								<Text className="mt-3 space-x-2 text-right">
									{discountPercentage && (
										<Badge color="famedic-lime">
											{discountPercentage}%
										</Badge>
									)}
									<span className="line-through">
										{discountedPrice}
									</span>
								</Text>
							)}
							<Text className="mt-4 text-right">
								<Strong>
									<span className="text-2xl text-famedic-dark dark:text-white">
										{price}
									</span>
									{process.env.NODE_ENV === 'testing' && (
										<span className="ml-2 text-sm text-gray-500">
											(≈${extractPriceValue(price).toFixed(2)})
										</span>
									)}
								</Strong>
							</Text>
						</div>
						<div className="absolute right-0 top-0">
							<button
								type="button"
								onClick={handleRemoveClick}
								className="-m-2 inline-flex p-2 text-gray-400 hover:text-red-500"
								aria-label={`Eliminar ${heading} del carrito`}
							>
								<XMarkIcon
									aria-hidden="true"
									className="h-6 w-6"
								/>
							</button>
						</div>
					</div>
				</div>
			</li>
		</>
	);
}

// Helper function para CartItem
const extractPriceValue = (priceString) => {
	if (!priceString) return 0;
	const numericString = priceString.replace(/[^0-9.,]/g, '');
	const normalized = numericString.replace(',', '.');
	const value = parseFloat(normalized);
	return isNaN(value) ? 0 : value;
};

function CartSummary({ 
	cartDetails, 
	checkoutUrl, 
	infoMessage,
	// =========== NUEVAS PROPS ===========
	onCheckoutClick,
	itemsCount = 0,
	totalValue = 0,
	currency = 'MXN'
	// ====================================
}) {
	
	const handleCheckout = (e) => {
		if (onCheckoutClick) {
			e.preventDefault();
			console.log('🎯 Checkout desde CartSummary');
			onCheckoutClick(e);
		} else {
			console.log('🔗 Navegación directa al checkout');
			// Navegación normal si no hay callback
		}
	};

	return (
		<Card className="sticky mt-16 space-y-6 px-4 py-6 sm:p-6 lg:top-24 lg:col-span-5 lg:mt-0 lg:p-8">
			{infoMessage?.title && infoMessage?.message && (
				<div className="rounded-md bg-slate-50 p-4 shadow dark:bg-slate-800">
					<div className="flex">
						<div className="shrink-0">
							<InformationCircleIcon
								aria-hidden="true"
								className="size-6 text-famedic-light"
							/>
						</div>
						<div className="ml-3">
							<Subheading>Necesitarás una cita</Subheading>

							<Text className="mt-2">
								Algunos estudios requieren cita para asegurar
								que la sucursal cuente con el equipo necesario y
								que se cumplan todos los requisitos. Esto
								garantiza un servicio preciso y de calidad.
							</Text>
						</div>
					</div>
				</div>
			)}

			<Heading>Resumen</Heading>

			{/* Debug info solo en desarrollo */}
			{process.env.NODE_ENV === 'testing' && (
				<div className="rounded bg-blue-50 p-3">
					<div className="text-sm text-blue-800">
						<strong>GA4 Ready:</strong> {itemsCount} items, Total: ${totalValue.toFixed(2)} {currency}
					</div>
				</div>
			)}

			<dl className="[&>:first-child]:pt-0 [&>:last-child]:pb-6">
				{cartDetails.map((cartDetail, index) => (
					<CartDetail
						key={cartDetail.label}
						{...cartDetail}
						totalRow={index === cartDetails.length - 1}
					/>
				))}
			</dl>

			<Button
				href={checkoutUrl}
				onClick={handleCheckout}
				className="w-full animate-pulse !py-3 hover:animate-none"
			>
				<ChevronDoubleRightIcon />
				Continuar
				{process.env.NODE_ENV === 'testing' && (
					<span className="ml-2 text-xs opacity-70">
						({itemsCount} items)
					</span>
				)}
			</Button>
			
			{process.env.NODE_ENV === 'testing' && (
				<div className="text-center text-xs text-gray-500">
					Click envía evento <strong>begin_checkout</strong> a GA4
				</div>
			)}
		</Card>
	);
}

function CartDetail({ label, value, totalRow = false }) {
	return (
		<>
			<div className="flex items-center justify-between gap-4 py-6">
				<dt className="flex-shrink-0">
					{totalRow ? (
						<Subheading>
							<span className="whitespace-nowrap text-2xl">
								{label}
							</span>
						</Subheading>
					) : (
						<Text className="whitespace-nowrap">{label}</Text>
					)}
				</dt>
				<dd className="text-right">
					{totalRow ? (
						<Strong className="text-2xl">{value}</Strong>
					) : (
						<Text className="text-balance">{value}</Text>
					)}
				</dd>
			</div>
			{!totalRow && <Divider />}
		</>
	);
}