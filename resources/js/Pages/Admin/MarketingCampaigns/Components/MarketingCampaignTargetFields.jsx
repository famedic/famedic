import { useEffect, useState } from "react";
import axios from "axios";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import { MagnifyingGlassIcon } from "@heroicons/react/16/solid";

const TARGET_TYPE_OPTIONS = [
	{ value: "brand", label: "Marca" },
	{ value: "category", label: "Categoría" },
	{ value: "product", label: "Producto" },
	{ value: "collection", label: "Colección" },
];

function payloadError(errors, key) {
	return errors?.[`target_payload.${key}`] || errors?.[key];
}

function brandEntries(brands) {
	if (!brands) return [];
	return Object.entries(brands).map(([value, brand]) => [
		value,
		typeof brand === "string" ? brand : brand?.name ?? value,
	]);
}

function brandName(brands, value) {
	if (!value || !brands) return value || "";
	const brand = brands[value];
	if (!brand) return value;
	return typeof brand === "string" ? brand : brand.name ?? value;
}

function categoryLabel(category) {
	return category?.name ?? `Categoría #${category?.id}`;
}

function collectionLabel(collection) {
	const base = collection.name || `Colección #${collection.id}`;
	return collection.is_active ? base : `${base} (inactiva)`;
}

export default function MarketingCampaignTargetFields({
	data,
	setData,
	errors = {},
	brands = {},
	categories = [],
	collections = [],
	productSearchUrl,
}) {
	const payload = data.target_payload || {};
	const [query, setQuery] = useState("");
	const [results, setResults] = useState([]);
	const [searching, setSearching] = useState(false);
	const [selectedProduct, setSelectedProduct] = useState(null);

	useEffect(() => {
		if (data.target_type !== "product") {
			setResults([]);
			return;
		}

		const q = query.trim();
		if (q.length < 2) {
			setResults([]);
			return;
		}

		if (!productSearchUrl) return;

		const controller = new AbortController();
		const timer = setTimeout(async () => {
			setSearching(true);
			try {
				const response = await axios.get(productSearchUrl, {
					params: {
						q,
						brand: payload.brand || undefined,
					},
					signal: controller.signal,
					withCredentials: true,
				});
				const rows = Array.isArray(response.data)
					? response.data
					: (response.data?.data ?? []);
				setResults(rows);
			} catch (error) {
				if (error.name !== "CanceledError" && error.code !== "ERR_CANCELED") {
					setResults([]);
				}
			} finally {
				setSearching(false);
			}
		}, 300);

		return () => {
			clearTimeout(timer);
			controller.abort();
		};
	}, [query, data.target_type, payload.brand, productSearchUrl]);

	const updatePayload = (nextPayload) => {
		setData({
			...data,
			target_payload: nextPayload,
		});
	};

	const handleTargetTypeChange = (value) => {
		setSelectedProduct(null);
		setQuery("");
		setResults([]);
		setData({
			...data,
			target_type: value,
			target_payload: {},
		});
	};

	const selectProduct = (product) => {
		setSelectedProduct(product);
		setQuery("");
		setResults([]);
		updatePayload({
			laboratory_test_id: product.id,
		});
	};

	return (
		<div className="space-y-4">
			<Field>
				<Label>Tipo de destino</Label>
				<Listbox
					value={data.target_type || ""}
					onChange={handleTargetTypeChange}
					placeholder="Seleccionar destino"
				>
					{TARGET_TYPE_OPTIONS.map((option) => (
						<ListboxOption key={option.value} value={option.value}>
							<ListboxLabel>{option.label}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
				{errors.target_type && (
					<ErrorMessage>{errors.target_type}</ErrorMessage>
				)}
			</Field>

			{data.target_type === "brand" && (
				<Field>
					<Label>Marca</Label>
					<Listbox
						value={payload.brand || ""}
						onChange={(value) => updatePayload({ brand: value })}
						placeholder="Seleccionar marca"
					>
						{brandEntries(brands).map(([value, label]) => (
							<ListboxOption key={value} value={value}>
								<ListboxLabel>{label}</ListboxLabel>
							</ListboxOption>
						))}
					</Listbox>
					{(payloadError(errors, "brand") || errors.target_payload) && (
						<ErrorMessage>
							{payloadError(errors, "brand") || errors.target_payload}
						</ErrorMessage>
					)}
				</Field>
			)}

			{data.target_type === "category" && (
				<>
					<Field>
						<Label>Marca</Label>
						<Listbox
							value={payload.brand || ""}
							onChange={(value) =>
								updatePayload({
									brand: value,
									laboratory_test_category_id: "",
								})
							}
							placeholder="Seleccionar marca"
						>
							{brandEntries(brands).map(([value, label]) => (
								<ListboxOption key={value} value={value}>
									<ListboxLabel>{label}</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
						{payloadError(errors, "brand") && (
							<ErrorMessage>
								{payloadError(errors, "brand")}
							</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Categoría</Label>
						<Listbox
							value={
								payload.laboratory_test_category_id
									? String(payload.laboratory_test_category_id)
									: ""
							}
							onChange={(value) =>
								updatePayload({
									...payload,
									laboratory_test_category_id: Number(value),
								})
							}
							placeholder="Seleccionar categoría"
						>
							{categories.map((category) => (
								<ListboxOption
									key={category.id}
									value={String(category.id)}
								>
									<ListboxLabel>
										{categoryLabel(category)}
									</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
						{payloadError(errors, "laboratory_test_category_id") && (
							<ErrorMessage>
								{payloadError(
									errors,
									"laboratory_test_category_id",
								)}
							</ErrorMessage>
						)}
					</Field>
				</>
			)}

			{data.target_type === "product" && (
				<div className="space-y-3">
					<Field>
						<Label>Buscar producto</Label>
						<InputGroup>
							<MagnifyingGlassIcon />
							<Input
								value={query}
								onChange={(e) => setQuery(e.target.value)}
								placeholder="Escribe al menos 2 caracteres…"
							/>
						</InputGroup>
						{searching && (
							<Text className="mt-1 text-sm text-zinc-500">
								Buscando…
							</Text>
						)}
					</Field>

					{results.length > 0 && (
						<ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
							{results.map((product) => (
								<li key={product.id}>
									<button
										type="button"
										className="flex w-full flex-col gap-0.5 px-3 py-2 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800"
										onClick={() => selectProduct(product)}
									>
										<span className="font-medium">
											{product.name}
										</span>
										<span className="text-sm text-zinc-500">
											{[
												product.other_name,
												brandName(
													brands,
													product.brand,
												),
												product.category?.name ||
													product.category_name,
											]
												.filter(Boolean)
												.join(" · ")}
										</span>
									</button>
								</li>
							))}
						</ul>
					)}

					{(selectedProduct || payload.laboratory_test_id) && (
						<div className="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
							<Text className="font-medium">
								Producto seleccionado
								{selectedProduct
									? `: ${selectedProduct.name}`
									: ` #${payload.laboratory_test_id}`}
							</Text>
							{(selectedProduct?.brand || payload.brand) && (
								<Text className="mt-1 text-sm text-zinc-500">
									Marca:{" "}
									{brandName(
										brands,
										selectedProduct?.brand || payload.brand,
									)}{" "}
									(solo lectura)
								</Text>
							)}
						</div>
					)}

					{(payloadError(errors, "laboratory_test_id") ||
						errors.target_payload) && (
						<ErrorMessage>
							{payloadError(errors, "laboratory_test_id") ||
								errors.target_payload}
						</ErrorMessage>
					)}
				</div>
			)}

			{data.target_type === "collection" && (
				<Field>
					<Label>Colección</Label>
					<Listbox
						value={
							payload.marketing_campaign_collection_id
								? String(
										payload.marketing_campaign_collection_id,
									)
								: ""
						}
						onChange={(value) =>
							updatePayload({
								marketing_campaign_collection_id: Number(value),
							})
						}
						placeholder="Seleccionar colección"
					>
						{collections.map((collection) => (
							<ListboxOption
								key={collection.id}
								value={String(collection.id)}
							>
								<ListboxLabel>
									{collectionLabel(collection)}
								</ListboxLabel>
							</ListboxOption>
						))}
					</Listbox>
					{(payloadError(
						errors,
						"marketing_campaign_collection_id",
					) ||
						errors.target_payload) && (
						<ErrorMessage>
							{payloadError(
								errors,
								"marketing_campaign_collection_id",
							) || errors.target_payload}
						</ErrorMessage>
					)}
				</Field>
			)}
		</div>
	);
}
