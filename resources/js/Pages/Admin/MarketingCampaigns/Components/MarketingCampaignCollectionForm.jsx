import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import MarketingCampaignProductSelector from "./MarketingCampaignProductSelector";

function brandEntries(brands) {
	if (!brands) return [];
	return Object.entries(brands).map(([value, brand]) => [
		value,
		typeof brand === "string" ? brand : brand?.name ?? value,
	]);
}

function itemBrand(item) {
	if (!item) return "";
	if (typeof item.brand === "object") {
		return String(item.brand?.value ?? item.brand?.name ?? "");
	}
	return String(item.brand ?? "");
}

export default function MarketingCampaignCollectionForm({
	data,
	setData,
	errors = {},
	brands = {},
	productSearchUrl,
	initialItems = [],
	processing = false,
	onSubmit,
	submitLabel = "Guardar colección",
}) {
	const [selectedItems, setSelectedItems] = useState(initialItems);
	const [brandWarning, setBrandWarning] = useState(false);

	const syncItems = (items) => {
		setSelectedItems(items);
		setData(
			"laboratory_test_ids",
			items.map((item) => item.id),
		);
	};

	const handleBrandChange = (value) => {
		const kept = selectedItems.filter(
			(item) => itemBrand(item) === String(value),
		);
		const removed = selectedItems.length - kept.length;
		setBrandWarning(removed > 0);
		setSelectedItems(kept);
		setData({
			...data,
			laboratory_brand: value,
			laboratory_test_ids: kept.map((item) => item.id),
		});
	};

	return (
		<form onSubmit={onSubmit} className="space-y-6">
			<Field>
				<Label>Nombre interno</Label>
				<Input
					autoFocus
					value={data.name || ""}
					onChange={(e) => setData("name", e.target.value)}
					placeholder="Nombre interno de la colección"
				/>
				{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
			</Field>

			<Field>
				<Label>Título público</Label>
				<Input
					value={data.public_title || ""}
					onChange={(e) => setData("public_title", e.target.value)}
					placeholder="Título visible"
				/>
				{errors.public_title && (
					<ErrorMessage>{errors.public_title}</ErrorMessage>
				)}
			</Field>

			<Field>
				<Label>Descripción pública</Label>
				<Textarea
					rows={3}
					value={data.public_description || ""}
					onChange={(e) =>
						setData("public_description", e.target.value)
					}
					placeholder="Descripción opcional"
				/>
				{errors.public_description && (
					<ErrorMessage>{errors.public_description}</ErrorMessage>
				)}
			</Field>

			<Field>
				<Label>Marca de laboratorio</Label>
				<Listbox
					value={data.laboratory_brand || ""}
					onChange={handleBrandChange}
					placeholder="Seleccionar marca"
				>
					{brandEntries(brands).map(([value, label]) => (
						<ListboxOption key={value} value={value}>
							<ListboxLabel>{label}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
				{errors.laboratory_brand && (
					<ErrorMessage>{errors.laboratory_brand}</ErrorMessage>
				)}
			</Field>

			{brandWarning && (
				<Text className="text-sm text-amber-700 dark:text-amber-400">
					Se eliminaron los estudios que no pertenecen a la nueva
					marca.
				</Text>
			)}

			<CheckboxField>
				<Checkbox
					checked={Boolean(data.is_active)}
					onChange={(checked) => setData("is_active", checked)}
				/>
				<Label>Colección activa</Label>
			</CheckboxField>
			{errors.is_active && (
				<ErrorMessage>{errors.is_active}</ErrorMessage>
			)}

			<MarketingCampaignProductSelector
				brand={data.laboratory_brand}
				selectedItems={selectedItems}
				onChange={syncItems}
				productSearchUrl={productSearchUrl}
				error={
					errors.laboratory_test_ids ||
					errors["laboratory_test_ids.0"]
				}
			/>

			<div className="flex justify-end">
				<Button type="submit" color="lime" disabled={processing}>
					{processing && <ArrowPathIcon className="animate-spin" />}
					{submitLabel}
				</Button>
			</div>
		</form>
	);
}
