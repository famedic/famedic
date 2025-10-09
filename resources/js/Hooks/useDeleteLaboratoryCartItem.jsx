import { useState } from "react";
import { useForm } from "@inertiajs/react";

export function useDeleteLaboratoryCartItem() {
	const [laboratoryCartItemToDelete, setLaboratoryCartItemToDelete] =
		useState(null);

	const { delete: destroy, processing } = useForm({});

	const destroyLaboratoryCartItem = () => {
		destroy(
			route("laboratory-cart-items.destroy", {
				laboratory_cart_item: laboratoryCartItemToDelete,
			}),
			{
				preserveScroll: true,
				onSuccess: () => setLaboratoryCartItemToDelete(null),
			},
		);
	};

	return {
		laboratoryCartItemToDelete,
		setLaboratoryCartItemToDelete,
		processing,
		destroyLaboratoryCartItem,
	};
}
