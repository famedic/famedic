import { useEffect, useState } from "react";
import axios from "axios";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	MagnifyingGlassIcon,
	ChevronUpIcon,
	ChevronDownIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";

function formatPrice(cents) {
	if (cents == null || cents === "") return "—";
	const amount = Number(cents) / 100;
	return `$${amount.toLocaleString("es-MX", {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})} MXN`;
}

function brandLabel(item) {
	if (!item) return "—";
	if (item.brand_label) return item.brand_label;
	if (typeof item.brand === "object") {
		return item.brand?.name ?? item.brand?.value ?? "—";
	}
	return item.brand || "—";
}

function categoryLabel(item) {
	return item?.category?.name || item?.category_name || "—";
}

export default function MarketingCampaignProductSelector({
	brand,
	selectedItems = [],
	onChange,
	productSearchUrl,
	error,
}) {
	const [query, setQuery] = useState("");
	const [results, setResults] = useState([]);
	const [searching, setSearching] = useState(false);

	useEffect(() => {
		const q = query.trim();
		if (!brand || q.length < 2 || !productSearchUrl) {
			setResults([]);
			return;
		}

		const controller = new AbortController();
		const timer = setTimeout(async () => {
			setSearching(true);
			try {
				const response = await axios.get(productSearchUrl, {
					params: { q, brand },
					signal: controller.signal,
					withCredentials: true,
				});
				const rows = Array.isArray(response.data)
					? response.data
					: (response.data?.data ?? []);
				const selectedIds = new Set(
					selectedItems.map((item) => Number(item.id)),
				);
				setResults(
					rows.filter((row) => !selectedIds.has(Number(row.id))),
				);
			} catch (err) {
				if (err.name !== "CanceledError" && err.code !== "ERR_CANCELED") {
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
	}, [query, brand, productSearchUrl, selectedItems]);

	const addItem = (product) => {
		if (selectedItems.some((item) => Number(item.id) === Number(product.id))) {
			return;
		}
		onChange([...selectedItems, product]);
		setQuery("");
		setResults([]);
	};

	const removeItem = (id) => {
		onChange(selectedItems.filter((item) => Number(item.id) !== Number(id)));
	};

	const moveItem = (index, direction) => {
		const next = [...selectedItems];
		const target = index + direction;
		if (target < 0 || target >= next.length) return;
		[next[index], next[target]] = [next[target], next[index]];
		onChange(next);
	};

	return (
		<div className="space-y-4">
			<Field>
				<Label>Agregar estudios</Label>
				<InputGroup>
					<MagnifyingGlassIcon />
					<Input
						value={query}
						onChange={(e) => setQuery(e.target.value)}
						placeholder={
							brand
								? "Buscar por nombre…"
								: "Selecciona una marca primero"
						}
						disabled={!brand}
					/>
				</InputGroup>
				{searching && (
					<Text className="mt-1 text-sm text-zinc-500">Buscando…</Text>
				)}
				{error && <ErrorMessage>{error}</ErrorMessage>}
			</Field>

			{results.length > 0 && (
				<ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
					{results.map((product) => (
						<li key={product.id}>
							<button
								type="button"
								className="flex w-full flex-col gap-0.5 px-3 py-2 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800"
								onClick={() => addItem(product)}
							>
								<span className="font-medium">{product.name}</span>
								<span className="text-sm text-zinc-500">
									{[
										product.other_name,
										brandLabel(product),
										categoryLabel(product),
									]
										.filter(Boolean)
										.join(" · ")}
								</span>
							</button>
						</li>
					))}
				</ul>
			)}

			{selectedItems.length === 0 ? (
				<Text className="text-sm text-zinc-500">
					Ningún estudio seleccionado. Puedes guardar la colección vacía.
				</Text>
			) : (
				<ul className="space-y-2">
					{selectedItems.map((item, index) => (
						<li
							key={item.id}
							className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
						>
							<div className="min-w-0 flex-1">
								<div className="font-medium">{item.name}</div>
								<div className="text-sm text-zinc-500">
									{[
										item.other_name,
										brandLabel(item),
										categoryLabel(item),
										formatPrice(item.famedic_price_cents),
									]
										.filter(Boolean)
										.join(" · ")}
								</div>
							</div>
							<div className="flex items-center gap-1">
								<Button
									type="button"
									plain
									disabled={index === 0}
									onClick={() => moveItem(index, -1)}
									title="Subir"
								>
									<ChevronUpIcon className="size-4" />
								</Button>
								<Button
									type="button"
									plain
									disabled={index === selectedItems.length - 1}
									onClick={() => moveItem(index, 1)}
									title="Bajar"
								>
									<ChevronDownIcon className="size-4" />
								</Button>
								<Button
									type="button"
									plain
									onClick={() => removeItem(item.id)}
									title="Quitar"
								>
									<XMarkIcon className="size-4" />
								</Button>
							</div>
						</li>
					))}
				</ul>
			)}
		</div>
	);
}
