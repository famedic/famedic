export default function AddressStep({
	data,
	setData,
	errors,
	clearErrors,
	description = "Asegúrate de que la dirección sea correcta.",
	addresses,
	toggleAddressForm,
	showAddressForm,
	...props
}) {
	const selectedAddress = useMemo(() => {
		return addresses.find((address) => address.id == data.address);
	}, [data.address]);

	const stepHeading = useMemo(() => {
		if (showAddressForm) {
			return "Ingresa la dirección del paciente";
		}
		return data.address ? "Dirección" : "Selecciona la dirección";
	}, [showAddressForm, data.address]);

	return (
		<CheckoutStep
			{...props}
			IconComponent={MapIcon}
			heading={stepHeading}
			description={description}
			defaultOpen={!selectedAddress}
			selectedContent={
				<>
					<Text>
						{selectedAddress?.street} {selectedAddress?.number}
					</Text>
					<Text>
						{selectedAddress?.neighborhood},{" "}
						{selectedAddress?.zipcode}
					</Text>
					<Text>{`${selectedAddress?.state}, ${selectedAddress?.city} `}</Text>
				</>
			}
			formContent={
				<>
					{addresses.length > 0 && !showAddressForm && (
						<AddressSelection
							setData={setData}
							addresses={addresses}
							toggleAddressForm={toggleAddressForm}
							clearErrors={clearErrors}
						/>
					)}
					{showAddressForm && (
						<AddressForm
							setCheckoutData={setData}
							toggleAddressForm={toggleAddressForm}
							showAddressesButton={addresses.length > 0}
						/>
					)}
				</>
			}
			onClickEdit={() => setData("address", null)}
		/>
	);
}

function AddressSelection({
	setData,
	addresses,
	toggleAddressForm,
	clearErrors,
}) {
	const close = useClose();

	const selectAddress = (address) => {
		setData("address", address.id);
		clearErrors("address");
		clearErrors("address_street");
		clearErrors("address_number");
		clearErrors("address_neighborhood");
		clearErrors("address_state");
		clearErrors("address_city");
		clearErrors("address_zipcode");
		close();
	};

	return (
		<ul className="mt-3 grid gap-8 sm:grid-cols-2">
			{addresses.map((address) => (
				<CheckoutSelectionCard
					key={address.id}
					onClick={() => selectAddress(address)}
					heading={address.street + " " + address.number}
					IconComponent={MapPinIcon}
				>
					<Text>
						{address.street} {address.number}
					</Text>
					<Text>
						{address.neighborhood}, {address.zipcode}
					</Text>
					<Text>{`${address.state}, ${address.city} `}</Text>
				</CheckoutSelectionCard>
			))}
			<CheckoutSelectionCard
				onClick={toggleAddressForm}
				heading="Nueva dirección"
				IconComponent={PlusIcon}
				greenIcon
			>
				<Text className="line-clamp-3 max-w-64">
					Puedes agregar una nueva dirección y guardarla para futuras
					compras, si lo deseas.
				</Text>
			</CheckoutSelectionCard>
		</ul>
	);
}

