import { Button } from "@/Components/Catalyst/button";
import {
	Field,
	Description,
	Label,
	ErrorMessage,
	FieldGroup,
	Fieldset,
} from "@/Components/Catalyst/fieldset";
import { Subheading } from "@/Components/Catalyst/heading";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";
import { useForm } from "@inertiajs/react";
import { ArrowPathIcon, PlusIcon, XMarkIcon } from "@heroicons/react/16/solid";
import { useState } from "react";

export default function LaboratoryTestForm({
	laboratoryTest,
	brands,
	categories,
}) {
	const editMode = route().current("admin.laboratory-tests.edit");
	const [newFeature, setNewFeature] = useState("");

	const { data, setData, post, put, processing, errors } = useForm({
		brand: laboratoryTest?.brand ?? "",
		gda_id: laboratoryTest?.gda_id ?? "",
		name: laboratoryTest?.name ?? "",
		description: laboratoryTest?.description ?? "",
		feature_list: laboratoryTest?.feature_list ?? [],
		indications: laboratoryTest?.indications ?? "",
		other_name: laboratoryTest?.other_name ?? "",
		elements: laboratoryTest?.elements ?? "",
		common_use: laboratoryTest?.common_use ?? "",
		requires_appointment: laboratoryTest?.requires_appointment ?? false,
		public_price: laboratoryTest?.public_price_cents
			? (laboratoryTest.public_price_cents / 100).toFixed(2)
			: "",
		famedic_price: laboratoryTest?.famedic_price_cents
			? (laboratoryTest.famedic_price_cents / 100).toFixed(2)
			: "",
		laboratory_test_category_id:
			laboratoryTest?.laboratory_test_category_id ?? "",
	});

	const addFeature = () => {
		if (newFeature.trim()) {
			setData("feature_list", [...data.feature_list, newFeature.trim()]);
			setNewFeature("");
		}
	};

	const removeFeature = (index) => {
		const updatedFeatures = data.feature_list.filter((_, i) => i !== index);
		setData("feature_list", updatedFeatures);
	};

	const handleKeyDown = (e) => {
		if (e.key === "Enter") {
			e.preventDefault();
			addFeature();
		}
	};

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (editMode) {
				put(
					route("admin.laboratory-tests.update", {
						laboratory_test: laboratoryTest,
					}),
					{
						preserveScroll: true,
					},
				);
			} else {
				post(route("admin.laboratory-tests.store"));
			}
		}
	};

	return (
		<form onSubmit={submit} className="grid gap-8 sm:grid-cols-2">
			<div className="space-y-1">
				<Subheading>Información de la prueba</Subheading>
				<Text>
					Configura los datos de la prueba de laboratorio incluyendo
					precios, categoría, marca y características específicas.
				</Text>
			</div>

			<Fieldset>
				<FieldGroup>
					<div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
						<Field>
							<Label>Marca</Label>
							<Select
								required
								value={data.brand}
								onChange={(e) =>
									setData("brand", e.target.value)
								}
							>
								<option value="">Seleccionar marca</option>
								{Object.entries(brands).map(
									([value, brand]) => (
										<option key={value} value={value}>
											{brand.name}
										</option>
									),
								)}
							</Select>
							{errors.brand && (
								<ErrorMessage>{errors.brand}</ErrorMessage>
							)}
						</Field>

						<Field>
							<Label>GDA ID</Label>
							<Input
								type="text"
								required
								value={data.gda_id}
								onChange={(e) =>
									setData("gda_id", e.target.value)
								}
								placeholder="ID único del laboratorio"
							/>
							{errors.gda_id && (
								<ErrorMessage>{errors.gda_id}</ErrorMessage>
							)}
						</Field>
					</div>

					<Field>
						<Label>Nombre</Label>
						<Input
							autoFocus
							type="text"
							required
							value={data.name}
							onChange={(e) => setData("name", e.target.value)}
							placeholder="Nombre de la prueba"
						/>
						{errors.name && (
							<ErrorMessage>{errors.name}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Otro nombre</Label>
						<Input
							type="text"
							value={data.other_name}
							onChange={(e) =>
								setData("other_name", e.target.value)
							}
							placeholder="Nombre alternativo de la prueba"
						/>
						{errors.other_name && (
							<ErrorMessage>{errors.other_name}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Categoría</Label>
						<Select
							required
							value={data.laboratory_test_category_id}
							onChange={(e) =>
								setData(
									"laboratory_test_category_id",
									e.target.value,
								)
							}
						>
							<option value="">Seleccionar categoría</option>
							{categories.map((category) => (
								<option key={category.id} value={category.id}>
									{category.name}
								</option>
							))}
						</Select>
						{errors.laboratory_test_category_id && (
							<ErrorMessage>
								{errors.laboratory_test_category_id}
							</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Descripción</Label>
						<Textarea
							value={data.description}
							onChange={(e) =>
								setData("description", e.target.value)
							}
							placeholder="Descripción detallada de la prueba"
							rows={3}
						/>
						{errors.description && (
							<ErrorMessage>{errors.description}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Indicaciones</Label>
						<Textarea
							value={data.indications}
							onChange={(e) =>
								setData("indications", e.target.value)
							}
							placeholder="Indicaciones médicas para la prueba"
							rows={3}
						/>
						{errors.indications && (
							<ErrorMessage>{errors.indications}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Elementos</Label>
						<Textarea
							value={data.elements}
							onChange={(e) =>
								setData("elements", e.target.value)
							}
							placeholder="Elementos incluidos en la prueba"
							rows={2}
						/>
						{errors.elements && (
							<ErrorMessage>{errors.elements}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Uso común</Label>
						<Textarea
							value={data.common_use}
							onChange={(e) =>
								setData("common_use", e.target.value)
							}
							placeholder="Usos comunes de la prueba"
							rows={2}
						/>
						{errors.common_use && (
							<ErrorMessage>{errors.common_use}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Lista de características</Label>
						<Description>
							Agrega características específicas de la prueba.
							Presiona Enter para agregar cada característica.
						</Description>

						{/* Interactive feature list */}
						<div className="space-y-2">
							<div className="flex gap-2">
								<Input
									type="text"
									value={newFeature}
									onChange={(e) =>
										setNewFeature(e.target.value)
									}
									onKeyDown={handleKeyDown}
									placeholder="Agregar nueva característica"
									className="flex-1"
								/>
								<Button
									type="button"
									onClick={addFeature}
									disabled={!newFeature.trim()}
									color="zinc"
								>
									<PlusIcon className="size-4" />
								</Button>
							</div>

							{data.feature_list.length > 0 && (
								<div className="space-y-1">
									{data.feature_list.map((feature, index) => (
										<div
											key={index}
											className="flex items-center gap-2 rounded-md bg-zinc-50 p-2 dark:bg-zinc-800"
										>
											<Text className="flex-1">
												{feature}
											</Text>
											<Button
												type="button"
												onClick={() =>
													removeFeature(index)
												}
												color="red"
												className="size-6 p-1"
											>
												<XMarkIcon className="size-4" />
											</Button>
										</div>
									))}
								</div>
							)}
						</div>
						{errors.feature_list && (
							<ErrorMessage>{errors.feature_list}</ErrorMessage>
						)}
					</Field>

					<div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
						<Field>
							<Label>Precio público</Label>
							<Input
								type="number"
								min="0"
								step="0.01"
								required
								value={data.public_price}
								onChange={(e) =>
									setData("public_price", e.target.value)
								}
								placeholder="0.00"
							/>
							<Description>
								Precio en pesos mexicanos (ej: 23.50)
							</Description>
							{errors.public_price && (
								<ErrorMessage>
									{errors.public_price}
								</ErrorMessage>
							)}
						</Field>

						<Field>
							<Label>Precio Famedic</Label>
							<Input
								type="number"
								min="0"
								step="0.01"
								required
								value={data.famedic_price}
								onChange={(e) =>
									setData("famedic_price", e.target.value)
								}
								placeholder="0.00"
							/>
							<Description>
								Precio en pesos mexicanos (ej: 20.50)
							</Description>
							{errors.famedic_price && (
								<ErrorMessage>
									{errors.famedic_price}
								</ErrorMessage>
							)}
						</Field>
					</div>

					<SwitchField>
						<Label>¿Requiere cita?</Label>
						<Description>
							Indica si esta prueba requiere que el paciente
							programe una cita previa.
						</Description>
						<Switch
							checked={data.requires_appointment}
							onChange={(value) =>
								setData("requires_appointment", value)
							}
						/>
						{errors.requires_appointment && (
							<ErrorMessage>
								{errors.requires_appointment}
							</ErrorMessage>
						)}
					</SwitchField>
				</FieldGroup>
			</Fieldset>

			<div className="flex justify-end sm:col-span-2">
				<Button
					type="submit"
					disabled={processing}
					className="w-full sm:w-auto"
				>
					{editMode ? "Actualizar" : "Crear"} prueba
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</div>
		</form>
	);
}
