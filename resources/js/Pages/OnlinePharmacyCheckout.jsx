export default function OnlinePharmacyCheckout({
	onlinePharmacyCart,
	total,
	formattedTotal,
	formattedSubtotal,
	formattedTax,
	formattedDelivery,
	addresses,
	paymentMethods,
	hasOdessaPay,
	contacts,
}) {
	const {
		onlinePharmacyCartItemToDelete,
		setOnlinePharmacyCartItemToDelete,
		destroyOnlinePharmacyCartItem,
		processing,
	} = useDeleteOnlinePharmacyCartItem();

	const initialFormData = {
		contact:
			new URLSearchParams(window.location.search).get("contact") || null,

		address:
			new URLSearchParams(window.location.search).get("address") || null,

		payment_method:
			new URLSearchParams(window.location.search).get("payment_method") ||
			null,
	};

	const {
		data,
		transform,
		setData,
		errors,
		clearErrors,
		post,
		processing: checkoutProcessing,
	} = useForm(initialFormData);

	transform((data) => {
		return {
			...data,
			total: total,
		};
	});

	const submit = (e) => {
		e.preventDefault();

		if (!checkoutProcessing && !!total) {
			post(route("online-pharmacy.checkout.store"));
		}
	};

	const [showAddressForm, setShowAddressForm] = useState(
		() => addresses.length < 1,
	);
	const [showContactForm, setShowContactForm] = useState(
		() => contacts.length < 1,
	);

	const toggleAddressForm = () => setShowAddressForm((prev) => !prev);
	const toggleContactForm = () => setShowContactForm((prev) => !prev);

	const contactStepIsComplete = useMemo(() => {
		return !!data.contact || showContactForm;
	}, [data.contact, showContactForm]);

	const addressStepIsComplete = useMemo(() => {
		return !!data.address || showAddressForm;
	}, [data.address, showAddressForm]);

	const paymentMethodStepIsComplete = useMemo(() => {
		return !!data.payment_method;
	}, [data.payment_method]);

	useEffect(() => {
		if (data.address) {
			const zipcode = addresses.find(
				(address) => address.id === data.address,
			)?.zipcode;

			router.get(
				route("online-pharmacy.checkout", { zipcode }),
				{},
				{ preserveState: true, preserveScroll: true },
			);
		}
	}, [data.address]);

	const addCardReturnUrl = useMemo(() => {
		const filteredData = Object.fromEntries(
			Object.entries(data).filter(
				([_, value]) =>
					value !== undefined && value !== null && value !== "",
			),
		);

		return route("online-pharmacy.checkout", {
			...filteredData,
		});
	}, [data]);

	return (
		<>
			<CheckoutLayout
				title="Completar compra"
				header={
					<div className="flex flex-col gap-3">
						<GradientHeading noDivider>
							Completar compra
						</GradientHeading>
						<Subheading>
							<span className="text-xl lg:text-2xl">
								Vamos a asegurarnos de que todo sea
								correcto.{" "}
							</span>
						</Subheading>
					</div>
				}
				summaryDetails={[
					{ value: formattedSubtotal, label: "Subtotal" },
					{
						value: formattedTax || "Pendiente de cálculo",
						label: "Impuesto",
					},
					{
						value: formattedDelivery || "Pendiente de cálculo",
						label: "Envío",
					},
					{
						value: data.address
							? formattedTotal?.trim()
								? formattedTotal
								: formattedSubtotal
							: formattedSubtotal,
						label: "Total",
					},
				]}
				items={onlinePharmacyCart.map((onlinePharmacyCartItem) => ({
					heading: onlinePharmacyCartItem.vitau_product.base.name,
					description:
						onlinePharmacyCartItem.vitau_product.presentation,
					imgSrc:
						onlinePharmacyCartItem.vitau_product.default_image ||
						null,
					price: onlinePharmacyCartItem.formatted_price,
					quantity: onlinePharmacyCartItem.quantity,
					onDestroy: () =>
						setOnlinePharmacyCartItemToDelete(
							onlinePharmacyCartItem,
						),
				}))}
				paymentDisabled={
					checkoutProcessing ||
					!addressStepIsComplete ||
					!contactStepIsComplete ||
					!paymentMethodStepIsComplete
				}
				paymentProcessing={checkoutProcessing}
				submit={submit}
			>
				<ContactStep
					description="En caso de requerir receta, la información del paciente debe coincidir."
					data={data}
					setData={setData}
					errors={errors}
					error={errors.contact}
					clearErrors={clearErrors}
					contacts={contacts}
					toggleContactForm={toggleContactForm}
					showContactForm={showContactForm}
				/>
				<AddressStep
					disabled={!contactStepIsComplete}
					data={data}
					setData={setData}
					errors={errors}
					error={errors.address}
					clearErrors={clearErrors}
					addresses={addresses}
					toggleAddressForm={toggleAddressForm}
					showAddressForm={showAddressForm}
				/>
				<PaymentMethodStep
					disabled={!addressStepIsComplete || !contactStepIsComplete}
					data={data}
					setData={setData}
					errors={errors}
					error={errors.payment_method}
					clearErrors={clearErrors}
					paymentMethods={paymentMethods}
					hasOdessaPay={hasOdessaPay}
					addCardReturnUrl={addCardReturnUrl}
				/>
			</CheckoutLayout>

			<DeleteConfirmationModal
				isOpen={!!onlinePharmacyCartItemToDelete}
				close={() => setOnlinePharmacyCartItemToDelete(null)}
				title="Quitar del carrito"
				description={`¿Estás seguro de que deseas quitarlo ${onlinePharmacyCartItemToDelete?.vitau_product?.base.name} del carrito?`}
				processing={processing}
				destroy={destroyOnlinePharmacyCartItem}
			/>
		</>
	);
}

import { useDeleteOnlinePharmacyCartItem } from "@/Hooks/useDeleteOnlinePharmacyCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import CheckoutLayout from "@/Layouts/CheckoutLayout";
import { router, useForm } from "@inertiajs/react";
import { useState, useMemo, useEffect } from "react";
import AddressStep from "@/Components/Checkout/AddressStep";
import PaymentMethodStep from "@/Components/Checkout/PaymentMethodStep";
import ContactStep from "@/Components/Checkout/ContactStep";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