function AddressForm({
	toggleAddressForm,
	showAddressesButton,
	setCheckoutData,
}) {
	const close = useClose();

	const { mexicanStates } = usePage().props;

	const { data, setData, processing, errors, setError } = useForm({
		street: "",
		number: "",
		neighborhood: "",
		state: "",
		city: "",
		zipcode: "",
		additional_references: "",
	});

	const submit = () => {
		axios
			.post(route("checkout.addresses.store"), {
				...data,
			})
			.then((response) => {
				router.reload({
					only: ["addresses"],
					onFinish: () => {
						setCheckoutData("address", response.data.address);
						close();
					},
				});
			})
			.catch((error) => {
				if (error.status === 422) {
					clearErrors();
					setError(error.response.data.errors);
				}
			});
	};

	return (
		<>
			{showAddressesButton && (
				<Button outline onClick={toggleAddressForm} className="mb-4">
					<ChevronLeftIcon className="size-4" />
					Mis direcciones
				</Button>
			)}
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
				<div className="grid gap-3 sm:grid-cols-10">
					<Field className="sm:col-span-7">
						<Label>Calle</Label>
						<Input
							dusk="street"
							required
							type="text"
							value={data.street}
							autoComplete="street-address"
							onChange={(e) => setData("street", e.target.value)}
						/>
						{errors.street && (
							<ErrorMessage>{errors.street}</ErrorMessage>
						)}
					</Field>
					<Field className="sm:col-span-3">
						<Label>Número</Label>
						<Input
							dusk="number"
							required
							type="text"
							value={data.number}
							onChange={(e) => setData("number", e.target.value)}
						/>
						{errors.number && (
							<ErrorMessage>{errors.number}</ErrorMessage>
						)}
					</Field>
				</div>
				<Field>
					<Label>Colonia</Label>
					<Input
						dusk="neighborhood"
						required
						type="text"
						value={data.neighborhood}
						onChange={(e) =>
							setData("neighborhood", e.target.value)
						}
					/>
					{errors.neighborhood && (
						<ErrorMessage>{errors.neighborhood}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label className="flex justify-between">
						Referencias adicionales
						<Description>opcional</Description>
					</Label>
					<Input
						dusk="additionalReferences"
						type="text"
						value={data.additional_references}
						onChange={(e) =>
							setData("additional_references", e.target.value)
						}
						placeholder="Ej: Número interno, Torre 2, etc."
					/>
					{errors.additional_references && (
						<ErrorMessage>
							{errors.additional_references}
						</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Estado</Label>
					<Select
						dusk="state"
						required
						value={data.state}
						onChange={(e) => setData("state", e.target.value)}
					>
						<option value="" disabled>
							Selecciona una opción
						</option>
						{mexicanStates &&
							Object.keys(mexicanStates).map((key) => (
								<option key={key} value={key}>
									{key}
								</option>
							))}
					</Select>
					{errors.state && (
						<ErrorMessage>{errors.state}</ErrorMessage>
					)}
				</Field>
				<Field disabled={!data.state}>
					<Label>Ciudad o municipio</Label>
					<Select
						dusk="city"
						required
						value={data.city}
						onChange={(e) => setData("city", e.target.value)}
					>
						<option value="" disabled>
							{data.state
								? `Selecciona una opción`
								: "Primero selecciona un estado"}
						</option>
						{mexicanStates &&
							data.state &&
							mexicanStates[data.state].map((key) => (
								<option key={key} value={key}>
									{key}
								</option>
							))}
					</Select>

					{errors.city && <ErrorMessage>{errors.city}</ErrorMessage>}
				</Field>
				<Field>
					<Label>Código postal</Label>
					<Input
						dusk="zipcode"
						required
						type="text"
						autoComplete="postal-code"
						value={data.zipcode}
						onChange={(e) => setData("zipcode", e.target.value)}
					/>
					{errors.zipcode && (
						<ErrorMessage>{errors.zipcode}</ErrorMessage>
					)}
				</Field>
			</div>
			<div className="mt-6 flex justify-end sm:col-span-2">
				<Button
					onClick={submit}
					type="button"
					className={`w-full sm:w-auto ${processing ? "opacity-0" : ""}`}
					disabled={processing}
				>
					Guardar dirección
				</Button>
			</div>
		</>
	);
}

import { useMemo } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import { Text } from "@/Components/Catalyst/text";
import { Input } from "@/Components/Catalyst/input";
import {
	Field,
	Label,
	Description,
	ErrorMessage,
} from "@/Components/Catalyst/fieldset";
import { Select } from "@/Components/Catalyst/select";
import { useClose } from "@headlessui/react";
import {
	PlusIcon,
	MapPinIcon,
	ChevronLeftIcon,
} from "@heroicons/react/16/solid";
import CheckoutStep from "@/Components/Checkout/CheckoutStep";
import { MapIcon } from "@heroicons/react/24/solid";
import CheckoutSelectionCard from "@/Components/Checkout/CheckoutSelectionCard";
import { Button } from "@/Components/Catalyst/button";
