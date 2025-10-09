export default function LaboratoryCheckout({
	laboratoryAppointment,
	laboratoryCarts,
	laboratoryBrand,
	total,
	formattedTotal,
	formattedSubtotal,
	formattedDiscount,
	addresses,
	paymentMethods,
	hasOdessaPay,
	contacts,
}) {
	const {
		laboratoryCartItemToDelete,
		setLaboratoryCartItemToDelete,
		destroyLaboratoryCartItem,
		processing,
	} = useDeleteLaboratoryCartItem();

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
			laboratory_appointment: laboratoryAppointment?.id,
			total: total,
		};
	});

	const submit = (e) => {
		e.preventDefault();

		if (!checkoutProcessing) {
			post(
				route("laboratory.checkout.store", {
					laboratory_brand: laboratoryBrand.value,
				}),
			);
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
		return !!data.contact || laboratoryAppointment;
	}, [data.contact, laboratoryAppointment]);

	const addressStepIsComplete = useMemo(() => {
		return !!data.address;
	}, [data.address]);

	const paymentMethodStepIsComplete = useMemo(() => {
		return !!data.payment_method;
	}, [data.payment_method]);

	const addCardReturnUrl = useMemo(() => {
		const filteredData = Object.fromEntries(
			Object.entries(data).filter(
				([_, value]) =>
					value !== undefined && value !== null && value !== "",
			),
		);

		return route("laboratory.checkout", {
			laboratory_brand: laboratoryBrand.value,
			...filteredData,
		});
	}, [data]);

	return (
		<>
			<CheckoutLayout
				title="Completar compra"
				header={
					<div className="flex flex-col gap-8 sm:flex-row sm:items-center">
						<LaboratoryBrandCard
							src={`/images/gda/${laboratoryBrand.imageSrc}`}
							className="w-48 p-4"
						/>

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
					</div>
				}
				laboratoryBrand={laboratoryBrand}
				summaryDetails={[
					{ value: formattedSubtotal, label: "Subtotal" },
					{ value: "-" + formattedDiscount, label: "Descuento" },
					{ value: formattedTotal, label: "Total" },
				]}
				items={laboratoryCarts[laboratoryBrand.value].map(
					(laboratoryCartItem) => ({
						heading: laboratoryCartItem.laboratory_test.name,
						description:
							laboratoryCartItem.laboratory_test.description,
						indications:
							laboratoryCartItem.laboratory_test.indications,
						features:
							laboratoryCartItem.laboratory_test.feature_list,
						price: laboratoryCartItem.laboratory_test
							.formatted_famedic_price,
						discountedPrice:
							laboratoryCartItem.laboratory_test
								.formatted_public_price,
						discountPercentage: Math.round(
							((laboratoryCartItem.laboratory_test
								.public_price_cents -
								laboratoryCartItem.laboratory_test
									.famedic_price_cents) /
								laboratoryCartItem.laboratory_test
									.public_price_cents) *
								100,
						),
						showDefaultImage: false,
						...(laboratoryCartItem.laboratory_test
							.requires_appointment
							? { infoMessage: "Requiere cita" }
							: {}),
						onDestroy: () =>
							setLaboratoryCartItemToDelete(laboratoryCartItem),
					}),
				)}
				paymentDisabled={
					checkoutProcessing ||
					!addressStepIsComplete ||
					!contactStepIsComplete ||
					!paymentMethodStepIsComplete
				}
				paymentProcessing={checkoutProcessing}
				submit={submit}
			>
				<>
					{laboratoryAppointment ? (
						<LaboratoryAppointmentInfo
							data={data}
							setData={setData}
							laboratoryAppointment={laboratoryAppointment}
							errors={errors}
						/>
					) : (
						<ContactStep
							data={data}
							setData={setData}
							errors={errors}
							error={errors.contact}
							clearErrors={clearErrors}
							contacts={contacts}
							toggleContactForm={toggleContactForm}
							showContactForm={showContactForm}
						/>
					)}
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
						disabled={
							!addressStepIsComplete || !contactStepIsComplete
						}
						data={data}
						setData={setData}
						errors={errors}
						error={errors.payment_method}
						clearErrors={clearErrors}
						paymentMethods={paymentMethods}
						hasOdessaPay={hasOdessaPay}
						addCardReturnUrl={addCardReturnUrl}
					/>
				</>
			</CheckoutLayout>

			<DeleteConfirmationModal
				isOpen={!!laboratoryCartItemToDelete}
				close={() => setLaboratoryCartItemToDelete(null)}
				title="Eliminar del carrito"
				description={`¿Estás seguro de que deseas eliminar ${laboratoryCartItemToDelete?.laboratory_test.name} del carrito?`}
				processing={processing}
				destroy={destroyLaboratoryCartItem}
			/>
		</>
	);
}

