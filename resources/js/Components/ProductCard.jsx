import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import {
	ShoppingCartIcon,
	MinusCircleIcon,
	PlusCircleIcon,
	BarsArrowDownIcon,
	ArrowPathIcon,
	EyeIcon,
} from "@heroicons/react/16/solid";
import { PhotoIcon, CheckIcon } from "@heroicons/react/24/solid";
import { Button } from "@/Components/Catalyst/button";
import { Link } from "@inertiajs/react";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import { useRef, useEffect, useState } from "react";
import { Dialog, DialogBody } from "@/Components/Catalyst/dialog";

// ==================== FUNCIÓN DEBUG GA4 CORREGIDA ====================
const sendGA4Event = (eventName, ecommerceData, debugInfo = {}) => {
	if (typeof window === 'undefined') {
		console.warn('GA4: Window no disponible (SSR)');
		return;
	}
	
	window.dataLayer = window.dataLayer || [];
	
	// **IMPORTANTE: Limpiar ecommerce anterior (recomendación de Google)**
	window.dataLayer.push({ ecommerce: null });
	
	// Crear evento limpio para GA4
	const ga4Event = {
		event: eventName,
		ecommerce: {
			...ecommerceData,
			// Asegurar que items sea un array y tenga estructura correcta
			items: Array.isArray(ecommerceData.items) 
				? ecommerceData.items.map(item => ({
					// Estructura COMPLETA que espera GA4
					item_id: item.item_id?.toString() || `item_${Date.now()}`,
					item_name: item.item_name || 'Producto sin nombre',
					affiliation: item.affiliation || 'Famedic Store',
					coupon: item.coupon || '',
					discount: item.discount || 0,
					index: item.index || 0,
					item_brand: item.item_brand || 'Famedic',
					item_category: item.item_category || 'Laboratory Tests',
					item_category2: item.item_category2 || '',
					item_category3: item.item_category3 || '',
					item_category4: item.item_category4 || '',
					item_category5: item.item_category5 || '',
					item_list_id: item.item_list_id || 'product_grid',
					item_list_name: item.item_list_name || 'Grid de productos',
					item_variant: item.item_variant || '',
					location_id: item.location_id || '',
					price: item.price || 0,
					quantity: item.quantity || 1,
					// google_business_vertical: 'retail' // Opcional
				}))
				: []
		}
	};
	
	window.dataLayer.push(ga4Event);
	
	// Debug detallado
	console.groupCollapsed(`🎯 GA4 Product Event: ${eventName}`);
	console.log('📦 Evento completo:', ga4Event);
	console.log('🔍 ecommerce data:', ecommerceData);
	console.log('📍 Debug info:', debugInfo);
	console.log('📊 dataLayer length:', window.dataLayer.length);
	
	// Mostrar últimos eventos
	const lastEvents = window.dataLayer.slice(-5).filter(e => e.event);
	console.log('🔄 Últimos 5 eventos:', lastEvents);
	console.groupEnd();
	
	// También mostrar notificación en pantalla en desarrollo
	if (process.env.NODE_ENV === 'testing') {
		showProductDebugNotification(eventName, ecommerceData);
	}
	
	return ga4Event;
};

