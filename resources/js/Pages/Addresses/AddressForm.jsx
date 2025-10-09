import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Field, Label, Description } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Button } from "@/Components/Catalyst/button";
import { usePage, useForm, router } from "@inertiajs/react";
import { Select } from "@/Components/Catalyst/select";
import { ErrorMessage } from "@/Components/Catalyst/fieldset";
import { useEffect, useState } from "react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function AddressForm({ isOpen }) {
	const { address, mexicanStates } = usePage().props;

	const [cachedMexicanStates, setCachedMexicanStates] =
		useState(mexicanStates);
	const [cachedEditMode, setCachedEditMode] = useState(
		route().current("addresses.edit"),
	);
	const [cachedAddress, setCachedAddress] = useState(address);

	const resetFormData = (address) => ({
		street: address?.street ?? "",
		number: address?.number ?? "",
		neighborhood: address?.neighborhood ?? "",
		state: address?.state ?? "",
		city: address?.city ?? "",
		zipcode: address?.zipcode ?? "",
		additional_references: address?.additional_references ?? "",
	});

	const { data, setData, post, put, processing, errors } = useForm(
		resetFormData(address),
	);

	useEffect(() => {
		if (isOpen) {
			setCachedMexicanStates(mexicanStates);
			setCachedAddress(address);
			setCachedEditMode(route().current("addresses.edit") ?? false);
			setData(resetFormData(address));
		}
	}, [isOpen]);

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (cachedEditMode) {
				put(route("addresses.update", { address: cachedAddress }), {
					preserveScroll: true,
				});
			} else {
				post(route("addresses.store"), { preserveScroll: true });
			}
		}
	};

	const closeDialog = () => {
		router.get(
			route("addresses.index"),
			{},
			{ preserveState: true, preserveScroll: true },
		);
	};

	return (
		<Dialog open={isOpen} onClose={closeDialog}>
			<form dusk="addressForm" onSubmit={submit}>
				<DialogTitle>
					{cachedEditMode
						? `Edita tu dirección ${data?.street ? `"${data.street} ${data.number}"` : ""}`
						: "Agregar dirección"}
				</DialogTitle>
				<DialogDescription>
					Ingresa la información de tu dirección.
				</DialogDescription>
				<DialogBody className="space-y-6">
					<div className="grid gap-6 md:grid-cols-8">
						<Field className="md:col-span-5">
							<Label>Calle</Label>
							<Input
								dusk="street"
								required
								type="text"
								value={data.street}
								autoComplete="street-address"
								onChange={(e) =>
									setData("street", e.target.value)
								}
							/>
							{errors.street && (
								<ErrorMessage>{errors.street}</ErrorMessage>
							)}
						</Field>
						<Field className="md:col-span-3">
							<Label>Número</Label>
							<Input
								dusk="number"
								required
								type="text"
								value={data.number}
								onChange={(e) =>
									setData("number", e.target.value)
								}
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
							{cachedMexicanStates &&
								Object.keys(cachedMexicanStates).map((key) => (
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
							{cachedMexicanStates &&
								data.state &&
								cachedMexicanStates[data.state].map((key) => (
									<option key={key} value={key}>
										{key}
									</option>
								))}
						</Select>
						{errors.city && (
							<ErrorMessage>{errors.city}</ErrorMessage>
						)}
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
				</DialogBody>
				<DialogActions>
					<Button
						autoFocus
						dusk="cancel"
						plain
						type="button"
						onClick={closeDialog}
					>
						Cancelar
					</Button>
					<Button
						dusk="saveAddress"
						type="submit"
						disabled={processing}
					>
						Guardar
						{processing && (
							<ArrowPathIcon className="animate-spin" />
						)}
					</Button>
				</DialogActions>
			</form>
		</Dialog>
	);
}
