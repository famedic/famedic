import ShoppingCartLayout from "@/Layouts/ShoppingCartLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text, TextLink } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import FeaturesGrid from "@/Components/FeaturesGrid";
import { useDeleteLaboratoryCartItem } from "@/Hooks/useDeleteLaboratoryCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import {
	CurrencyDollarIcon,
	DocumentCheckIcon,
	LockClosedIcon,
	QueueListIcon,
} from "@heroicons/react/24/solid";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import { useEffect, useState } from "react";

// ==================== FUNCIÓN DEBUG GA4 ACTUALIZADA ====================
const sendGA4Event = (eventName, ecommerceData, debugInfo = {}) => {
	if (typeof window === 'undefined') {
		console.warn('GA4: Window no disponible (SSR)');
		return;
	}
	
	window.dataLayer = window.dataLayer || [];
	
	// Limpiar ecommerce anterior (recomendación de Google)
	window.dataLayer.push({ ecommerce: null });
	
	// Crear evento limpio para GA4
	const ga4Event = {
		event: eventName,
		ecommerce: {
			...ecommerceData,
			// Asegurar que items sea un array
			items: Array.isArray(ecommerceData.items) ? ecommerceData.items : [ecommerceData.items].filter(Boolean)
		}
	};
	
	window.dataLayer.push(ga4Event);
	
	// Debug detallado
	console.groupCollapsed(`🛒 GA4 Cart Event: ${eventName}`);
	console.log('📦 Evento completo:', ga4Event);
	console.log('🔍 ecommerce data:', ecommerceData);
	console.log('📍 Debug info:', debugInfo);
	console.log('📊 dataLayer length:', window.dataLayer.length);
	
	// Mostrar últimos eventos
	const lastEvents = window.dataLayer.slice(-5).filter(e => e.event);
	console.log('🔄 Últimos 5 eventos:', lastEvents);
	console.groupEnd();
	
	// Notificación en desarrollo
	if (process.env.NODE_ENV === 'testing') {
		showCartDebugNotification(eventName, ecommerceData);
	}
	
	return ga4Event; // Retornar para testing
};

