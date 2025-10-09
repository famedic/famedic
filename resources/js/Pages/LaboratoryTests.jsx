export default function LaboratoryTests({
	laboratoryBrand,
	laboratoryTests,
	laboratoryTestCategories,
	laboratoryCarts,
}) {
	const searchparam =
		new URLSearchParams(window.location.search).get("query") || "";
	const categoryParam =
		new URLSearchParams(window.location.search).get("category") || "";

	const [search, setSearch] = useState(searchparam);

	const updateSearch = (newSearch, newCategory) => {
		const params = {
			...(newSearch && { query: newSearch }),
			...(newCategory && { category: newCategory }),
			laboratory_brand: laboratoryBrand.value,
		};

		router.get(route("laboratory-tests", { ...params }));
	};

	const handleEnterKey = (e) => {
		if (e.key === "Enter") {
			updateSearch(search, categoryParam);
		}
	};

	const hasShoppingCartBanner =
		laboratoryCarts?.[laboratoryBrand.value]?.length > 0;

	return (
		<FamedicLayout
			title="Laboratorios"
			hasShoppingCartBanner={hasShoppingCartBanner}
			banner={{
				text: "¡Nuevos paquetes y chequeos disponibles!",
				onClick: () =>
					router.get(
						route("laboratory-tests", {
							laboratory_brand: laboratoryBrand.value,
							category: "Chequeos y Paquetes",
						}),
					),
			}}
		>
			<Header laboratoryBrand={laboratoryBrand} />

			<SearchAndFilter
				search={search}
				setSearch={setSearch}
				updateSearch={updateSearch}
				category={categoryParam}
				handleEnterKey={handleEnterKey}
				laboratoryTestCategories={laboratoryTestCategories}
			/>

			<LaboratoryTestsGrid
				search={searchparam}
				category={categoryParam}
				updateSearch={updateSearch}
				laboratoryTests={laboratoryTests}
				laboratoryCartItems={
					laboratoryCarts?.[laboratoryBrand.value] || []
				}
				laboratoryTestCategories={laboratoryTestCategories}
				laboratoryBrand={laboratoryBrand}
			/>

			{laboratoryCarts?.[laboratoryBrand.value]?.length > 0 && (
				<ShoppingCartBanner
					message={`Tienes ${laboratoryCarts[laboratoryBrand.value]?.length} estudio${laboratoryCarts[laboratoryBrand.value]?.length > 1 ? "s" : ""} en el carrito`}
					href={route("laboratory.shopping-cart", {
						laboratory_brand: laboratoryBrand.value,
					})}
				/>
			)}
		</FamedicLayout>
	);
}

function Header({ laboratoryBrand }) {
	return (
		<div className="flex flex-col gap-6 sm:flex-row">
			<LaboratoryBrandCard
				src={`/images/gda/${laboratoryBrand.imageSrc}`}
				className="w-60 p-4"
			/>
			<div className="flex flex-col gap-2">
				<GradientHeading noDivider>
					{laboratoryBrand.name}
				</GradientHeading>
				<div className="flex flex-wrap gap-2">
					{laboratoryBrand.states.map((state) => (
						<Badge color="sky" key={state}>
							<MapPinIcon className="size-5" />
							{state}
						</Badge>
					))}
				</div>
				<Anchor
					target="_blank"
					href={route("laboratory-stores.index", {
						brand: laboratoryBrand.value,
					})}
				>
					Ver lista de sucursales
					<ArrowTopRightOnSquareIcon className="ml-1 inline-block size-5 align-middle" />
				</Anchor>
			</div>
		</div>
	);
}

function SearchAndFilter({
	laboratoryTestCategories,
	search,
	setSearch,
	updateSearch,
	category,
	handleEnterKey,
}) {
	return (
		<div className="grid max-w-2xl gap-2 md:grid-cols-6">
			<Field className="md:col-span-2">
				<Label>Filtrar por categoría</Label>
				<Listbox
					placeholder="Categorías"
					value={category}
					onChange={(newCategory) =>
						updateSearch(search, newCategory)
					}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					{laboratoryTestCategories.map((category) => (
						<ListboxOption
							key={category.name}
							value={category.name}
						>
							<ListboxLabel>{category.name}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
			</Field>
			<Field className="self-end md:col-span-4">
				<InputGroup>
					<MagnifyingGlassIcon />
					<Input
						dusk="search"
						type="text"
						value={search}
						onChange={(e) => setSearch(e.target.value)}
						onKeyDown={handleEnterKey}
						placeholder="Busca aquí tus estudios"
					/>
				</InputGroup>
			</Field>
		</div>
	);
}

import FamedicLayout from "@/Layouts/FamedicLayout";
import ShoppingCartBanner from "@/Components/ShoppingCartBanner";
import LaboratoryTestsGrid from "@/Pages/Laboratories/LaboratoryTestsGrid";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { InputGroup, Input } from "@/Components/Catalyst/input";
import { MagnifyingGlassIcon } from "@heroicons/react/24/outline";
import {
	ArrowTopRightOnSquareIcon,
	MapPinIcon,
} from "@heroicons/react/16/solid";
import { router } from "@inertiajs/react";
import { useState } from "react";
import { Anchor } from "@/Components/Catalyst/text";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