function LaboratoryAppointmentInfo({
	laboratoryAppointment,
	data,
	setData,
	errors,
}) {
	return (
		<>
			<Card className="bg-zinc-50 px-4 py-6 sm:p-6 lg:p-8">
				<div className="flex items-start gap-2">
					<CheckCircleIcon className="mt-0.5 size-6 flex-shrink-0 rounded-full fill-green-600 dark:fill-famedic-lime" />
					<div className="space-y-3">
						<Subheading>Paciente</Subheading>
						<div>
							<Text>
								{laboratoryAppointment.patient_full_name}
							</Text>
							<Text>
								{laboratoryAppointment.formatted_patient_gender}
							</Text>
							<Text>
								{
									laboratoryAppointment.formatted_patient_birth_date
								}
							</Text>
							<Text>{laboratoryAppointment.patient_phone}</Text>
						</div>
						<SwitchField>
							<Label>¿Guardar paciente?</Label>
							<Description>
								Si decides guardar el paciente, podrás usarlo en
								futuras compras.
							</Description>
							<Switch
								checked={data.save_contact}
								onChange={(value) =>
									setData("save_contact", value)
								}
							/>
							{errors.save_contact && (
								<ErrorMessage>
									{errors.save_contact}
								</ErrorMessage>
							)}
						</SwitchField>
					</div>
				</div>
			</Card>

			<Card className="bg-zinc-50 px-4 py-6 sm:p-6 lg:p-8">
				<div className="flex items-start gap-2">
					<CheckCircleIcon className="mt-0.5 size-6 flex-shrink-0 rounded-full fill-green-600 dark:fill-famedic-lime" />
					<div className="space-y-3">
						<Subheading>Cita en laboratorio</Subheading>
						<div>
							<Text>
								{laboratoryAppointment.laboratory_store.name}
							</Text>
							<Text className="max-w-sm">
								<span className="text-xs">
									{
										laboratoryAppointment.laboratory_store
											.address
									}
								</span>
							</Text>
							<Badge color="sky" className="mt-2">
								{
									laboratoryAppointment.formatted_appointment_date
								}
							</Badge>
						</div>
					</div>
				</div>
			</Card>

			<div>
				<Divider className="mb-2" />
				<Text>
					Si necesitas hacer modificaciones a tu cita o información de
					paciente, favor de contactarnos al{" "}
					<a href="tel:5566515232" target="_blank">
						<Button
							plain
							className="text-zinc-950 underline decoration-zinc-950/50 data-[hover]:decoration-zinc-950 dark:text-white dark:decoration-white/50 dark:data-[hover]:decoration-white"
						>
							<PhoneIcon />
							55 6651 5232
						</Button>
					</a>
				</Text>
				<Divider className="mt-2" />
			</div>
		</>
	);
}

import { useDeleteLaboratoryCartItem } from "@/Hooks/useDeleteLaboratoryCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { Badge } from "@/Components/Catalyst/badge";
import { PhoneIcon } from "@heroicons/react/16/solid";
import { CheckCircleIcon } from "@heroicons/react/24/solid";
import {
	ErrorMessage,
	Description,
	Label,
} from "@/Components/Catalyst/fieldset";
import { Subheading } from "@/Components/Catalyst/heading";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { Text } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import CheckoutLayout from "@/Layouts/CheckoutLayout";
import { useForm } from "@inertiajs/react";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { useState, useMemo } from "react";
import ContactStep from "@/Components/Checkout/ContactStep";
import AddressStep from "@/Components/Checkout/AddressStep";
import PaymentMethodStep from "@/Components/Checkout/PaymentMethodStep";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