// Mostrar notificación en pantalla para debug
const showProductDebugNotification = (eventName, data) => {
	if (typeof window === 'undefined') return;
	
	// Eliminar notificaciones anteriores
	const existing = document.querySelectorAll('.ga4-product-notification');
	existing.forEach(n => n.remove());
	
	const notification = document.createElement('div');
	notification.className = 'ga4-product-notification';
	notification.style.cssText = `
		position: fixed;
		bottom: 80px;
		right: 20px;
		background: #EA4335;
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
	const itemName = data.items?.[0]?.item_name || 'Producto';
	
	notification.innerHTML = `
		<div style="font-weight: bold; margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
			<span>🛍️</span>
			<span>${eventName}</span>
		</div>
		<div style="font-size: 12px; opacity: 0.9;">
			${itemName.substring(0, 25)}${itemName.length > 25 ? '...' : ''}<br/>
			💰 $${totalValue.toFixed(2)} ${data.currency || 'MXN'}
		</div>
	`;
	
	document.body.appendChild(notification);
	
	setTimeout(() => {
		notification.style.animation = 'slideOutRight 0.3s ease';
		setTimeout(() => notification.remove(), 300);
	}, 3000);
};

// Agregar estilos CSS para animaciones
if (typeof window !== 'undefined') {
	const styleId = 'ga4-product-styles';
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

// ==================== HELPER: Extraer precio numérico ====================
const extractPriceValue = (priceString) => {
	if (!priceString || priceString === '$0.00') return 0;
	
	// Extraer números, puntos y comas
	const numericString = priceString.replace(/[^0-9.,]/g, '');
	if (!numericString) return 0;
	
	// Manejar formato mexicano (1,000.50) o americano (1,000.50)
	let normalized = numericString;
	if (normalized.includes(',') && normalized.includes('.')) {
		// Formato: 1,000.50 → quitar comas
		normalized = normalized.replace(/,/g, '');
	} else if (normalized.includes(',')) {
		// Formato: 1.000,50 → reemplazar coma por punto
		normalized = normalized.replace(',', '.');
	}
	
	const value = parseFloat(normalized);
	return isNaN(value) ? 0 : value;
};
// =======================================================================

export default function ProductCard({
	heading,
	description,
	inCartHref = null,
	discountPercentage,
	discountedPrice,
	tags = [],
	price,
	quantity = null,
	imgSrc,
	showDefaultImage = false,
	onRemoveClick,
	onAddClick,
	onQuantityChangeClick,
	features = [],
	processing = false,
	// Laboratory test specific fields
	otherName = null,
	elements = null,
	commonUse = null,
	// =========== NUEVAS PROPS PARA GA4 ===========
	productData = null, // Datos completos del producto para GA4
	currency = 'MXN',   // Moneda por defecto
	listId = 'product_grid', // ID de la lista de productos
	listName = 'Grid de productos', // Nombre de la lista
	// =============================================
}) {
	const [isOpen, setIsOpen] = useState(
		route().current && route().current("laboratory-tests.test"),
	);
	
	// =========== STATE PARA CONTROLAR EVENTOS ===========
	const [eventState, setEventState] = useState({
		view_item_sent: false,
		add_to_cart_sent: false,
		last_event_time: null
	});
	
	// =========== DEBUG: Verificar props al montar ===========
	useEffect(() => {
		console.group('🔍 ProductCard Debug Info');
		console.log('📦 Props recibidos:', {
			heading: heading?.substring(0, 30),
			price,
			priceNumeric: extractPriceValue(price),
			productData,
			quantity,
			inCartHref: !!inCartHref,
			discountPercentage,
			listId,
			listName
		});
		console.log('📍 Component mounted');
		console.groupEnd();
	}, []);

	// =========== MANEJADOR DE VIEW_ITEM CORREGIDO ===========
	const handleViewItem = () => {
		console.log('🔍 handleViewItem llamado');
		
		// Prevenir eventos duplicados rápidos
		const now = Date.now();
		if (eventState.last_event_time && (now - eventState.last_event_time < 500)) {
			console.warn('⚠️ Evento demasiado rápido, omitiendo...');
			return;
		}
		
		const priceValue = extractPriceValue(price);
		
		// **ESTRUCTURA COMPLETA PARA view_item**
		const itemStructure = {
			item_id: productData?.id?.toString() || `prod_${Date.now()}`,
			item_name: heading || 'Producto sin nombre',
			affiliation: 'Famedic Store',
			coupon: productData?.coupon || '',
			discount: discountPercentage ? (priceValue * (discountPercentage / 100)) : 0,
			index: productData?.index || 0,
			item_brand: productData?.brand || 'Famedic',
			item_category: productData?.category || 'Laboratory Tests',
			item_category2: productData?.subcategory || '',
			item_category3: productData?.type || '',
			item_list_id: listId,
			item_list_name: listName,
			item_variant: productData?.variant || '',
			location_id: productData?.location_id || '',
			price: priceValue,
			quantity: 1
		};
		
		sendGA4Event('view_item', {
			currency: currency,
			value: priceValue,
			items: [itemStructure]
		}, { 
			source: 'product_card', 
			hasProductData: !!productData,
			fromDetailButton: true 
		});
		
		// Actualizar estado
		setEventState(prev => ({ 
			...prev, 
			view_item_sent: true,
			last_event_time: now 
		}));
		
		// Abrir el diálogo de detalle
		setIsOpen(true);
	};

	// =========== MANEJADOR DE ADD_TO_CART CORREGIDO ===========
	const handleAddToCart = (e) => {
		if (processing) {
			console.warn('⏳ GA4 add_to_cart: processing está en true');
			return;
		}
		
		// Prevenir eventos duplicados
		const now = Date.now();
		if (eventState.last_event_time && (now - eventState.last_event_time < 500)) {
			console.warn('⚠️ Evento add_to_cart demasiado rápido');
			return;
		}
		
		console.log('🛒 handleAddToCart llamado');
		
		const priceValue = extractPriceValue(price);
		const discountAmount = discountPercentage ? 
			(priceValue * (discountPercentage / 100)) : 0;
		
		// **ESTRUCTURA COMPLETA PARA add_to_cart**
		const itemStructure = {
			item_id: productData?.id?.toString() || `prod_${Date.now()}`,
			item_name: heading || 'Producto sin nombre',
			affiliation: 'Famedic Store',
			coupon: productData?.coupon || '',
			discount: discountAmount,
			index: productData?.index || 0,
			item_brand: productData?.brand || 'Famedic',
			item_category: productData?.category || 'Laboratory Tests',
			item_category2: productData?.subcategory || '',
			item_category3: productData?.type || '',
			item_list_id: listId,
			item_list_name: listName,
			item_variant: productData?.variant || '',
			location_id: productData?.location_id || '',
			price: priceValue,
			quantity: 1
		};
		
		sendGA4Event('add_to_cart', {
			currency: currency,
			value: priceValue,
			items: [itemStructure]
		}, {
			priceOriginal: price,
			priceValue: priceValue,
			discountPercentage,
			discountAmount,
			action: 'initial_add'
		});
		
		// Actualizar estado
		setEventState(prev => ({ 
			...prev, 
			add_to_cart_sent: true,
			last_event_time: now 
		}));
		
		// Llamar al manejador original
		if (onAddClick) {
			console.log('📞 Llamando a onAddClick original');
			onAddClick(e);
		}
	};

	// =========== MANEJADOR DE REMOVE_FROM_CART CORREGIDO ===========
	const handleRemoveFromCart = (e) => {
		console.log('🗑️ handleRemoveFromCart llamado');
		
		const priceValue = extractPriceValue(price);
		const discountAmount = discountPercentage ? 
			(priceValue * (discountPercentage / 100)) : 0;
		const currentQuantity = quantity || 1;
		
		// **ESTRUCTURA COMPLETA PARA remove_from_cart**
		const itemStructure = {
			item_id: productData?.id?.toString() || `prod_${Date.now()}`,
			item_name: heading,
			affiliation: 'Famedic Store',
			coupon: productData?.coupon || '',
			discount: discountAmount,
			index: productData?.index || 0,
			item_brand: productData?.brand || 'Famedic',
			item_category: productData?.category || 'Laboratory Tests',
			item_category2: productData?.subcategory || '',
			item_category3: productData?.type || '',
			item_list_id: 'shopping_cart', // Diferente cuando está en carrito
			item_list_name: 'Carrito de compras',
			item_variant: productData?.variant || '',
			location_id: productData?.location_id || '',
			price: priceValue,
			quantity: currentQuantity
		};
		
		sendGA4Event('remove_from_cart', {
			currency: currency,
			value: priceValue * currentQuantity,
			items: [itemStructure]
		}, { 
			action: 'full_remove', 
			quantity: currentQuantity,
			totalValue: priceValue * currentQuantity 
		});
		
		// Llamar al manejador original
		if (onRemoveClick) onRemoveClick(e);
	};

	// =========== MANEJADOR DE QUANTITY_CHANGE CORREGIDO ===========
	const handleQuantityChange = (newQuantity) => {
		console.log('🔢 handleQuantityChange:', newQuantity);
		
		if (!onQuantityChangeClick) {
			console.warn('⚠️ onQuantityChangeClick no definido');
			return;
		}
		
		const oldQuantity = quantity || 1;
		const priceValue = extractPriceValue(price);
		
		// Determinar si es agregar o remover
		if (newQuantity > oldQuantity) {
			// Agregar cantidad
			const addedQuantity = newQuantity - oldQuantity;
			console.log(`➕ Agregando ${addedQuantity} unidades`);
			
			const itemStructure = {
				item_id: productData?.id?.toString(),
				item_name: heading,
				affiliation: 'Famedic Store',
				index: productData?.index || 0,
				item_brand: productData?.brand || 'Famedic',
				item_category: productData?.category || 'Laboratory Tests',
				item_list_id: 'shopping_cart',
				item_list_name: 'Carrito de compras',
				price: priceValue,
				quantity: addedQuantity
			};
			
			sendGA4Event('add_to_cart', {
				currency: currency,
				value: priceValue * addedQuantity,
				items: [itemStructure]
			}, { 
				action: 'quantity_increase', 
				from: oldQuantity, 
				to: newQuantity,
				added: addedQuantity 
			});
		} else if (newQuantity < oldQuantity && newQuantity > 0) {
			// Reducir cantidad
			const removedQuantity = oldQuantity - newQuantity;
			console.log(`➖ Reduciendo ${removedQuantity} unidades`);
			
			const itemStructure = {
				item_id: productData?.id?.toString(),
				item_name: heading,
				affiliation: 'Famedic Store',
				index: productData?.index || 0,
				item_brand: productData?.brand || 'Famedic',
				item_category: productData?.category || 'Laboratory Tests',
				item_list_id: 'shopping_cart',
				item_list_name: 'Carrito de compras',
				price: priceValue,
				quantity: removedQuantity
			};
			
			sendGA4Event('remove_from_cart', {
				currency: currency,
				value: priceValue * removedQuantity,
				items: [itemStructure]
			}, { 
				action: 'quantity_decrease', 
				from: oldQuantity, 
				to: newQuantity,
				removed: removedQuantity 
			});
		}
		
		// Llamar al manejador original
		onQuantityChangeClick(newQuantity);
	};
	// =============================================

	return (
		<>
			<div className="flex flex-1 flex-col rounded-xl shadow-md">
				<ProductCardContent
					heading={heading}
					description={description}
					tags={tags}
					features={features}
					imgSrc={imgSrc}
					showDefaultImage={showDefaultImage}
					price={price}
					discountedPrice={discountedPrice}
					discountPercentage={discountPercentage}
					quantity={quantity}
					inCartHref={inCartHref}
					onRemoveClick={handleRemoveFromCart}
					onAddClick={handleAddToCart}
					onQuantityChangeClick={handleQuantityChange}
					processing={processing}
					showDetailButton={true}
					setIsOpen={setIsOpen}
					otherName={otherName}
					elements={elements}
					commonUse={commonUse}
					// Pasar todas las props necesarias
					productData={productData}
					currency={currency}
					onViewDetail={handleViewItem}
					listId={listId}
					listName={listName}
					eventState={eventState}
				/>
			</div>

			<ProductDetail
				heading={heading}
				description={description}
				tags={tags}
				features={features}
				imgSrc={imgSrc}
				showDefaultImage={showDefaultImage}
				price={price}
				discountedPrice={discountedPrice}
				discountPercentage={discountPercentage}
				quantity={quantity}
				inCartHref={inCartHref}
				onRemoveClick={handleRemoveFromCart}
				onAddClick={handleAddToCart}
				onQuantityChangeClick={handleQuantityChange}
				processing={processing}
				showDetailButton={true}
				setIsOpen={setIsOpen}
				isOpen={isOpen}
				otherName={otherName}
				elements={elements}
				commonUse={commonUse}
				productData={productData}
				currency={currency}
				onViewDetail={handleViewItem}
				listId={listId}
				listName={listName}
			/>
		</>
	);
}

function ProductCardContent({
	heading,
	description,
	tags = [],
	features = [],
	imgSrc,
	showDefaultImage = false,
	price,
	discountedPrice,
	discountPercentage,
	quantity = null,
	inCartHref = null,
	onRemoveClick,
	onAddClick,
	onQuantityChangeClick,
	processing = false,
	showDetailButton = false,
	setIsOpen,
	forceExpand = false,
	// Laboratory test specific fields
	otherName = null,
	elements = null,
	commonUse = null,
	// =========== NUEVAS PROPS ===========
	productData = null,
	currency = 'MXN',
	onViewDetail,
	listId = 'product_grid',
	listName = 'Grid de productos',
	eventState = {},
	// ====================================
}) {
	const descRef = useRef(null);
	const [showExpand, setShowExpand] = useState(false);

	useEffect(() => {
		if (forceExpand) {
			setShowExpand(false);
			return;
		}
		function checkEllipsis() {
			const el = descRef.current;
			if (!el) return;
			setShowExpand(el.scrollHeight > el.clientHeight + 1);
		}
		checkEllipsis();
		window.addEventListener("resize", checkEllipsis);
		return () => window.removeEventListener("resize", checkEllipsis);
	}, [description, forceExpand]);

	// =========== DEBUG: Componente montado ===========
	useEffect(() => {
		console.log(`📊 ProductCardContent: "${heading?.substring(0, 30)}..."`);
		console.log('🎯 Event State:', eventState);
	}, [heading, eventState]);

	return (
		<>
			{/* In-cart header */}
			{inCartHref && (
				<div className="flex items-center justify-center rounded-t-xl bg-slate-100 py-2.5 dark:bg-slate-800">
					<CheckIcon className="mr-1 h-4 w-4 stroke-famedic-light" />
					<span className="text-sm font-semibold text-famedic-dark dark:text-white">
						En{" "}
						<Link className="underline" href={inCartHref}>
							tu carrito
						</Link>
					</span>
				</div>
			)}
			<div
				className={`h-full ${inCartHref ? "rounded-b-xl bg-slate-100 px-1.5 pb-1.5 dark:bg-slate-800" : ""}`}
			>
				<Card className="flex h-full flex-1 flex-col justify-between gap-6 p-6 lg:gap-8">
					{/* Body */}
					<div className="space-y-3">
						{/* Image */}
						{(showDefaultImage || imgSrc) && (
							<div className="mb-6 aspect-1 w-full rounded-lg lg:mb-8">
								{imgSrc ? (
									<img
										src={imgSrc}
										alt={heading}
										className="h-full w-full rounded-lg bg-transparent object-cover"
									/>
								) : (
									<div className="flex h-full w-full items-center justify-center">
										<PhotoIcon className="size-32 fill-zinc-200 dark:fill-slate-700" />
									</div>
								)}
							</div>
						)}

						{/* Heading */}
						<Subheading>{heading}</Subheading>

						{/* Tags */}
						{tags?.length > 0 && (
							<div className="flex flex-wrap gap-2">
								{tags.map((tag) => (
									<Badge
										key={tag.label}
										color={tag.color || "slate"}
									>
										<tag.icon className="size-4" />
										{tag.label}
									</Badge>
								))}
							</div>
						)}

						{/* Description */}
						<Disclosure defaultOpen={forceExpand}>
							{({ open }) => (
								<div>
									{!open && (
										<Text className="line-clamp-4">
											<span
												ref={descRef}
												className="block"
											>
												{description}
											</span>
										</Text>
									)}
									{showExpand && !open && !forceExpand && (
										<DisclosureButton
											as="div"
											className="mt-3 flex w-full items-center justify-center gap-1"
										>
											<Button outline>
												<BarsArrowDownIcon className="size-4" />
											</Button>
										</DisclosureButton>
									)}
									<DisclosurePanel>
										<Text>{description}</Text>
									</DisclosurePanel>
								</div>
							)}
						</Disclosure>

						{/* Features list */}
						{features.length > 0 && (
							<ul className="space-y-1">
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

						{forceExpand && (
							<>
								{otherName && forceExpand && (
									<div>
										<Text className="!text-xs">
											<Strong>
												También conocido como
											</Strong>
										</Text>
										<Text className="text-balance">
											{otherName}
										</Text>
									</div>
								)}
								{/* Elements for laboratory tests - only show in detail dialog */}
								{elements && forceExpand && (
									<div>
										<Text className="!text-xs">
											<Strong>
												Elementos analizados
											</Strong>
										</Text>
										<Text className="text-balance">
											{elements}
										</Text>
									</div>
								)}
								{/* Common use for laboratory tests - only show in detail dialog */}
								{commonUse && forceExpand && (
									<div>
										<Text className="!text-xs">
											<Strong>Usos comunes</Strong>
										</Text>
										<Text className="text-balance">
											{commonUse}
										</Text>
									</div>
								)}
							</>
						)}
						
						{/* Debug info solo en desarrollo */}
						{process.env.NODE_ENV === 'testing' && (
							<div className="rounded bg-gray-100 p-2 text-xs">
								<div className="font-semibold">GA4 Info:</div>
								<div>List: {listName}</div>
								<div>Price: ${extractPriceValue(price).toFixed(2)}</div>
								{eventState.view_item_sent && (
									<Badge color="green" className="mt-1">view_item ✓</Badge>
								)}
								{eventState.add_to_cart_sent && (
									<Badge color="blue" className="mt-1 ml-1">add_to_cart ✓</Badge>
								)}
							</div>
						)}
					</div>

					{/* Price */}
					<div>
						<div className="mb-2">
							{discountedPrice && discountPercentage > 0 && (
								<Text className="space-x-2">
									{discountPercentage && (
										<Badge color="lime">
											{discountPercentage}%
										</Badge>
									)}
									<span className="line-through">
										{discountedPrice}
									</span>
								</Text>
							)}
							<Text>
								<Strong>
									<span className="text-2xl text-famedic-dark dark:text-white">
										{price}
									</span>
									{process.env.NODE_ENV === 'testing' && (
										<span className="ml-2 text-sm text-gray-500">
											(${extractPriceValue(price).toFixed(2)})
										</span>
									)}
								</Strong>
							</Text>
						</div>

						{/* Cart actions */}
						{inCartHref ? (
							<>
								{!!quantity && (
									<div className="mb-4 mt-3 flex items-center gap-1">
										<Button
											onClick={() => {
												console.log('➖ Botón menos clickeado');
												quantity === 1
													? onRemoveClick()
													: onQuantityChangeClick(quantity - 1);
											}}
											plain
										>
											<MinusCircleIcon className="!size-6 fill-red-600 dark:fill-red-400" />
										</Button>
										<Subheading>
											<span className="px-2 text-xl">
												{quantity}
											</span>
										</Subheading>
										<Button
											onClick={() => {
												console.log('➕ Botón más clickeado');
												onQuantityChangeClick(quantity + 1);
											}}
											plain
										>
											<PlusCircleIcon className="!size-6 fill-green-600 dark:fill-green-400" />
										</Button>
									</div>
								)}
								<Button
									type="button"
									onClick={(e) => {
										console.log('🗑️ Botón eliminar clickeado');
										onRemoveClick(e);
									}}
									outline
									className="w-full"
								>
									<ShoppingCartIcon className="h-6 w-6 fill-red-300" />
									Eliminar
								</Button>
							</>
						) : (
							<>
								<Button
									type="button"
									color="famedic-light"
									className="hidden w-full dark:flex"
									onClick={(e) => {
										console.log('🛒 Botón agregar (dark) clickeado');
										onAddClick(e);
									}}
									disabled={processing}
								>
									<ShoppingCartIcon className="h-6 w-6 fill-famedic-lime" />
									Agregar
									{processing && (
										<ArrowPathIcon className="animate-spin" />
									)}
								</Button>
								<Button
									type="button"
									className="flex w-full dark:hidden"
									onClick={(e) => {
										console.log('🛒 Botón agregar (light) clickeado');
										onAddClick(e);
									}}
									disabled={processing}
								>
									<ShoppingCartIcon className="h-6 w-6" />
									Agregar
									{processing && (
										<ArrowPathIcon className="animate-spin" />
									)}
								</Button>
							</>
						)}

						{/* Detail button */}
						{showDetailButton && (
							<Button
								plain
								type="button"
								className="mt-2 w-full"
								onClick={() => {
									console.log('👁️ Botón "Ver detalle" clickeado');
									if (onViewDetail) {
										onViewDetail();
									} else {
										console.warn('⚠️ onViewDetail no definido');
										setIsOpen(true);
									}
								}}
							>
								<EyeIcon />
								Ver detalle
								{process.env.NODE_ENV === 'testing' && eventState.view_item_sent && (
									<Badge color="green" className="ml-2">✓</Badge>
								)}
							</Button>
						)}
					</div>
				</Card>
			</div>
		</>
	);
}

function ProductDetail({
	heading,
	description,
	tags = [],
	features = [],
	imgSrc,
	showDefaultImage = false,
	price,
	discountedPrice,
	discountPercentage,
	quantity = null,
	inCartHref = null,
	onRemoveClick,
	onAddClick,
	onQuantityChangeClick,
	processing = false,
	setIsOpen,
	isOpen,
	// Laboratory test specific fields
	otherName = null,
	elements = null,
	commonUse = null,
	// =========== NUEVAS PROPS ===========
	productData = null,
	currency = 'MXN',
	onViewDetail,
	listId = 'product_grid',
	listName = 'Grid de productos',
	// ====================================
}) {
	// DEBUG: Cuando se abre el diálogo
	useEffect(() => {
		if (isOpen) {
			console.log('📖 Diálogo ProductDetail ABIERTO');
		}
	}, [isOpen]);

	// Wrap onRemoveClick to close dialog after removing
	const handleRemoveClick = () => {
		if (onRemoveClick) onRemoveClick();
		if (setIsOpen) setIsOpen(false);
	};

	return (
		<Dialog open={isOpen} onClose={() => setIsOpen(false)} className="!p-0">
			<DialogBody className="!mt-0">
				<ProductCardContent
					heading={heading}
					description={description}
					tags={tags}
					features={features}
					imgSrc={imgSrc}
					showDefaultImage={showDefaultImage}
					price={price}
					discountedPrice={discountedPrice}
					discountPercentage={discountPercentage}
					quantity={quantity}
					inCartHref={inCartHref}
					onRemoveClick={handleRemoveClick}
					onAddClick={onAddClick}
					onQuantityChangeClick={onQuantityChangeClick}
					processing={processing}
					setIsOpen={setIsOpen}
					forceExpand={true}
					otherName={otherName}
					elements={elements}
					commonUse={commonUse}
					productData={productData}
					currency={currency}
					onViewDetail={onViewDetail}
					listId={listId}
					listName={listName}
				/>
			</DialogBody>
		</Dialog>
	);
}