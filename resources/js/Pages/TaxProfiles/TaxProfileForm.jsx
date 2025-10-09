export default function TaxProfileForm({ isOpen }) {
	const { taxProfile, taxRegimes, cfdiUses } = usePage().props;

	const [cachedTaxRegimes, setCachedTaxRegimes] = useState(taxRegimes);
	const [cachedCfdiUses, setCachedCfdiUses] = useState(cfdiUses);

	const [cachedEditMode, setCachedEditMode] = useState(
		route().current("tax-profiles.edit"),
	);
	const [cachedTaxProfile, setCachedTaxProfile] = useState(taxProfile);

	const [
		showChangeFiscalCertificateButton,
		setShowChangeFiscalCertificateButton,
	] = useState(cachedEditMode);

	const resetFormData = (taxProfile) => ({
		name: taxProfile?.name ?? "",
		rfc: taxProfile?.rfc ?? "",
		zipcode: taxProfile?.zipcode ?? "",
		tax_regime: taxProfile?.tax_regime ?? null,
		cfdi_use: taxProfile?.cfdi_use ?? null,
		fiscal_certificate: null,
	});

	const { data, setData, post, transform, processing, errors } = useForm(
		resetFormData(taxProfile),
	);

	useEffect(() => {
		if (isOpen) {
			const isEditMode = route().current("tax-profiles.edit") ?? false;
			setCachedTaxRegimes(taxRegimes);
			setCachedCfdiUses(cfdiUses);
			setCachedTaxProfile(taxProfile);
			setCachedEditMode(isEditMode);
			setData(resetFormData(taxProfile));
			setShowChangeFiscalCertificateButton(isEditMode);
		}
	}, [isOpen]);

	transform((data) => ({
		...data,
		...(cachedEditMode && { _method: "put" }),
	}));

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (cachedEditMode) {
				post(
					route("tax-profiles.update", {
						tax_profile: cachedTaxProfile,
					}),
					{
						preserveScroll: true,
					},
				);
			} else {
				post(route("tax-profiles.store"), { preserveScroll: true });
			}
		}
	};

	const closeDialog = () => {
		router.get(
			route("tax-profiles.index"),
			{},
			{ preserveState: true, preserveScroll: true },
		);
	};

	return (
		<Dialog open={isOpen} onClose={closeDialog}>
			<form dusk="taxProfileForm" onSubmit={submit}>
				<DialogTitle>
					{cachedEditMode
						? `Edita tu perfil fiscal ${data?.rfc ? `"${data.rfc}"` : ""}`
						: "Agregar perfil fiscal"}
				</DialogTitle>
				<DialogDescription>
					Ingresa la información de tu perfil fiscal.
				</DialogDescription>
				<DialogBody className="space-y-6">
					<Field>
						<Label>Nombre o Razón Social</Label>
						<Input
							dusk="name"
							required
							invalid={!!errors.name}
							value={data.name}
							onChange={(e) => setData("name", e.target.value)}
							type="text"
							autoComplete="given-name"
						/>
						{errors.name && (
							<ErrorMessage>{errors.name}</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>RFC</Label>
						<Input
							dusk="rfc"
							required
							invalid={!!errors.rfc}
							value={data.rfc}
							onChange={(e) => setData("rfc", e.target.value)}
							type="text"
						/>
						{errors.rfc && (
							<ErrorMessage>{errors.rfc}</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Código postal</Label>
						<Input
							dusk="zipcode"
							required
							invalid={!!errors.zipcode}
							type="text"
							autoComplete="postal-code"
							value={data.zipcode}
							onChange={(e) => setData("zipcode", e.target.value)}
						/>
						{errors.zipcode && (
							<ErrorMessage>{errors.zipcode}</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Constancia de Situación Fiscal</Label>
						<Description>PDF, PNG o JPG</Description>

						{showChangeFiscalCertificateButton ? (
							<>
								<div
									data-slot="control"
									className="flex flex-wrap gap-2"
								>
									<a
										href={route(
											"tax-profiles.fiscal-certificate",
											{ tax_profile: cachedTaxProfile },
										)}
										target="_blank"
									>
										<Button outline type="button">
											<DocumentTextIcon />
											Ver constancia
										</Button>
									</a>
									<Button
										outline
										type="button"
										onClick={() =>
											setShowChangeFiscalCertificateButton(
												false,
											)
										}
									>
										<ArrowsUpDownIcon />
										Actualizar constancia
									</Button>
								</div>
							</>
						) : (
							<>
								<Input
									invalid={!!errors.fiscal_certificate}
									dusk="fiscal_certificate"
									type="file"
									accept="application/pdf,image/png,image/jpeg"
									onChange={(e) =>
										setData(
											"fiscal_certificate",
											e.target.files[0],
										)
									}
								/>
								{errors.fiscal_certificate && (
									<ErrorMessage>
										{errors.fiscal_certificate}
									</ErrorMessage>
								)}
							</>
						)}
					</Field>
					<Field>
						<Label>Régimen fiscal</Label>
						<Listbox
							invalid={!!errors.tax_regime}
							placeholder="Selecciona un régimen fiscal"
							value={data.tax_regime}
							onChange={(value) => {
								setData("tax_regime", value);
								setData("cfdi_use", null);
							}}
						>
							{cachedTaxRegimes &&
								Object.keys(cachedTaxRegimes).map((key) => (
									<ListboxOption key={key} value={key}>
										<ListboxLabel>{`${key} - ${cachedTaxRegimes[key].name}`}</ListboxLabel>
									</ListboxOption>
								))}
						</Listbox>
						{errors.tax_regime && (
							<ErrorMessage>{errors.tax_regime}</ErrorMessage>
						)}
					</Field>
					<Field disabled={!data.tax_regime}>
						<Label>Uso CFDI</Label>
						<Listbox
							invalid={!!errors.cfdi_use}
							placeholder={
								data.tax_regime
									? `Selecciona un uso de CFDI`
									: "Primero selecciona un régimen fiscal"
							}
							value={data.cfdi_use}
							onChange={(value) => setData("cfdi_use", value)}
						>
							{cachedCfdiUses &&
								data.tax_regime &&
								Object.entries(cachedCfdiUses)
									.filter(([key]) =>
										cachedTaxRegimes[
											data.tax_regime
										].uses.includes(key),
									)
									.map(([key, value]) => (
										<ListboxOption key={key} value={key}>
											<ListboxLabel>{`${key} - ${value}`}</ListboxLabel>
										</ListboxOption>
									))}
						</Listbox>
						{errors.cfdi_use && (
							<ErrorMessage>{errors.cfdi_use}</ErrorMessage>
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
						dusk="saveTaxProfile"
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

import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Description, Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Button } from "@/Components/Catalyst/button";
import { usePage, useForm, router } from "@inertiajs/react";
import { ErrorMessage } from "@/Components/Catalyst/fieldset";
import { useEffect, useState } from "react";
import {
	ArrowPathIcon,
	ArrowsUpDownIcon,
	DocumentTextIcon,
} from "@heroicons/react/16/solid";
import {
	Listbox,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";
