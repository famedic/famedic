import { useEffect, useState } from "react";
import axios from "axios";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	MagnifyingGlassIcon,
	ChevronUpIcon,
	ChevronDownIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";
import { formatCents } from "./collectionPricing";

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

const EMPTY_ID_LIST = [];

function idsKey(items) {
	if (!items?.length) return "";
	return items
		.map((item) => Number(item?.id ?? item))
		.filter((id) => Number.isFinite(id))
		.sort((a, b) => a - b)
		.join(",");
}

export default function MarketingCampaignProductSelector({
	brand,
	selectedItems,
	onChange,
	productSearchUrl,
	error,
	maxItems = 20,
	excludeIds,
	emptyMessage = "Ningún estudio seleccionado.",
	addLabel = "Agregar estudios",
	variant = "default",
	showSelectedCount = true,
}) {
	const resolvedSelectedItems = selectedItems ?? EMPTY_ID_LIST;
	const resolvedExcludeIds = excludeIds ?? EMPTY_ID_LIST;
	const selectedItemsKey = idsKey(resolvedSelectedItems);
	const excludeIdsKey = idsKey(resolvedExcludeIds);
	const [query, setQuery] = useState("");
	const [results, setResults] = useState([]);
	const [searching, setSearching] = useState(false);
	const isCollection = variant === "collection";

	useEffect(() => {
		const q = query.trim();
		if (q.length < 2 || !productSearchUrl) {
			setResults((current) => (current.length === 0 ? current : []));
			return;
		}

		if (isCollection && !brand) {
			setResults((current) => (current.length === 0 ? current : []));
			return;
		}

		const controller = new AbortController();
		const timer = setTimeout(async () => {
			setSearching(true);
			try {
				const response = await axios.get(productSearchUrl, {
					params: { q, brand: brand || undefined },
					signal: controller.signal,
					withCredentials: true,
				});
				const rows = Array.isArray(response.data)
					? response.data
					: (response.data?.data ?? []);
				setResults(rows);
			} catch (err) {
				if (err.name !== "CanceledError" && err.code !== "ERR_CANCELED") {
					setResults((current) => (current.length === 0 ? current : []));
				}
			} finally {
				setSearching(false);
			}
		}, 300);

		return () => {
			clearTimeout(timer);
			controller.abort();
		};
	}, [query, brand, productSearchUrl, selectedItemsKey, excludeIdsKey, isCollection]);

	const selectedIds = new Set([
		...resolvedSelectedItems.map((item) => Number(item.id)),
		...resolvedExcludeIds.map((id) => Number(id)),
	]);

	const addItem = (product) => {
		if (
			resolvedSelectedItems.some(
				(item) => Number(item.id) === Number(product.id),
			) ||
			resolvedSelectedItems.length >= maxItems
		) {
			return;
		}
		onChange([...resolvedSelectedItems, product]);
		setQuery("");
		setResults([]);
	};

	const removeItem = (id) => {
		onChange(
			resolvedSelectedItems.filter(
				(item) => Number(item.id) !== Number(id),
			),
		);
	};

	const moveItem = (index, direction) => {
		const next = [...resolvedSelectedItems];
		const target = index + direction;
		if (target < 0 || target >= next.length) return;
		[next[index], next[target]] = [next[target], next[index]];
		onChange(next);
	};

	const renderMeta = (product, { selected = false } = {}) => {
		const parts = [
			product.other_name,
			!isCollection ? brandLabel(product) : null,
			categoryLabel(product),
			formatCents(product.famedic_price_cents),
		].filter(Boolean);

		return (
			<span className="text-sm text-zinc-500">
				{parts.join(" · ")}
				{product.requires_appointment && (
					<Badge color="sky" className="ml-2">
						Requiere cita
					</Badge>
				)}
				{selected && (
					<Badge color="zinc" className="ml-2">
						Ya agregado
					</Badge>
				)}
			</span>
		);
	};

	return (
		<div className="space-y-4">
			{showSelectedCount && resolvedSelectedItems.length > 0 && (
				<Text className="text-sm font-medium">
					{resolvedSelectedItems.length} estudio
					{resolvedSelectedItems.length === 1 ? "" : "s"} seleccionado
					{resolvedSelectedItems.length === 1 ? "" : "s"}
				</Text>
			)}

			<Field>
				<Label>{addLabel}</Label>
				<InputGroup>
					<MagnifyingGlassIcon />
					<Input
						value={query}
						onChange={(e) => setQuery(e.target.value)}
						placeholder={
							brand
								? "Escribe al menos 2 caracteres…"
								: "Selecciona una marca de laboratorio primero"
						}
						disabled={!brand}
					/>
				</InputGroup>
				{searching && (
					<Text className="mt-1 text-sm text-zinc-500">Buscando…</Text>
				)}
				{!searching && query.trim().length >= 2 && results.length === 0 && (
					<Text className="mt-1 text-sm text-zinc-500">
						No encontramos estudios con esa búsqueda.
					</Text>
				)}
				{error && <ErrorMessage>{error}</ErrorMessage>}
			</Field>

			{results.length > 0 && (
				<ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
					{results.map((product) => {
						const alreadySelected = selectedIds.has(Number(product.id));

						return (
							<li key={product.id}>
								<button
									type="button"
									disabled={alreadySelected}
									className={`flex w-full flex-col gap-1 px-3 py-2 text-left ${
										alreadySelected
											? "cursor-not-allowed opacity-60"
											: "hover:bg-zinc-50 dark:hover:bg-zinc-800"
									}`}
									onClick={() => addItem(product)}
								>
									<span className="font-medium">
										{product.name}
									</span>
									{renderMeta(product, {
										selected: alreadySelected,
									})}
								</button>
							</li>
						);
					})}
				</ul>
			)}

			{resolvedSelectedItems.length === 0 ? (
				<Text className="text-sm text-zinc-500">{emptyMessage}</Text>
			) : (
				<ul className="space-y-2">
					{resolvedSelectedItems.map((item, index) => (
						<li
							key={item.id}
							className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
						>
							<div className="min-w-0 flex-1">
								<div className="flex flex-wrap items-center gap-2">
									<Badge color="zinc">#{index + 1}</Badge>
									<div className="font-medium">{item.name}</div>
								</div>
								<div className="mt-1 text-sm text-zinc-500">
									{[
										item.other_name,
										categoryLabel(item),
										formatCents(item.famedic_price_cents),
									]
										.filter(Boolean)
										.join(" · ")}
									{item.requires_appointment && (
										<Badge color="sky" className="ml-2">
											Requiere cita
										</Badge>
									)}
								</div>
							</div>
							<div className="flex items-center gap-1">
								<Button
									type="button"
									plain
									disabled={index === 0}
									onClick={() => moveItem(index, -1)}
									aria-label={`Subir ${item.name}`}
								>
									<ChevronUpIcon className="size-4" />
								</Button>
								<Button
									type="button"
									plain
									disabled={
										index === resolvedSelectedItems.length - 1
									}
									onClick={() => moveItem(index, 1)}
									aria-label={`Bajar ${item.name}`}
								>
									<ChevronDownIcon className="size-4" />
								</Button>
								<Button
									type="button"
									plain
									onClick={() => removeItem(item.id)}
									aria-label={`Quitar ${item.name}`}
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