// Notificación específica para carrito
const showCartDebugNotification = (eventName, data) => {
	if (typeof window === 'undefined') return;
	
	// Eliminar notificaciones anteriores del mismo tipo
	const existingNotifications = document.querySelectorAll('.ga4-cart-notification');
	existingNotifications.forEach(n => n.remove());
	
	const notification = document.createElement('div');
	notification.className = 'ga4-cart-notification';
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
		animation: slideInTop 0.3s ease;
	`;
	
	const itemCount = data.items?.length || 0;
	const totalValue = data.value || 0;
	
	notification.innerHTML = `
		<div style="font-weight: bold; margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
			<span>🛒</span>
			<span>${eventName}</span>
		</div>
		<div style="font-size: 12px; opacity: 0.9;">
			${itemCount} ${itemCount === 1 ? 'producto' : 'productos'}<br/>
			💰 $${totalValue.toFixed(2)} ${data.currency || 'MXN'}
		</div>
	`;
	
	document.body.appendChild(notification);
	
	setTimeout(() => {
		notification.style.animation = 'slideOutTop 0.3s ease';
		setTimeout(() => notification.remove(), 300);
	}, 3000);
};

// Agregar estilos CSS para animaciones de carrito
if (typeof window !== 'undefined') {
	const styleId = 'ga4-cart-styles-enhanced';
	if (!document.getElementById(styleId)) {
		const style = document.createElement('style');
		style.id = styleId;
		style.textContent = `
			@keyframes slideInTop {
				from { transform: translateY(-20px); opacity: 0; }
				to { transform: translateY(0); opacity: 1; }
			}
			@keyframes slideOutTop {
				from { transform: translateY(0); opacity: 1; }
				to { transform: translateY(-20px); opacity: 0; }
			}
		`;
		document.head.appendChild(style);
	}
}
// =======================================================================

export default function LaboratoryShoppingCart({
	laboratoryBrand,
	laboratoryCarts,
	formattedTotal,
	formattedSubtotal,
	formattedDiscount,
}) {
	const {
		laboratoryCartItemToDelete,
		setLaboratoryCartItemToDelete,
		destroyLaboratoryCartItem,
		processing,
	} = useDeleteLaboratoryCartItem();

	// ============ STATE PARA CONTROLAR EVENTOS DUPLICADOS ============
	const [eventLog, setEventLog] = useState({
		view_cart_sent: false,
		last_remove_event: null,
		last_checkout_event: null
	});

	// ============ DEBUG: Información inicial ============
	useEffect(() => {
		console.group('🛒 LaboratoryShoppingCart Montado');
		console.log('🏷️ Laboratory Brand:', laboratoryBrand);
		console.log('📦 Carrito items:', laboratoryCarts?.[laboratoryBrand.value]?.length || 0);
		console.log('💰 Totales:', { 
			formattedTotal, 
			formattedSubtotal, 
			formattedDiscount,
			// Valores numéricos para debug
			numericTotal: extractPriceValue(formattedTotal),
			numericSubtotal: extractPriceValue(formattedSubtotal),
			numericDiscount: extractPriceValue(formattedDiscount)
		});
		console.log('🔧 Event Log:', eventLog);
		console.groupEnd();
	}, [laboratoryBrand, laboratoryCarts, eventLog]);

	// ============ HELPER: Extraer valor numérico del precio ============
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

	// ============ EVENTO: view_cart (al cargar la página) ============
	useEffect(() => {
		// Prevenir eventos duplicados
		if (eventLog.view_cart_sent) {
			console.log('🔄 view_cart ya enviado, omitiendo...');
			return;
		}
		
		// Esperar un momento para asegurar que la página esté completamente cargada
		const timer = setTimeout(() => {
			const cartItems = laboratoryCarts?.[laboratoryBrand.value] || [];
			
			if (cartItems.length > 0) {
				console.log('🔍 Enviando evento view_cart...');
				
				// Calcular valor total del carrito
				const totalValue = extractPriceValue(formattedTotal) || 
					cartItems.reduce((sum, item) => {
						return sum + (item.laboratory_test?.famedic_price_cents || 0) / 100;
					}, 0);
				
				// Preparar items para GA4 - ESTRUCTURA CORRECTA
				const items = cartItems.map((item, index) => {
					const test = item.laboratory_test;
					const itemValue = test.famedic_price_cents / 100;
					const publicValue = test.public_price_cents / 100;
					const discount = Math.max(0, publicValue - itemValue);
					
					// **ESTRUCTURA EXACTA QUE ESPERA GA4**
					return {
						item_id: test.id?.toString() || `lab_test_${test.id || index}`,
						item_name: test.name || 'Estudio de laboratorio',
						affiliation: 'Famedic Store',
						coupon: '', // Si hay cupones aplicados
						discount: discount,
						index: index,
						item_brand: laboratoryBrand.name || 'Laboratorio',
						item_category: test.category || 'Laboratory Tests',
						item_category2: test.subcategory || '',
						item_category3: test.type || '',
						item_list_id: 'shopping_cart',
						item_list_name: 'Carrito de compras',
						item_variant: test.type || 'standard',
						location_id: '', // Si tienes IDs de ubicación
						price: itemValue,
						quantity: 1,
						// google_business_vertical: 'retail' // Para retail
					};
				});
				
				// Enviar evento view_cart
				const sentEvent = sendGA4Event('view_cart', {
					currency: 'MXN',
					value: totalValue,
					items: items
				}, {
					itemCount: cartItems.length,
					brand: laboratoryBrand.value,
					timestamp: new Date().toISOString(),
					preventDuplicate: true
				});
				
				if (sentEvent) {
					setEventLog(prev => ({ ...prev, view_cart_sent: true }));
					console.log(`✅ Evento view_cart enviado con ${cartItems.length} items`);
					
					// Verificar estructura en consola
					console.log('📋 Estructura items enviada:', items.map(item => ({
						id: item.item_id,
						name: item.item_name,
						price: item.price,
						category: item.item_category
					})));
				}
			} else {
				console.log('🔄 Carrito vacío, no se envía view_cart');
			}
		}, 1000); // Delay para asegurar que GTM esté cargado
		
		return () => clearTimeout(timer);
	}, [laboratoryCarts, laboratoryBrand, formattedTotal, eventLog.view_cart_sent]);

	// ============ MANEJADOR: remove_from_cart MEJORADO ============
	const handleItemRemove = (laboratoryCartItem, index) => {
		console.log(`🗑️ Eliminando item ${index} del carrito`);
		
		if (!laboratoryCartItem?.laboratory_test) {
			console.error('❌ Error: laboratoryCartItem no tiene laboratory_test');
			return;
		}
		
		const test = laboratoryCartItem.laboratory_test;
		const itemValue = test.famedic_price_cents / 100;
		const publicValue = test.public_price_cents / 100;
		const discount = Math.max(0, publicValue - itemValue);
		
		// Prevenir eventos duplicados rápidos
		const now = Date.now();
		if (eventLog.last_remove_event && (now - eventLog.last_remove_event < 1000)) {
			console.warn('⚠️ Evento remove_from_cart demasiado rápido, omitiendo...');
			return;
		}
		
		// **ESTRUCTURA CORRECTA PARA remove_from_cart**
		const itemData = {
			item_id: test.id?.toString() || `lab_test_${test.id}`,
			item_name: test.name,
			affiliation: 'Famedic Store',
			coupon: '',
			discount: discount,
			index: index,
			item_brand: laboratoryBrand.name || 'Laboratorio',
			item_category: test.category || 'Laboratory Tests',
			item_category2: test.subcategory || '',
			item_category3: test.type || '',
			item_list_id: 'shopping_cart',
			item_list_name: 'Carrito de compras',
			item_variant: test.type || 'standard',
			location_id: '',
			price: itemValue,
			quantity: 1
		};
		
		// Enviar evento remove_from_cart
		sendGA4Event('remove_from_cart', {
			currency: 'MXN',
			value: itemValue,
			items: [itemData]
		}, {
			action: 'manual_removal',
			testId: test.id,
			testName: test.name.substring(0, 30),
			itemIndex: index,
			timestamp: now
		});
		
		// Actualizar log
		setEventLog(prev => ({ ...prev, last_remove_event: now }));
		
		// Llamar a la función original de eliminación
		setLaboratoryCartItemToDelete(laboratoryCartItem);
	};

	// ============ MANEJADOR: begin_checkout MEJORADO ============
	const handleCheckoutClick = (e) => {
		if (e) {
			e.preventDefault(); // Prevenir navegación inmediata
		}
		
		const cartItems = laboratoryCarts?.[laboratoryBrand.value] || [];
		
		if (cartItems.length === 0) {
			console.warn('⚠️ Carrito vacío, no se puede proceder a checkout');
			if (e) {
				e.stopPropagation();
			}
			return;
		}
		
		// Prevenir eventos duplicados
		const now = Date.now();
		if (eventLog.last_checkout_event && (now - eventLog.last_checkout_event < 2000)) {
			console.warn('⚠️ Evento begin_checkout demasiado rápido, omitiendo...');
			return;
		}
		
		console.log('🚀 Iniciando checkout...');
		
		// Calcular valor total
		const totalValue = extractPriceValue(formattedTotal) || 
			cartItems.reduce((sum, item) => {
				return sum + (item.laboratory_test?.famedic_price_cents || 0) / 100;
			}, 0);
		
		// Preparar items - ESTRUCTURA CORRECTA
		const items = cartItems.map((item, index) => {
			const test = item.laboratory_test;
			const itemValue = test.famedic_price_cents / 100;
			const publicValue = test.public_price_cents / 100;
			const discount = Math.max(0, publicValue - itemValue);
			
			return {
				item_id: test.id?.toString() || `lab_test_${test.id}`,
				item_name: test.name,
				affiliation: 'Famedic Store',
				coupon: '',
				discount: discount,
				index: index,
				item_brand: laboratoryBrand.name || 'Laboratorio',
				item_category: test.category || 'Laboratory Tests',
				item_category2: test.subcategory || '',
				item_category3: test.type || '',
				item_list_id: 'shopping_cart',
				item_list_name: 'Carrito de compras',
				item_variant: test.type || 'standard',
				location_id: '',
				price: itemValue,
				quantity: 1
			};
		});
		
		// Determinar si hay cupón aplicado
		const hasDiscount = formattedDiscount && formattedDiscount !== '$0.00' && formattedDiscount !== '-$0.00';
		const coupon = hasDiscount ? 'famedic_discount' : '';
		
		// Enviar evento begin_checkout
		sendGA4Event('begin_checkout', {
			currency: 'MXN',
			value: totalValue,
			items: items,
			coupon: coupon
		}, {
			step: 'checkout_initiated',
			itemCount: cartItems.length,
			totalValue: totalValue,
			hasDiscount: hasDiscount,
			discountAmount: formattedDiscount,
			timestamp: now
		});
		
		// Actualizar log
		setEventLog(prev => ({ ...prev, last_checkout_event: now }));
		
		console.log(`✅ Evento begin_checkout enviado`);
		
		// Navegar después de un breve delay para asegurar que el evento se envíe
		setTimeout(() => {
			console.log(`📍 Navegando a checkout...`);
			window.location.href = route("laboratory.checkout", {
				laboratory_brand: laboratoryBrand.value,
			});
		}, 300);
	};

	// ============ RENDER ============
	const cartItems = laboratoryCarts?.[laboratoryBrand.value] || [];
	const hasAppointmentItems = cartItems.filter(
		item => item.laboratory_test?.requires_appointment
	).length > 0;

	// Preparar lista de productData para ShoppingCartLayout
	const productDataList = cartItems.map((item, index) => {
		const test = item.laboratory_test;
		return {
			id: test.id,
			brand: laboratoryBrand.name,
			category: test.category,
			subcategory: test.subcategory,
			variant: test.type,
			index: index,
			price: test.famedic_price_cents / 100,
			name: test.name
		};
	});

	return (
		<>
			<ShoppingCartLayout
				title="Carrito de laboratorio"
				header={
					<div className="flex flex-col gap-6 sm:flex-row">
						<LaboratoryBrandCard
							src={`/images/gda/${laboratoryBrand.imageSrc}`}
							className="w-60 p-4"
						/>

						<GradientHeading noDivider>
							Carrito de laboratorio
						</GradientHeading>
					</div>
				}
				items={cartItems.map((laboratoryCartItem, index) => {
					const test = laboratoryCartItem.laboratory_test;
					const discountPercentage = Math.round(
						((test.public_price_cents - test.famedic_price_cents) /
							test.public_price_cents) * 100
					);
					
					return {
						heading: test.name,
						description: test.description,
						indications: test.indications,
						features: test.feature_list,
						price: test.formatted_famedic_price,
						discountedPrice: test.formatted_public_price,
						discountPercentage: discountPercentage,
						showDefaultImage: false,
						...(test.requires_appointment
							? { infoMessage: "Requiere cita" }
							: {}),
						onDestroy: () => handleItemRemove(laboratoryCartItem, index),
						// Pasar el índice para debugging
						itemIndex: index
					};
				})}
				emptyItemsContent={
					<>
						<Subheading>No hay estudios en tu carrito</Subheading>
						<Text className="w-full">
							Te invitamos a{" "}
							<TextLink
								href={route("laboratory-tests", {
									laboratory_brand: laboratoryBrand.value,
								})}
								onClick={() => {
									// Evento opcional: select_item al explorar estudios
									sendGA4Event('select_item', {
										item_list_id: 'empty_cart_suggestion',
										item_list_name: 'Sugerencias carrito vacío',
										items: [{
											item_id: 'explore_studies',
											item_name: 'Explorar estudios',
											index: 0,
											item_list_id: 'empty_cart_suggestion',
											item_list_name: 'Sugerencias carrito vacío'
										}]
									});
								}}
							>
								explorar los estudios de {laboratoryBrand.name}
							</TextLink>{" "}
						</Text>
					</>
				}
				summaryDetails={[
					{ value: formattedSubtotal, label: "Subtotal" },
					{ value: "-" + formattedDiscount, label: "Descuento" },
					{ value: formattedTotal, label: "Total" },
				]}
				summaryInfoMessage={
					hasAppointmentItems
						? {
								title: "Necesitarás una cita",
								message:
									"Algunos estudios requieren cita para asegurar que la sucursal cuente con el equipo necesario y que se cumplan todos los requisitos. Esto garantiza un servicio preciso y de calidad.",
							}
						: {}
				}
				checkoutUrl={route("laboratory.checkout", {
					laboratory_brand: laboratoryBrand.value,
				})}
				onCheckoutClick={handleCheckoutClick}
				// =========== NUEVAS PROPS PARA GA4 ===========
				currency="MXN"
				productDataList={productDataList}
				itemsCount={cartItems.length}
				totalValue={extractPriceValue(formattedTotal)}
				// =============================================
			>
				<Divider className="sm:mb-18 mb-12 mt-12 lg:mb-24" />

				{/* DEBUG: Panel de información del carrito mejorado */}
				{process.env.NODE_ENV === 'testing' && (
					<div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
						<Subheading className="text-blue-800 flex items-center gap-2">
							<span>🔍</span>
							<span>Debug Carrito GA4</span>
							<Badge color="blue" className="ml-2">
								{eventLog.view_cart_sent ? '✅ view_cart' : '⏳ view_cart'}
							</Badge>
						</Subheading>
						<div className="mt-3 grid grid-cols-1 gap-3 text-sm md:grid-cols-4">
							<div className="space-y-1">
								<div><strong>Items:</strong> {cartItems.length}</div>
								<div><strong>Valor total:</strong> ${extractPriceValue(formattedTotal).toFixed(2)}</div>
							</div>
							<div className="space-y-1">
								<div><strong>Marca:</strong> {laboratoryBrand.name}</div>
								<div><strong>Descuento:</strong> {formattedDiscount}</div>
							</div>
							<div className="space-y-1">
								<div><strong>Eventos enviados:</strong></div>
								<div className="flex gap-1">
									<Badge color={eventLog.view_cart_sent ? "green" : "gray"}>
										view_cart
									</Badge>
									<Badge color={eventLog.last_remove_event ? "green" : "gray"}>
										remove
									</Badge>
									<Badge color={eventLog.last_checkout_event ? "green" : "gray"}>
										checkout
									</Badge>
								</div>
							</div>
							<div className="space-y-2">
								<button
									onClick={() => {
										console.group('🔍 Debug Manual GA4');
										console.log('Carrito completo:', cartItems);
										console.log('dataLayer actual:', window.dataLayer);
										console.log('Últimos eventos GA4:', 
											window.dataLayer.filter(item => item.event).slice(-5));
										console.log('Event Log:', eventLog);
										console.groupEnd();
									}}
									className="w-full rounded bg-blue-100 px-3 py-2 text-xs text-blue-700 hover:bg-blue-200"
								>
									Mostrar debug en consola
								</button>
								<button
									onClick={() => {
										// Test event para verificar GTM
										sendGA4Event('test_cart_event', {
											currency: 'MXN',
											value: 100,
											items: [{
												item_id: 'test_item',
												item_name: 'Producto de prueba',
												price: 100,
												quantity: 1
											}]
										}, { test: true });
									}}
									className="w-full rounded bg-green-100 px-3 py-2 text-xs text-green-700 hover:bg-green-200 mt-1"
								>
									Test Event GA4
								</button>
							</div>
						</div>
					</div>
				)}

				<FeaturesGrid
					features={[
						{
							name: "Precios exclusivos",
							icon: CurrencyDollarIcon,
							description:
								"Obtén todos tus estudios a precios verdaderamente preferenciales.",
						},
						{
							name: "Garantía y seguridad",
							icon: LockClosedIcon,
							description:
								"Tus compras están seguras. Si no recibes el servicio o producto, tendrás una devolución total.",
						},
						{
							name: "Facturación sencilla",
							icon: DocumentCheckIcon,
							description:
								"Con tus perfiles fiscales, es muy fácil solicitar tus facturas.",
						},
						{
							name: "Historial de compras y resultados",
							icon: QueueListIcon,
							description:
								"Consulta todas tus compras, facturas y resultados de laboratorios.",
						},
					]}
				/>
			</ShoppingCartLayout>

			<DeleteConfirmationModal
				isOpen={!!laboratoryCartItemToDelete}
				close={() => setLaboratoryCartItemToDelete(null)}
				title="Quitar del carrito"
				description={`¿Estás seguro de que deseas quitar "${
					laboratoryCartItemToDelete?.laboratory_test.name
				}" del carrito?`}
				processing={processing}
				destroy={() => {
					// Confirmar eliminación
					if (laboratoryCartItemToDelete) {
						console.log('✅ Confirmando eliminación del carrito');
						destroyLaboratoryCartItem();
						
						// Resetear log después de eliminar
						setEventLog(prev => ({ ...prev, view_cart_sent: false }));
					}
				}}
			/>
		</>
	);
}

// =========== COMPONENTE Badge (si no está importado) ===========
import { Badge } from "@/Components/Catalyst/badge";
// ================================================================