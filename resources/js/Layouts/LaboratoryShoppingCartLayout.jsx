import FamedicLayout from "@/Layouts/FamedicLayout";
import SideBar from "@/Layouts/FamedicLayout/SideBar";
import NavBar from "@/Layouts/FamedicLayout/NavBar";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text, TextLink } from "@/Components/Catalyst/text";
import { PlusIcon } from "@heroicons/react/16/solid";
import LabStudyCard from "@/Components/LaboratoryCart/LabStudyCard";
import OrderSummary from "@/Components/LaboratoryCart/OrderSummary";
import StickyCheckoutBar from "@/Components/LaboratoryCart/StickyCheckoutBar";

const sendGA4Event = (eventName, ecommerceData, debugInfo = {}) => {
	if (typeof window === "undefined") {
		return;
	}

	window.dataLayer = window.dataLayer || [];
	window.dataLayer.push({
		event: eventName,
		ecommerce: {
			...ecommerceData,
			items: Array.isArray(ecommerceData.items) ? ecommerceData.items : [],
		},
	});

	if (process.env.NODE_ENV === "testing") {
		console.groupCollapsed(`🛒 GA4 Cart Layout Event: ${eventName}`);
		console.log(debugInfo);
		console.groupEnd();
	}
};

const extractPriceValue = (priceString) => {
	if (!priceString) {
		return 0;
	}

	const numericString = priceString.replace(/[^0-9.,]/g, "");
	const normalized = numericString.replace(",", ".");
	const value = parseFloat(normalized);

	return Number.isNaN(value) ? 0 : value;
};

export default function LaboratoryShoppingCartLayout({
	title,
	header,
	studyItems = [],
	emptyItemsContent,
	formattedSubtotal,
	formattedDiscount,
	formattedTotal,
	checkoutUrl,
	onCheckoutClick,
	summaryExtra = null,
	appointmentNotice = null,
	addMoreHref = null,
	addMoreLabel = "+ Agregar más estudios",
	currency = "MXN",
	productDataList = [],
	preferredStore = null,
	children,
}) {
	const hasItems = studyItems.length > 0;

	const handleRemoveItem = (item, index) => {
		const priceValue = extractPriceValue(item.formattedPrice);
		const ga4Item = {
			item_id: productDataList[index]?.id?.toString() || `item_${index}`,
			item_name: item.name || "Estudio de laboratorio",
			affiliation: "Famedic Store",
			discount: 0,
			index,
			item_brand: productDataList[index]?.brand || "Famedic",
			item_category: "Laboratory Tests",
			price: priceValue,
			quantity: 1,
			item_list_id: "shopping_cart",
			item_list_name: "Carrito de compras",
		};

		sendGA4Event(
			"remove_from_cart",
			{
				currency,
				value: priceValue,
				items: [ga4Item],
			},
			{ action: "cart_layout_removal", itemIndex: index },
		);

		item.onRemove?.();
	};

	const handleCheckout = (event) => {
		event.preventDefault();
		onCheckoutClick?.(event);
	};

	return (
		<FamedicLayout title={title} navbar={<NavBar />} sidebar={<SideBar />}>
			{header}

			<div
				className={
					hasItems ? "pb-28 lg:pb-0" : "pb-6"
				}
			>
				<div className="lg:grid lg:grid-cols-12 lg:items-start lg:gap-x-10 xl:gap-x-14">
					<section className="lg:col-span-7 xl:col-span-8">
						{hasItems ? (
							<>
								<Subheading className="mb-3 text-base sm:text-lg">
									Estudios en tu carrito
									<span className="ml-2 font-normal text-zinc-500 dark:text-slate-400">
										({studyItems.length})
									</span>
								</Subheading>

								<ul
									role="list"
									className="space-y-3"
									aria-label="Estudios en tu carrito"
								>
									{studyItems.map((item, index) => (
										<LabStudyCard
											key={item.id ?? index}
											name={item.name}
											formattedPrice={item.formattedPrice}
											requiresAppointment={
												item.requiresAppointment
											}
											onRemove={() =>
												handleRemoveItem(item, index)
											}
										/>
									))}
								</ul>

								{addMoreHref && (
									<div className="mt-4">
										<TextLink
											href={addMoreHref}
											className="inline-flex items-center gap-1.5 text-sm font-medium"
										>
											<PlusIcon className="size-4" />
											{addMoreLabel}
										</TextLink>
									</div>
								)}
							</>
						) : (
							<div className="py-8 sm:py-10">{emptyItemsContent}</div>
						)}

						{hasItems && children}
					</section>

					{hasItems && (
						<aside className="hidden lg:col-span-5 lg:block xl:col-span-4">
							<OrderSummary
								formattedSubtotal={formattedSubtotal}
								formattedDiscount={formattedDiscount}
								formattedTotal={formattedTotal}
								checkoutUrl={checkoutUrl}
								onCheckoutClick={handleCheckout}
								summaryExtra={summaryExtra}
								appointmentNotice={appointmentNotice}
								itemsCount={studyItems.length}
								preferredStore={preferredStore}
							/>
						</aside>
					)}
				</div>
			</div>

			{hasItems && (
				<StickyCheckoutBar
					formattedTotal={formattedTotal}
					checkoutUrl={checkoutUrl}
					onCheckoutClick={handleCheckout}
				/>
			)}
		</FamedicLayout>
	);
}
