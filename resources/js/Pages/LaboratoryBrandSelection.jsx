import FamedicLayout from "@/Layouts/FamedicLayout";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { GradientHeading } from "@/Components/Catalyst/heading";
import {
	Listbox,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";
import { Text } from "@/Components/Catalyst/text";
import {
	ArrowRightIcon,
	BuildingStorefrontIcon,
} from "@heroicons/react/20/solid";
import { Link, usePage } from "@inertiajs/react";
import { clsx } from "clsx";
import { useMemo, useState } from "react";

const DEFAULT_BRANDS = [
	{
		brand: "swisslab",
		value: "swisslab",
		name: "Swisslab",
		imageSrc: "GDA-SWISSLAB.png",
		states: ["Nuevo Leon"],
		active_store_count: 0,
	},
	{
		brand: "olab",
		value: "olab",
		name: "Olab",
		imageSrc: "GDA-OLAB.png",
		states: ["Ciudad de Mexico", "Estado de Mexico"],
		active_store_count: 0,
	},
	{
		brand: "azteca",
		value: "azteca",
		name: "Azteca",
		imageSrc: "GDA-AZTECA.png",
		states: ["Ciudad de Mexico", "Estado de Mexico"],
		active_store_count: 0,
	},
	{
		brand: "jenner",
		value: "jenner",
		name: "Jenner",
		imageSrc: "GDA-JENNER.png",
		states: ["Ciudad de Mexico", "Estado de Mexico"],
		active_store_count: 0,
	},
	{
		brand: "liacsa",
		value: "liacsa",
		name: "Liacsa",
		imageSrc: "GDA-LIACSA.png",
		states: ["Chihuahua"],
		active_store_count: 0,
	},
];

const STATE_LABELS = {
	"nuevo leon": "Nuevo León",
	"nuevo león": "Nuevo León",
	"ciudad de mexico": "Ciudad de México",
	"ciudad de méxico": "Ciudad de México",
	"estado de mexico": "Estado de México",
	"estado de méxico": "Estado de México",
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

export default function LaboratoryBrandSelection({ brands = [], states = [] }) {
	const { url } = usePage();
	const searchParams = new URLSearchParams(url.split("?")[1] ?? "");
	const [state, setState] = useState(() =>
		normalizeStateName(searchParams.get("state") || ""),
	);
	const category = searchParams.get("category") || "";

	const brandItems = brands.length > 0 ? brands : DEFAULT_BRANDS;
	const stateOptions = useMemo(
		() => uniqueNormalizedStates(states),
		[states],
	);

	const stateBrandCount = useMemo(() => {
		return brandItems.reduce((acc, brand) => {
			uniqueNormalizedStates(brand.states).forEach((stateName) => {
				acc[stateName] = (acc[stateName] || 0) + 1;
			});

			return acc;
		}, {});
	}, [brandItems]);

	const visibleBrands = useMemo(() => {
		if (!state) {
			return brandItems;
		}

		return brandItems.filter((brand) =>
			uniqueNormalizedStates(brand.states).includes(state),
		);
	}, [brandItems, state]);

	const availabilityCopy = useMemo(() => {
		const count = visibleBrands.length;
		const label = count === 1 ? "marca disponible" : "marcas disponibles";

		return state ? `${count} ${label} en ${state}` : `${count} ${label}`;
	}, [state, visibleBrands.length]);

	const handleStateChange = (nextState) => {
		setState(nextState);

		const nextUrl = new URL(window.location.href);

		if (nextState) {
			nextUrl.searchParams.set("state", nextState);
		} else {
			nextUrl.searchParams.delete("state");
		}

		window.history.replaceState({}, "", nextUrl);
	};

	return (
		<FamedicLayout title="Laboratorios">
			<header className="space-y-5">
				<div className="max-w-3xl">
					<GradientHeading>Laboratorios</GradientHeading>
					<Text className="mt-2 max-w-2xl text-sm sm:text-base">
						Selecciona la marca de laboratorio donde deseas realizar
						tus estudios.
					</Text>
				</div>

				<div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white/85 px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900/70">
					<Field className="flex w-full flex-col gap-2 sm:max-w-md sm:flex-row sm:items-center">
						<Label className="shrink-0">Estado</Label>
						<Listbox
							placeholder="Estado"
							value={state}
							onChange={handleStateChange}
						>
							<ListboxOption value="">
								<ListboxLabel>Todos los estados</ListboxLabel>
							</ListboxOption>
							{stateOptions.map((stateName) => (
								<ListboxOption
									key={stateName}
									value={stateName}
								>
									<ListboxLabel>
										{stateName}
										{stateBrandCount[stateName]
											? ` (${stateBrandCount[stateName]})`
											: ""}
									</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
					</Field>

					<p className="text-sm font-medium text-slate-600 dark:text-slate-300">
						{availabilityCopy}
					</p>
				</div>
			</header>

			{visibleBrands.length > 0 ? (
				<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
					{visibleBrands.map((brand) => (
						<LaboratoryBrand
							key={brand.brand ?? brand.value}
							{...brand}
							category={category}
						/>
					))}
				</div>
			) : (
				<div className="rounded-lg border border-dashed border-slate-300 bg-white/80 p-8 text-center dark:border-slate-700 dark:bg-slate-900/70">
					<BuildingStorefrontIcon className="mx-auto size-10 text-famedic-lime" />
					<h2 className="mt-3 text-base font-semibold text-slate-950 dark:text-white">
						No encontramos laboratorios disponibles en este estado.
					</h2>
					<button
						type="button"
						onClick={() => handleStateChange("")}
						className="mt-5 rounded-lg bg-famedic-lime px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-famedic-lime/90 focus:outline-none focus:ring-2 focus:ring-famedic-lime focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-slate-950"
					>
						Ver todos los estados
					</button>
				</div>
			)}
		</FamedicLayout>
	);
}

function LaboratoryBrand({
	brand,
	value,
	name,
	imageSrc,
	states = [],
	active_store_count = 0,
	category = "",
}) {
	const brandValue = brand ?? value;
	const normalizedStates = uniqueNormalizedStates(states);
	const visibleStates = normalizedStates.slice(0, 2);
	const remainingStates = Math.max(
		normalizedStates.length - visibleStates.length,
		0,
	);
	const storesLabel = active_store_count === 1 ? "sucursal" : "sucursales";
	const stateSeparator = " \u00b7 ";

	return (
		<Link
			href={route("laboratory-tests", {
				laboratory_brand: brandValue,
				...(category ? { category } : {}),
			})}
			className={clsx(
				"group flex min-h-full flex-col rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-famedic-lime/70 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:border-slate-800 dark:bg-slate-900/85 dark:hover:border-famedic-lime/70 dark:focus-visible:ring-offset-slate-950",
			)}
		>
			<div className="flex h-24 items-center justify-center rounded-lg border border-slate-100 bg-white px-3 py-2 shadow-sm dark:border-slate-700 dark:bg-slate-950/60">
				<img
					alt={name}
					src={`/images/gda/${imageSrc}`}
					className="max-h-20 w-full object-contain"
				/>
			</div>

			<div className="mt-4 flex flex-1 flex-col gap-2">
				<h2 className="truncate text-lg font-semibold text-slate-950 dark:text-white">
					{name}
				</h2>
				<p className="text-sm font-medium text-slate-700 dark:text-slate-300">
					{active_store_count} {storesLabel}
				</p>
				<p className="min-h-12 text-sm leading-6 text-slate-600 dark:text-slate-300">
					{visibleStates.length > 0
						? visibleStates.join(stateSeparator)
						: "Sin cobertura activa"}
					{remainingStates > 0
						? `${stateSeparator}+${remainingStates}`
						: ""}
				</p>

				<span className="mt-auto inline-flex w-full items-center justify-center gap-2 rounded-lg bg-famedic-lime px-4 py-2.5 text-sm font-semibold text-slate-950 transition group-hover:bg-famedic-lime/90">
					Ver sucursales
					<ArrowRightIcon className="size-4" />
				</span>
			</div>
		</Link>
	);
}
