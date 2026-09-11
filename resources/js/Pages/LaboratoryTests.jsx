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

			<section className="space-y-4 border-t border-slate-200 pt-6 dark:border-slate-800">
				<div>
					<h2 className="font-poppins text-lg font-semibold text-slate-950 dark:text-white">
						Encuentra tu estudio
					</h2>
				</div>

				<SearchAndFilter
					search={search}
					setSearch={setSearch}
					updateSearch={updateSearch}
					category={categoryParam}
					handleEnterKey={handleEnterKey}
					laboratoryTestCategories={laboratoryTestCategories}
				/>
			</section>

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
	const states = uniqueNormalizedStates(laboratoryBrand.states);
	const stateSeparator = " \u00b7 ";
	const storeCount = Number(laboratoryBrand.active_store_count ?? 0);
	const storeLabel =
		storeCount === 1 ? "sucursal disponible" : "sucursales disponibles";
	const storeDirectoryUrl =
		laboratoryBrand.store_directory_url ||
		route("laboratory-stores.index", {
			brand: laboratoryBrand.value,
		});
	const nearbyStoreDirectoryUrl =
		laboratoryBrand.nearby_store_directory_url || storeDirectoryUrl;

	return (
		<header className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-5 dark:border-slate-800 dark:bg-slate-900/85">
			<div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
				<div className="flex flex-col gap-4 sm:flex-row sm:items-center">
					<LaboratoryBrandCard
						src={`/images/gda/${laboratoryBrand.imageSrc}`}
						className="w-full max-w-52 p-3 sm:w-52"
					/>

					<div className="min-w-0 space-y-2">
						<GradientHeading
							noDivider
							className="text-3xl/[2.5rem] lg:text-4xl/[3rem]"
						>
							{laboratoryBrand.name}
						</GradientHeading>
						<p className="font-poppins text-base font-semibold text-famedic-dark dark:text-famedic-lime">
							{storeCount.toLocaleString()} {storeLabel}
						</p>
						<p className="max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
							{states.length > 0
								? states.join(stateSeparator)
								: "Sin cobertura activa"}
						</p>
						<p className="max-w-xl text-sm text-slate-500 dark:text-slate-400">
							Encuentra la sucursal más cercana o revisa horarios
							y servicios sin salir del flujo de estudios.
						</p>
					</div>
				</div>

				<div className="grid gap-2 sm:grid-cols-2 lg:w-80 lg:grid-cols-1">
					<Link
						href={storeDirectoryUrl}
						aria-label={`Ver sucursales de ${laboratoryBrand.name}`}
						className="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-famedic-lime bg-famedic-lime px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-famedic-lime/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-slate-950"
					>
						<BuildingStorefrontIcon className="size-4" />
						Ver sucursales
					</Link>
					<Link
						href={nearbyStoreDirectoryUrl}
						aria-label={`Buscar sucursales cercanas de ${laboratoryBrand.name}`}
						className="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-famedic-lime/70 hover:text-slate-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-200 dark:hover:border-famedic-lime/70 dark:hover:text-white dark:focus-visible:ring-offset-slate-950"
					>
						<MapPinIcon className="size-4" />
						Sucursales cerca de mí
					</Link>
				</div>
			</div>
		</header>
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
						placeholder="Buscar estudios, perfiles o paquetes"
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
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { InputGroup, Input } from "@/Components/Catalyst/input";
import { MagnifyingGlassIcon } from "@heroicons/react/24/outline";
import { BuildingStorefrontIcon, MapPinIcon } from "@heroicons/react/20/solid";
import { Link, router } from "@inertiajs/react";
import { useState } from "react";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";

const STATE_LABELS = {
	"nuevo leon": "Nuevo Le\u00f3n",
	"nuevo le\u00f3n": "Nuevo Le\u00f3n",
	"ciudad de mexico": "Ciudad de M\u00e9xico",
	"ciudad de m\u00e9xico": "Ciudad de M\u00e9xico",
	"estado de mexico": "Estado de M\u00e9xico",
	"estado de m\u00e9xico": "Estado de M\u00e9xico",
};

function normalizeStateName(stateName = "") {
	const trimmedState = String(stateName).trim();
	const stateKey = trimmedState
		.toLocaleLowerCase("es-MX")
		.normalize("NFD")
		.replace(/[\u0300-\u036f]/g, "");

	return STATE_LABELS[stateKey] ?? trimmedState;
}

function uniqueNormalizedStates(states = []) {
	return [
		...new Map(
			states
				.map((stateName) => normalizeStateName(stateName))
				.filter(Boolean)
				.map((stateName) => [stateName, stateName]),
		).values(),
	];
}
