import { useForm } from "@inertiajs/react";
import { useState, useEffect } from "react";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";

export default function PaymentMethodDeleteConfirmation({
	isOpen,
	close,
	paymentMethod,
}) {
	const { delete: destroy, processing } = useForm({});
	const [cachedPaymentMethod, setCachedPaymentMethod] =
		useState(paymentMethod);

	useEffect(() => {
		if (isOpen) {
			setCachedPaymentMethod(paymentMethod);
		}
	}, [isOpen]);

	const handleDestroy = () => {
		if (!processing && cachedPaymentMethod) {
			destroy(
				route("payment-methods.destroy", {
					payment_method: cachedPaymentMethod.id,
				}),
				{
					preserveScroll: true,
					onSuccess: () => close(),
				},
			);
		}
	};

	return (
		<DeleteConfirmationModal
			isOpen={isOpen}
			close={close}
			title={`Eliminar tarjeta "${cachedPaymentMethod?.card.brand} ${cachedPaymentMethod?.card.last4}"`}
			description="¿Estás seguro de que deseas eliminar tu tarjeta?"
			processing={processing}
			destroy={handleDestroy}
		/>
	);
}
