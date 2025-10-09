import { useState } from "react";
import { router, useForm } from "@inertiajs/react";

export function useDeleteOnlinePharmacyCartItem() {
	const [onlinePharmacyCartItemToDelete, setOnlinePharmacyCartItemToDelete] =
		useState(null);

	const { delete: destroy, processing } = useForm({});

	const destroyOnlinePharmacyCartItem = () => {
		destroy(
			route("online-pharmacy-cart-items.destroy", {
				online_pharmacy_cart_item: onlinePharmacyCartItemToDelete,
			}),
			{
				preserveScroll: true,
				onSuccess: () => setOnlinePharmacyCartItemToDelete(null),
			},
		);
	};

	const updateOnlinePharmacyCartItemQuantity = (
		onlinePharmacyCartItem,
		newQuantity,
	) => {
		router.put(
			route("online-pharmacy-cart-items.update", {
				online_pharmacy_cart_item: onlinePharmacyCartItem,
			}),
			{ quantity: newQuantity },
			{
				preserveScroll: true,
			},
		);
	};

	return {
		onlinePharmacyCartItemToDelete,
		setOnlinePharmacyCartItemToDelete,
		processing,
		destroyOnlinePharmacyCartItem,
		updateOnlinePharmacyCartItemQuantity,
	};
}
