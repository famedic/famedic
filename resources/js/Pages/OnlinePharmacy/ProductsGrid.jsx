export default function ProductsGrid({
	products,
	onlinePharmacyCartItems,
	search,
	category,
	updateSearch,
}) {
	if (!products.length) {
		return <EmptyListCard />;
	}
	const {
		data,
		post,
		processing: storeProcessing,
	} = useForm({
		vitau_product: "",
	});

	const [submittingId, setSubmittingId] = useState(null);

	const addVitauProduct = (vitauProduct) => {
		if (!storeProcessing) {
			setSubmittingId(vitauProduct.id);
			data.vitau_product = vitauProduct.id;
			post(route("online-pharmacy-cart-items.store"), {
				preserveScroll: true,
				onFinish: () => setSubmittingId(null),
			});
		}
	};

	const {
		onlinePharmacyCartItemToDelete,
		setOnlinePharmacyCartItemToDelete,
		destroyOnlinePharmacyCartItem,
		processing,
		updateOnlinePharmacyCartItemQuantity,
	} = useDeleteOnlinePharmacyCartItem();

	const formattedPrice = (price) => {
		return new Intl.NumberFormat("en-US", {
			style: "currency",
			currency: "USD",
		}).format(Number(price));
	};

	return (
		<div className="space-y-4">
			{(search || category) && (
				<div>
					{search && (
						<Text>Mostrando resultados de busqueda "{search}"</Text>
					)}

					{category && (
						<div className="mt-2 flex items-center">
							<Text>Categoría</Text>
							<BadgeButton
								onClick={() => updateSearch(search, "")}
								className="ml-2"
							>
								<span className="flex items-center">
									{category}
									<XMarkIcon className="size-5 fill-red-700 dark:fill-red-300" />
								</span>
							</BadgeButton>
						</div>
					)}
				</div>
			)}

			<div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3 lg:gap-8 xl:grid-cols-4">
				{products.map((product) => (
					<ProductCard
						key={product.id}
						heading={product.base.name}
						description={product.presentation}
						inCartHref={
							onlinePharmacyCartItems.filter(
								(onlinePharmacyCartItem) =>
									onlinePharmacyCartItem.vitau_product_id ===
									product.id,
							).length > 0
								? route("online-pharmacy.shopping-cart")
								: false
						}
						price={formattedPrice(product.price) + " MXN"}
						tags={[
							product.price > 1500 && {
								icon: TruckIcon,
								label: " !Envío gratis!",
								color: "famedic-lime",
							},
							product.base?.category?.name && {
								icon: TagIcon,
								label: product.base.category.name,
							},
						].filter(Boolean)}
						imgSrc={product.default_image}
						showDefaultImage={true}
						onRemoveClick={() =>
							setOnlinePharmacyCartItemToDelete(
								onlinePharmacyCartItems.find(
									(onlinePharmacyCartItem) =>
										onlinePharmacyCartItem.vitau_product_id ==
										product.id,
								),
							)
						}
						onAddClick={() => addVitauProduct(product)}
						quantity={
							onlinePharmacyCartItems.find(
								(item) => item.vitau_product_id === product.id,
							)?.quantity ?? 1
						}
						onQuantityChangeClick={(newQty) =>
							updateOnlinePharmacyCartItemQuantity(
								onlinePharmacyCartItems.find(
									(item) =>
										item.vitau_product_id === product.id,
								),
								newQty,
							)
						}
						processing={submittingId === product.id}
					/>
				))}
			</div>
			<DeleteConfirmationModal
				isOpen={!!onlinePharmacyCartItemToDelete}
				close={() => setOnlinePharmacyCartItemToDelete(null)}
				title="Eliminar del carrito"
				description={`¿Estás seguro de que deseas eliminar ${onlinePharmacyCartItemToDelete?.vitau_product?.base.name} del carrito?`}
				processing={processing}
				destroy={destroyOnlinePharmacyCartItem}
			/>
		</div>
	);
}

import ProductCard from "@/Components/ProductCard";
import { BadgeButton } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { TagIcon, TruckIcon, XMarkIcon } from "@heroicons/react/16/solid";
import { useForm } from "@inertiajs/react";
import { useDeleteOnlinePharmacyCartItem } from "@/Hooks/useDeleteOnlinePharmacyCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { useState } from "react";
import EmptyListCard from "@/Components/EmptyListCard";
