export default function OnlinePharmacySearch({
	vitauProducts,
	vitauCategories,
	onlinePharmacyCart,
	previousPage,
	nextPage,
}) {
	const searchparam =
		new URLSearchParams(window.location.search).get("search") || "";
	const categoryParam =
		new URLSearchParams(window.location.search).get("category") || "";

	const [search, setSearch] = useState(searchparam);

	const [category, setCategory] = useState(categoryParam);

	const updateSearch = (newSearch, newCategory) => {
		const params = {
			...(newSearch && { search: newSearch }),
			...(newCategory && { category: newCategory }),
		};

		router.get(route("online-pharmacy-search", { ...params }));
	};

	const selectedCategory = useMemo(() => {
		return (
			vitauCategories.find((category) => category.id == categoryParam) ||
			null
		);
	}, [vitauCategories, categoryParam]);

	const handleEnterKey = (e) => {
		if (e.key === "Enter") {
			updateSearch(search, category);
		}
	};

	const hasShoppingCartBanner = onlinePharmacyCart?.length > 0;

	return (
		<FamedicLayout title="Farmacia en línea" hasShoppingCartBanner={hasShoppingCartBanner}>
			<div className="space-y-6">
				<GradientHeading>Búsqueda de farmacia</GradientHeading>

				<SearchAndFilter
					search={search}
					setSearch={setSearch}
					updateSearch={updateSearch}
					category={category}
					handleEnterKey={handleEnterKey}
					categories={vitauCategories}
				/>
			</div>

			<ProductsGrid
				products={vitauProducts}
				onlinePharmacyCartItems={onlinePharmacyCart || []}
				search={searchparam}
				category={selectedCategory ? selectedCategory.name : category}
				updateSearch={updateSearch}
			/>

			<PagePagination
				previousPage={previousPage}
				nextPage={nextPage}
				search={searchparam}
				category={categoryParam}
			/>

			{onlinePharmacyCart?.length > 0 && (
				<ShoppingCartBanner
					message={`Tienes ${onlinePharmacyCart?.length} producto${onlinePharmacyCart?.length > 1 ? "s" : ""} en el carrito`}
					href={route("online-pharmacy.shopping-cart")}
				/>
			)}
		</FamedicLayout>
	);
}

function SearchAndFilter({
	categories,
	search,
	setSearch,
	updateSearch,
	category,
	handleEnterKey,
}) {
	return (
		<div className="grid max-w-2xl gap-2 md:grid-cols-4">
			<Field>
				<Listbox
					placeholder="Categorías"
					defaultValue={category}
					value={String(category)}
					onChange={(newCategory) =>
						updateSearch(search, newCategory)
					}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					{categories.map((category) => (
						<ListboxOption
							key={category.id}
							value={String(category.id)}
						>
							<ListboxLabel>{category.name}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
			</Field>
			<Field className="md:col-span-3">
				<InputGroup>
					<MagnifyingGlassIcon />
					<Input
						dusk="search"
						type="text"
						value={search}
						onChange={(e) => setSearch(e.target.value)}
						onKeyDown={handleEnterKey}
						placeholder="Buscar..."
					/>
				</InputGroup>
			</Field>
		</div>
	);
}

function PagePagination({ previousPage, nextPage, search, category }) {
	return (
		<Pagination>
			{previousPage ? (
				<PaginationPrevious
					href={route("online-pharmacy-search", {
						...(search && { search }),
						...(category && { category }),
						...(previousPage && { page: previousPage }),
					})}
				/>
			) : (
				<PaginationPrevious disabled />
			)}

			{nextPage ? (
				<PaginationNext
					href={route("online-pharmacy-search", {
						...(search && { search }),
						...(category && { category }),
						...(nextPage && { page: nextPage }),
					})}
				/>
			) : (
				<PaginationNext disabled />
			)}
		</Pagination>
	);
}

import FamedicLayout from "@/Layouts/FamedicLayout";
import ShoppingCartBanner from "@/Components/ShoppingCartBanner";
import ProductsGrid from "@/Pages/OnlinePharmacy/ProductsGrid";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Field } from "@/Components/Catalyst/fieldset";
import { InputGroup, Input } from "@/Components/Catalyst/input";
import {
	Pagination,
	PaginationNext,
	PaginationPrevious,
} from "@/Components/Catalyst/pagination";
import { MagnifyingGlassIcon } from "@heroicons/react/24/outline";
import { router } from "@inertiajs/react";
import { useState, useMemo } from "react";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
