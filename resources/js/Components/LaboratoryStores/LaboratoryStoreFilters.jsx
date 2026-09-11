import { useEffect, useMemo, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { BadgeButton } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import {
	Listbox,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";
import {
	AdjustmentsHorizontalIcon,
	MagnifyingGlassIcon,
	MapPinIcon,
	XMarkIcon,
} from "@heroicons/react/24/outline";

const FEATURED_CAPABILITIES = [
	"rayos_x",
	"ultrasonido_convencional",
	"mastografia",
	"tomografia",
	"resonancia_magnetica",
];

const CAPABILITY_LABELS = {
	rayos_x: "Rayos X",
	ultrasonido_convencional: "Ultrasonido",
	mastografia: "Mastografia",
	tomografia: "Tomografia",
	resonancia_magnetica: "Resonancia",
};

export default function LaboratoryStoreFilters({
	filters = {},
	laboratoryBrands = {},
	states = [],
	municipalities = [],
	capabilities = [],
	services = [],
}) {
	const normalizedFilters = {
		brand: filters.brand || "",
		search: filters.search || "",
		state: filters.state || "",
		municipality: filters.municipality || "",
		postal_code: filters.postal_code || "",
		capability: filters.capability || "",
		service: filters.service || "",
		latitude: filters.latitude ?? "",
		longitude: filters.longitude ?? "",
		radius: filters.radius || "",
		sort: filters.sort || "name",
	};
	const [search, setSearch] = useState(normalizedFilters.search);
	const [locationStatus, setLocationStatus] = useState("idle");
	const [locationError, setLocationError] = useState("");
	const latestFilters = useRef(normalizedFilters);
	const hasLocation =
		normalizedFilters.latitude !== "" && normalizedFilters.longitude !== "";

	useEffect(() => {
		latestFilters.current = normalizedFilters;
	});

	useEffect(() => {
		setSearch(normalizedFilters.search);
	}, [normalizedFilters.search]);

	useEffect(() => {
		const timeout = window.setTimeout(() => {
			if (search !== normalizedFilters.search) {
				visit({ search: search.trim() });
			}
		}, 400);

		return () => window.clearTimeout(timeout);
	}, [search]);

	const capabilityOptions = useMemo(
		() =>
			FEATURED_CAPABILITIES.map((slug) => {
				const option = capabilities.find(
					(capability) => capability.slug === slug,
				);

				return option
					? {
							...option,
							name: CAPABILITY_LABELS[slug] || option.name,
						}
					: null;
			}).filter(Boolean),
		[capabilities],
	);

	const hasActiveFilters = Boolean(
		normalizedFilters.search ||
			normalizedFilters.state ||
			normalizedFilters.municipality ||
			normalizedFilters.postal_code ||
			normalizedFilters.capability ||
			normalizedFilters.service ||
			hasLocation ||
			normalizedFilters.sort !== "name",
	);

	function visit(nextFilters) {
		const next = {
			...latestFilters.current,
			...nextFilters,
		};

		if (Object.hasOwn(nextFilters, "state")) {
			next.municipality = "";
		}

		const params = Object.fromEntries(
			Object.entries(next).filter(
				([, value]) => value !== "" && value !== null,
			),
		);

		router.get(route("laboratory-stores.index"), params, {
			preserveScroll: true,
			preserveState: true,
			replace: true,
		});
	}

	function clearFilters() {
		visit({
			search: "",
			state: "",
			municipality: "",
			postal_code: "",
			capability: "",
			service: "",
			latitude: "",
			longitude: "",
			radius: "",
			sort: "name",
		});
	}

	function requestNearbyStores() {
		setLocationError("");

		if (!("geolocation" in navigator)) {
			setLocationError(
				"Tu navegador no permite usar ubicacion en esta pantalla.",
			);

			return;
		}

		setLocationStatus("loading");

		navigator.geolocation.getCurrentPosition(
			(position) => {
				setLocationStatus("idle");
				visit({
					latitude: position.coords.latitude,
					longitude: position.coords.longitude,
					radius: normalizedFilters.radius || 10,
					sort: "distance",
				});
			},
			(error) => {
				setLocationStatus("idle");
				setLocationError(locationErrorMessage(error));
			},
			{
				enableHighAccuracy: false,
				timeout: 10000,
				maximumAge: 300000,
			},
		);
	}

	function clearLocation() {
		visit({
			latitude: "",
			longitude: "",
			radius: "",
			sort:
				normalizedFilters.sort === "distance"
					? "name"
					: normalizedFilters.sort,
		});
	}

	return (
		<section className="rounded-lg border border-zinc-950/10 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-slate-900">
			<div className="space-y-3">
				<Field>
					<Label>Buscar sucursal</Label>
					<InputGroup>
						<MagnifyingGlassIcon data-slot="icon" />
						<Input
							type="search"
							value={search}
							onChange={(event) => setSearch(event.target.value)}
							placeholder="Busca por sucursal, colonia, municipio, CP o servicio"
							aria-label="Buscar por sucursal, colonia, municipio, codigo postal o servicio"
						/>
					</InputGroup>
				</Field>

				<div className="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-5">
					<Field>
						<Label>Marca</Label>
						<Listbox
							value={normalizedFilters.brand}
							onChange={(brand) => visit({ brand })}
							placeholder="Todas las marcas"
						>
							<ListboxOption value="">
								<ListboxLabel>Todas las marcas</ListboxLabel>
							</ListboxOption>
							{Object.entries(laboratoryBrands).map(
								([key, brand]) => (
									<ListboxOption key={key} value={key}>
										<ListboxLabel>
											{brand.name}
										</ListboxLabel>
									</ListboxOption>
								),
							)}
						</Listbox>
					</Field>

					<Field>
						<Label>Estado</Label>
						<Listbox
							value={normalizedFilters.state}
							onChange={(state) => visit({ state })}
							placeholder="Todos"
						>
							<ListboxOption value="">
								<ListboxLabel>Todos</ListboxLabel>
							</ListboxOption>
							{states.map((state) => (
								<ListboxOption key={state} value={state}>
									<ListboxLabel>
										{formatTitle(state)}
									</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
					</Field>

					<Field>
						<Label>Municipio</Label>
						<Listbox
							value={normalizedFilters.municipality}
							onChange={(municipality) => visit({ municipality })}
							placeholder="Todos"
						>
							<ListboxOption value="">
								<ListboxLabel>Todos</ListboxLabel>
							</ListboxOption>
							{municipalities.map((municipality) => (
								<ListboxOption
									key={municipality}
									value={municipality}
								>
									<ListboxLabel>
										{formatTitle(municipality)}
									</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
					</Field>

					<Field>
						<Label>Servicio</Label>
						<Listbox
							value={normalizedFilters.service}
							onChange={(service) => visit({ service })}
							placeholder="Todos"
						>
							<ListboxOption value="">
								<ListboxLabel>Todos</ListboxLabel>
							</ListboxOption>
							{services.map((service) => (
								<ListboxOption
									key={service.type}
									value={service.type}
								>
									<ListboxLabel>
										{service.name} ({service.stores_count})
									</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
					</Field>

					<Field>
						<Label>Ordenar</Label>
						<Listbox
							value={normalizedFilters.sort}
							onChange={(sort) => visit({ sort })}
							placeholder="Nombre"
						>
							<ListboxOption value="name">
								<ListboxLabel>Nombre</ListboxLabel>
							</ListboxOption>
							<ListboxOption value="relevance">
								<ListboxLabel>Relevancia</ListboxLabel>
							</ListboxOption>
							{hasLocation && (
								<ListboxOption value="distance">
									<ListboxLabel>Cercanía</ListboxLabel>
								</ListboxOption>
							)}
						</Listbox>
					</Field>
				</div>

				<div className="flex flex-col gap-2 border-t border-zinc-950/10 pt-3 lg:flex-row lg:items-center lg:justify-between dark:border-white/10">
					<div className="space-y-1.5">
						{hasLocation && (
							<p className="text-xs font-semibold uppercase tracking-normal text-famedic-dark dark:text-famedic-lime">
								Ubicación activa ·{" "}
								{normalizedFilters.radius || 10} km
							</p>
						)}
						<div className="flex flex-wrap items-center gap-2">
							<Button
								type="button"
								outline={!hasLocation}
								color={hasLocation ? "famedic" : undefined}
								onClick={requestNearbyStores}
								disabled={locationStatus === "loading"}
							>
								<MapPinIcon data-slot="icon" />
								{locationStatus === "loading"
									? "Obteniendo ubicación..."
									: hasLocation
										? "Actualizar cercanía"
										: "Cerca de mí"}
							</Button>
							{hasLocation && (
								<Button
									type="button"
									plain
									onClick={clearLocation}
								>
									<XMarkIcon data-slot="icon" />
									Quitar ubicación
								</Button>
							)}
						</div>
						{!hasLocation && (
							<p className="text-xs text-zinc-600 dark:text-slate-300">
								La ubicación se usa solo para calcular distancia
								y no se guarda.
							</p>
						)}
						{locationError && (
							<p className="text-sm font-medium text-amber-700 dark:text-amber-300">
								{locationError}
							</p>
						)}
					</div>

					{hasLocation && (
						<Field className="w-full lg:max-w-44">
							<Label>Radio</Label>
							<Listbox
								value={String(normalizedFilters.radius || 10)}
								onChange={(radius) =>
									visit({
										radius,
										sort: "distance",
									})
								}
								placeholder="10 km"
							>
								{[5, 10, 25, 50].map((radius) => (
									<ListboxOption
										key={radius}
										value={String(radius)}
									>
										<ListboxLabel>{radius} km</ListboxLabel>
									</ListboxOption>
								))}
							</Listbox>
						</Field>
					)}
				</div>

				<div className="flex flex-col gap-2">
					<div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-normal text-zinc-500 dark:text-slate-400">
						<AdjustmentsHorizontalIcon className="size-4" />
						Estudios frecuentes
					</div>
					<div className="flex flex-wrap gap-2">
						{capabilityOptions.map((capability) => (
							<FilterChip
								key={capability.slug}
								active={
									normalizedFilters.capability ===
									capability.slug
								}
								onClick={() =>
									visit({
										capability:
											normalizedFilters.capability ===
											capability.slug
												? ""
												: capability.slug,
									})
								}
							>
								{capability.name} ({capability.stores_count})
							</FilterChip>
						))}
						{services.map((service) => (
							<FilterChip
								key={service.type}
								active={
									normalizedFilters.service === service.type
								}
								tone="service"
								onClick={() =>
									visit({
										service:
											normalizedFilters.service ===
											service.type
												? ""
												: service.type,
									})
								}
							>
								{service.name} ({service.stores_count})
							</FilterChip>
						))}
					</div>
				</div>

				{hasActiveFilters && (
					<div className="flex justify-end">
						<Button type="button" outline onClick={clearFilters}>
							<XMarkIcon data-slot="icon" />
							Limpiar filtros
						</Button>
					</div>
				)}
			</div>
		</section>
	);
}

function FilterChip({ active, tone = "capability", children, onClick }) {
	return (
		<BadgeButton
			type="button"
			color={active ? (tone === "service" ? "sky" : "famedic") : "zinc"}
			onClick={onClick}
			aria-pressed={active}
		>
			{children}
		</BadgeButton>
	);
}

function formatTitle(value) {
	if (!value) {
		return "";
	}

	return value
		.toLocaleLowerCase("es-MX")
		.replace(/(^|\s)\S/g, (letter) => letter.toLocaleUpperCase("es-MX"));
}

function locationErrorMessage(error) {
	if (error.code === error.PERMISSION_DENIED) {
		return "Activa el permiso de ubicacion en tu navegador para encontrar sucursales cercanas.";
	}

	if (error.code === error.POSITION_UNAVAILABLE) {
		return "No pudimos acceder a tu ubicacion en este momento.";
	}

	if (error.code === error.TIMEOUT) {
		return "La solicitud de ubicacion tardo demasiado. Intenta de nuevo.";
	}

	return "No pudimos acceder a tu ubicacion.";
}
