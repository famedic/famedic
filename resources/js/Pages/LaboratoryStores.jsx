import LaboratoryStoreEmptyState from "@/Components/LaboratoryStores/LaboratoryStoreEmptyState";
import LaboratoryStoreFilters from "@/Components/LaboratoryStores/LaboratoryStoreFilters";
import LaboratoryStoreCard from "@/Components/LaboratoryStores/LaboratoryStoreCard";
import { hasCoordinates } from "@/Components/LaboratoryStores/laboratoryStoreDirectory";
import FamedicLayout from "@/Layouts/FamedicLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { ListBulletIcon, MapIcon } from "@heroicons/react/24/outline";
import clsx from "clsx";
import { lazy, Suspense, useEffect, useMemo, useState } from "react";

const LaboratoryStoreMap = lazy(
	() => import("@/Components/LaboratoryStores/LaboratoryStoreMap"),
);

export default function LaboratoryStores({
	laboratoryStores = [],
	laboratoryBrands = {},
	states = [],
	municipalities = [],
	capabilities = [],
	services = [],
	filters = {},
	total = laboratoryStores.length,
	filtered_total = laboratoryStores.length,
}) {
	const [view, setView] = useState("list");
	const [selectedStoreId, setSelectedStoreId] = useState(null);
	const activeBrand = filters.brand || "";
	const brandName =
		activeBrand && laboratoryBrands[activeBrand]
			? laboratoryBrands[activeBrand].name
			: "laboratorio";
	const hasResults = laboratoryStores.length > 0;
	const mapStoresCount = useMemo(
		() => laboratoryStores.filter(hasCoordinates).length,
		[laboratoryStores],
	);
	const userLocation = hasFilterLocation(filters)
		? {
				latitude: Number(filters.latitude),
				longitude: Number(filters.longitude),
			}
		: null;

	useEffect(() => {
		if (view !== "list" || !selectedStoreId) {
			return;
		}

		const scrollTimeout = window.setTimeout(() => {
			document
				.getElementById(`laboratory-store-${selectedStoreId}`)
				?.scrollIntoView({ behavior: "smooth", block: "center" });
		}, 50);

		const highlightTimeout = window.setTimeout(() => {
			setSelectedStoreId(null);
		}, 2800);

		return () => {
			window.clearTimeout(scrollTimeout);
			window.clearTimeout(highlightTimeout);
		};
	}, [selectedStoreId, view]);

	function showStoreOnMap(store) {
		setSelectedStoreId(store.id);
		setView("map");
	}

	return (
		<FamedicLayout title="Sucursales de laboratorio">
			<div className="mx-auto flex w-full max-w-7xl flex-col gap-4 px-0 pb-8 sm:gap-5">
				<header className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
					<div className="max-w-3xl space-y-2">
						<GradientHeading
							noDivider
							className="text-3xl/[2.5rem] lg:text-4xl/[3rem]"
						>
							Sucursales{" "}
							{activeBrand ? brandName : "de laboratorio"}
						</GradientHeading>
						<Text className="max-w-2xl text-sm">
							Encuentra la sucursal que tenga los servicios que
							necesitas, revisa su horario de hoy y abre la ruta
							en Google Maps.
						</Text>
						<p className="font-poppins text-sm font-semibold text-famedic-dark dark:text-famedic-lime">
							{filtered_total === total
								? `${total} sucursales disponibles`
								: `${filtered_total} sucursales encontradas de ${total}`}
						</p>
					</div>

					<div
						className="inline-flex w-full rounded-lg border border-famedic-dark/15 bg-white p-1 shadow-sm sm:w-auto dark:border-famedic-lime/20 dark:bg-slate-900"
						role="group"
						aria-label="Cambiar vista del directorio"
					>
						<button
							type="button"
							className={viewButtonClass(view === "list")}
							aria-pressed={view === "list"}
							onClick={() => setView("list")}
						>
							<ListBulletIcon className="size-4" />
							Lista
						</button>
						<button
							type="button"
							className={viewButtonClass(view === "map")}
							aria-pressed={view === "map"}
							onClick={() => setView("map")}
						>
							<MapIcon className="size-4" />
							Mapa
						</button>
					</div>
				</header>

				<LaboratoryStoreFilters
					filters={filters}
					laboratoryBrands={laboratoryBrands}
					states={states}
					municipalities={municipalities}
					capabilities={capabilities}
					services={services}
				/>

				<section aria-live="polite" className="space-y-3">
					<div className="flex items-center justify-between gap-3 pt-1">
						<h2 className="font-poppins text-base font-semibold text-zinc-950 dark:text-white">
							{filtered_total === 1
								? "1 sucursal encontrada"
								: `${filtered_total} sucursales encontradas`}
						</h2>
					</div>

					{hasResults && view === "list" ? (
						<div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
							{laboratoryStores.map((store) => (
								<LaboratoryStoreCard
									key={store.id}
									store={store}
									selected={selectedStoreId === store.id}
									onViewMap={
										hasCoordinates(store)
											? () => showStoreOnMap(store)
											: null
									}
								/>
							))}
						</div>
					) : null}

					{hasResults && view === "map" ? (
						<div className="space-y-3">
							{mapStoresCount < laboratoryStores.length && (
								<p className="text-sm font-medium text-zinc-600 dark:text-slate-300">
									{mapStoresCount} de{" "}
									{laboratoryStores.length} sucursales
									disponibles en mapa.
								</p>
							)}
							<Suspense fallback={<MapLoadingState />}>
								<LaboratoryStoreMap
									stores={laboratoryStores}
									selectedStoreId={selectedStoreId}
									onSelectStore={setSelectedStoreId}
									onViewList={() => setView("list")}
									userLocation={userLocation}
								/>
							</Suspense>
						</div>
					) : null}

					{!hasResults && (
						<LaboratoryStoreEmptyState brand={activeBrand} />
					)}
				</section>
			</div>
		</FamedicLayout>
	);
}

function hasFilterLocation(filters) {
	return (
		filters.latitude !== null &&
		filters.latitude !== undefined &&
		filters.longitude !== null &&
		filters.longitude !== undefined
	);
}

function viewButtonClass(active) {
	return clsx(
		"inline-flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-semibold transition sm:flex-none",
		active
			? "bg-famedic-dark text-white shadow-sm dark:bg-famedic-lime dark:text-famedic-darker"
			: "text-zinc-600 hover:bg-zinc-100 dark:text-slate-300 dark:hover:bg-white/10",
	);
}

function MapLoadingState() {
	return (
		<div className="flex min-h-[420px] items-center justify-center rounded-lg border border-zinc-950/10 bg-white text-sm font-semibold text-zinc-500 shadow-sm sm:min-h-[min(680px,calc(100vh-16rem))] dark:border-white/10 dark:bg-slate-900 dark:text-slate-300">
			Cargando mapa...
		</div>
	);
}
