import { lazy, Suspense, useEffect, useMemo, useState } from "react";
import * as Headless from "@headlessui/react";
import { router, useForm } from "@inertiajs/react";
import {
	ArchiveBoxIcon,
	ArrowPathIcon,
	BuildingStorefrontIcon,
	CheckCircleIcon,
	ClipboardDocumentIcon,
	ExclamationTriangleIcon,
	MapPinIcon,
	MagnifyingGlassIcon,
	PencilSquareIcon,
	PhoneIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";
import { ListBulletIcon, MapIcon } from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text, Strong, Code } from "@/Components/Catalyst/text";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import EmptyListCard from "@/Components/EmptyListCard";
import { coordinateQuality } from "@/lib/adminLaboratoryStoresMap";

const LaboratoryStoresMap = lazy(
	() => import("@/Components/Admin/LaboratoryStoresMap"),
);

const ACTIVE_STATUS = {
	active: "Activa",
	inactive: "Inactiva",
	historical: "Histórica",
};

const STATUS_BADGE = {
	Activa: "green",
	Inactiva: "zinc",
	Histórica: "amber",
};

const DETAIL_TABS = [
	"Resumen",
	"Ubicación",
	"Horarios",
	"Servicios",
	"Calidad",
	"Historial",
];

export default function LaboratoryStores({
	laboratoryStores,
	mapStores = [],
	mapSummary = { total: 0, with_coordinates: 0, missing_coordinates: 0 },
	storeDetail,
	filters,
	brands,
	filterOptions,
	summary,
	can,
	drawerMode,
	gdaWarning,
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		brand: filters.brand || "",
		state: filters.state || "",
		municipality: filters.municipality || "",
		active_status: filters.active_status || "",
		location_status: filters.location_status || "",
		service: filters.service || "",
		capability: filters.capability || "",
		data_status: filters.data_status || "",
		view: filters.view || "list",
	});
	const [showFilters, setShowFilters] = useState(false);
	const [selectedStoreId, setSelectedStoreId] = useState(
		storeDetail?.id || null,
	);
	const currentView = ["list", "map", "split"].includes(data.view)
		? data.view
		: "list";

	useEffect(() => {
		const timeout = window.setTimeout(() => {
			const changed =
				(data.search || "") !== (filters.search || "") ||
				(data.brand || "") !== (filters.brand || "") ||
				(data.state || "") !== (filters.state || "") ||
				(data.municipality || "") !== (filters.municipality || "") ||
				(data.active_status || "") !== (filters.active_status || "") ||
				(data.location_status || "") !==
					(filters.location_status || "") ||
				(data.service || "") !== (filters.service || "") ||
				(data.capability || "") !== (filters.capability || "") ||
				(data.data_status || "") !== (filters.data_status || "") ||
				(data.view || "list") !== (filters.view || "list");

			if (!changed || processing) return;

			get(route("admin.laboratory-stores.index"), {
				replace: true,
				preserveState: true,
				preserveScroll: true,
			});
		}, 350);

		return () => window.clearTimeout(timeout);
	}, [data, filters, get, processing]);

	useEffect(() => {
		if (storeDetail?.id) {
			setSelectedStoreId(storeDetail.id);
		}
	}, [storeDetail?.id]);

	useEffect(() => {
		if (currentView !== "split" || !selectedStoreId) {
			return;
		}

		document
			.getElementById(`admin-laboratory-store-${selectedStoreId}`)
			?.scrollIntoView({ behavior: "smooth", block: "center" });
	}, [currentView, selectedStoreId]);

	const filteredCount = useMemo(
		() =>
			Object.values({
				brand: filters.brand,
				state: filters.state,
				municipality: filters.municipality,
				active_status: filters.active_status,
				location_status: filters.location_status,
				service: filters.service,
				capability: filters.capability,
				data_status: filters.data_status,
			}).filter(Boolean).length,
		[filters],
	);

	const closeDrawer = () => {
		router.get(route("admin.laboratory-stores.index"), filters, {
			preserveScroll: true,
			preserveState: true,
		});
	};

	const visitStore = (store, edit = false) => {
		router.get(
			store.show_url,
			{
				...filters,
				view: currentView,
				...(edit ? { edit: 1 } : {}),
			},
			{
				preserveScroll: true,
				preserveState: true,
			},
		);
	};

	const setView = (view) => {
		setData("view", view);
	};

	return (
		<AdminLayout title="Sucursales de laboratorio">
			<div className="space-y-6">
				<header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
					<div className="space-y-2">
						<Heading>Sucursales</Heading>
						<Text>Administración operativa de sucursales GDA.</Text>
					</div>
					<div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
						<ExclamationTriangleIcon className="mr-2 inline size-4" />
						{gdaWarning}
					</div>
				</header>

				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
					<SummaryMetric
						label="Activas"
						value={summary.active_stores_count}
					/>
					<SummaryMetric
						label="Inactivas"
						value={summary.inactive_stores_count}
					/>
					<SummaryMetric
						label="Marcas"
						value={summary.brands_count}
					/>
					<SummaryMetric
						label="Con alertas"
						value={summary.data_alerts_count}
						tone="amber"
					/>
				</div>

				<section className="space-y-3">
					<div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
						<div className="flex-1 lg:max-w-xl">
							<InputGroup>
								<MagnifyingGlassIcon />
								<Input
									placeholder="Buscar por nombre, dirección, colonia, municipio, CP o teléfono"
									value={data.search}
									onChange={(event) =>
										setData("search", event.target.value)
									}
									aria-label="Buscar sucursales"
								/>
							</InputGroup>
						</div>
						<Button
							outline
							onClick={() => setShowFilters(!showFilters)}
						>
							Filtros
							{filteredCount > 0 && (
								<Badge color="sky">{filteredCount}</Badge>
							)}
						</Button>
					</div>

					{showFilters && (
						<Filters
							data={data}
							setData={setData}
							brands={brands}
							filterOptions={filterOptions}
						/>
					)}
				</section>

				<div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
					<ViewToggle view={currentView} onChange={setView} />
					<MapCounts
						summary={mapSummary}
						onMissingCoordinates={() =>
							setData("location_status", "missing_coordinates")
						}
					/>
				</div>

				{currentView === "list" && (
					<StoresList stores={laboratoryStores} brands={brands} />
				)}

				{currentView === "map" && (
					<AdminMapPanel
						stores={mapStores}
						selectedStoreId={selectedStoreId}
						onSelectStore={setSelectedStoreId}
						onOpenStore={visitStore}
						onEditStore={(store) => visitStore(store, true)}
					/>
				)}

				{currentView === "split" && (
					<div className="grid gap-4 xl:grid-cols-[minmax(18rem,35%)_minmax(0,1fr)]">
						<div className="hidden max-h-[calc(100vh-13rem)] min-w-0 overflow-y-auto overscroll-contain pr-1 xl:block">
							<StoresList
								stores={laboratoryStores}
								brands={brands}
								compact
								selectedStoreId={selectedStoreId}
								onSelectStore={setSelectedStoreId}
							/>
						</div>
						<div className="min-w-0 xl:sticky xl:top-4 xl:self-start">
							<AdminMapPanel
								stores={mapStores}
								selectedStoreId={selectedStoreId}
								onSelectStore={setSelectedStoreId}
								onOpenStore={visitStore}
								onEditStore={(store) => visitStore(store, true)}
							/>
						</div>
						<div className="min-w-0 xl:hidden">
							<StoresList
								stores={laboratoryStores}
								brands={brands}
							/>
						</div>
					</div>
				)}

				<StoreDrawer
					open={Boolean(storeDetail)}
					store={storeDetail}
					brands={brands}
					filterOptions={filterOptions}
					canUpdate={can.update}
					initialEditing={drawerMode === "edit"}
					onClose={closeDrawer}
				/>
			</div>
		</AdminLayout>
	);
}

function SummaryMetric({ label, value, tone = "slate" }) {
	const toneClass =
		tone === "amber"
			? "text-amber-700 dark:text-amber-300"
			: "text-slate-950 dark:text-white";

	return (
		<div className="rounded-lg border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
			<p className="text-xs font-medium uppercase tracking-wide text-slate-400">
				{label}
			</p>
			<p className={`mt-1 text-2xl font-semibold ${toneClass}`}>
				{Number(value || 0).toLocaleString()}
			</p>
		</div>
	);
}

function ViewToggle({ view, onChange }) {
	const options = [
		{ value: "list", label: "Lista", icon: ListBulletIcon },
		{ value: "map", label: "Mapa", icon: MapIcon },
		{ value: "split", label: "Mixto", icon: MapPinIcon, desktopOnly: true },
	];

	return (
		<div
			className="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900"
			role="group"
			aria-label="Cambiar vista de sucursales"
		>
			{options.map(({ value, label, icon: Icon, desktopOnly }) => (
				<button
					key={value}
					type="button"
					aria-pressed={view === value}
					onClick={() => onChange(value)}
					className={`inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime ${
						desktopOnly ? "hidden xl:inline-flex" : ""
					} ${
						view === value
							? "bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker"
							: "text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10"
					}`}
				>
					<Icon className="size-4" />
					{label}
				</button>
			))}
		</div>
	);
}

function MapCounts({ summary, onMissingCoordinates }) {
	return (
		<div className="flex flex-wrap items-center gap-2 text-sm">
			<Strong>{summary.total} sucursales</Strong>
			<Text>
				{summary.with_coordinates} con ubicación ·{" "}
				{summary.missing_coordinates} sin coordenadas
			</Text>
			{summary.missing_coordinates > 0 && (
				<Button type="button" outline onClick={onMissingCoordinates}>
					Ver sin ubicación
				</Button>
			)}
		</div>
	);
}

function AdminMapPanel({
	stores,
	selectedStoreId,
	onSelectStore,
	onOpenStore,
	onEditStore,
}) {
	return (
		<Suspense fallback={<MapLoadingState />}>
			<LaboratoryStoresMap
				stores={stores}
				selectedStoreId={selectedStoreId}
				onSelectStore={onSelectStore}
				onOpenStore={onOpenStore}
				onEditStore={onEditStore}
				heightClass="min-h-[60vh] lg:min-h-[calc(100vh-15rem)]"
			/>
			{selectedStoreId && (
				<div className="mt-3 flex justify-end">
					<Button
						outline
						type="button"
						onClick={() => {
							const store = stores.find(
								(store) => store.id === selectedStoreId,
							);

							if (store) {
								onOpenStore?.(store);
							}
						}}
					>
						Ver detalle seleccionado
					</Button>
				</div>
			)}
		</Suspense>
	);
}

function MapLoadingState() {
	return (
		<div className="flex min-h-[60vh] items-center justify-center rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
			Cargando mapa...
		</div>
	);
}

function Filters({ data, setData, brands, filterOptions }) {
	return (
		<div className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-2 xl:grid-cols-4 dark:border-slate-800 dark:bg-slate-900">
			<SelectField
				label="Marca"
				value={data.brand}
				onChange={(value) => setData("brand", value)}
			>
				<option value="">Todas las marcas</option>
				{Object.entries(brands || {}).map(([value, brand]) => (
					<option key={value} value={value}>
						{brand.name}
					</option>
				))}
			</SelectField>
			<SelectField
				label="Estado"
				value={data.state}
				onChange={(value) => setData("state", value)}
			>
				<option value="">Todos los estados</option>
				{(filterOptions.states || []).map((state) => (
					<option key={state} value={state}>
						{state}
					</option>
				))}
			</SelectField>
			<SelectField
				label="Municipio"
				value={data.municipality}
				onChange={(value) => setData("municipality", value)}
			>
				<option value="">Todos los municipios</option>
				{(filterOptions.municipalities || []).map((municipality) => (
					<option key={municipality} value={municipality}>
						{municipality}
					</option>
				))}
			</SelectField>
			<SelectField
				label="Estado activo"
				value={data.active_status}
				onChange={(value) => setData("active_status", value)}
			>
				<option value="">Todas</option>
				{Object.entries(ACTIVE_STATUS).map(([value, label]) => (
					<option key={value} value={value}>
						{label}
					</option>
				))}
			</SelectField>
			<SelectField
				label="Ubicación"
				value={data.location_status}
				onChange={(value) => setData("location_status", value)}
			>
				<option value="">Todas</option>
				<option value="with_coordinates">Con coordenadas</option>
				<option value="missing_coordinates">Sin coordenadas</option>
			</SelectField>
			<SelectField
				label="Servicio especial"
				value={data.service}
				onChange={(value) => setData("service", value)}
			>
				<option value="">Todos</option>
				{(filterOptions.services || []).map((service) => (
					<option key={service} value={service}>
						{serviceLabel(service)}
					</option>
				))}
			</SelectField>
			<SelectField
				label="Capability"
				value={data.capability}
				onChange={(value) => setData("capability", value)}
			>
				<option value="">Todas</option>
				{(filterOptions.capabilities || []).map((capability) => (
					<option key={capability.slug} value={capability.slug}>
						{capability.name}
					</option>
				))}
			</SelectField>
			<SelectField
				label="Estado de datos"
				value={data.data_status}
				onChange={(value) => setData("data_status", value)}
			>
				<option value="">Todos</option>
				{(filterOptions.data_statuses || []).map((status) => (
					<option key={status.value} value={status.value}>
						{status.label}
					</option>
				))}
			</SelectField>
		</div>
	);
}

function SelectField({ label, value, onChange, children }) {
	return (
		<Field>
			<Label>{label}</Label>
			<Select
				value={value}
				onChange={(event) => onChange(event.target.value)}
			>
				{children}
			</Select>
		</Field>
	);
}

function StoresList({
	stores,
	brands,
	compact = false,
	selectedStoreId = null,
	onSelectStore = null,
}) {
	if (!stores?.data?.length) return <EmptyListCard />;

	return (
		<PaginatedTable paginatedData={stores}>
			<div className="space-y-3">
				{stores.data.map((store) => (
					<StoreRow
						key={store.id}
						store={store}
						brand={brands[store.brand]}
						compact={compact}
						selected={selectedStoreId === store.id}
						onSelectStore={onSelectStore}
					/>
				))}
			</div>
		</PaginatedTable>
	);
}

function StoreRow({
	store,
	brand,
	compact = false,
	selected = false,
	onSelectStore = null,
}) {
	const quality = displayDataQuality(store);

	return (
		<article
			id={`admin-laboratory-store-${store.id}`}
			onClick={() => onSelectStore?.(store.id)}
			onKeyDown={(event) => {
				if (!onSelectStore) {
					return;
				}

				if (event.key === "Enter" || event.key === " ") {
					event.preventDefault();
					onSelectStore(store.id);
				}
			}}
			role={onSelectStore ? "button" : undefined}
			tabIndex={onSelectStore ? 0 : undefined}
			className={`rounded-lg border bg-white p-4 shadow-sm transition hover:border-famedic-lime/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime focus-visible:ring-offset-2 dark:bg-slate-900 ${
				selected
					? "border-famedic-lime/70 bg-famedic-lime/5 ring-1 ring-famedic-lime/20"
					: "border-slate-200 dark:border-slate-800"
			} ${onSelectStore ? "cursor-pointer" : ""}`}
		>
			<div
				className={`grid gap-4 ${
					compact
						? "lg:grid-cols-1"
						: "lg:grid-cols-[minmax(0,1.25fr)_minmax(0,0.75fr)_minmax(0,0.9fr)_minmax(0,0.85fr)_auto]"
				} lg:items-center`}
			>
				<div className="min-w-0 space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<BuildingStorefrontIcon className="size-5 text-famedic-dark dark:text-famedic-lime" />
						<Strong className="truncate">{store.name}</Strong>
						<Code>#{store.id}</Code>
					</div>
					<Text className="text-sm">
						{[store.municipality, store.state]
							.filter(Boolean)
							.join(" · ") || "Ubicación pendiente"}
					</Text>
					{store.postal_code && (
						<Text className="text-xs">CP {store.postal_code}</Text>
					)}
				</div>

				<div className={compact ? "hidden" : "space-y-1"}>
					<p className="text-xs uppercase tracking-wide text-slate-400">
						Marca
					</p>
					<Strong>{brand?.name || store.brand_label}</Strong>
				</div>

				<div className="space-y-2">
					<p className="text-xs uppercase tracking-wide text-slate-400">
						Servicios
					</p>
					<ServiceFlags store={store} />
					<Text className="text-xs">
						{store.capabilities_count} capabilities
					</Text>
				</div>

				<div className="space-y-2">
					<div className="flex flex-wrap gap-1.5">
						<Badge
							color={STATUS_BADGE[store.status_label] || "zinc"}
						>
							{store.status_label}
						</Badge>
						<Badge color={quality.color}>{quality.label}</Badge>
					</div>
					<Text className="text-xs">
						{store.field_conflicts_count > 0
							? `${store.field_conflicts_count} conflictos`
							: "Sin conflictos"}
					</Text>
				</div>

				<div className="flex flex-wrap gap-2 lg:justify-end">
					{store.phone && (
						<Button
							outline
							href={`tel:${store.phone}`}
							aria-label="Llamar sucursal"
						>
							<PhoneIcon />
						</Button>
					)}
					<Button
						href={store.show_url}
						outline
						onClick={(event) => event.stopPropagation()}
					>
						Ver
					</Button>
				</div>
			</div>
		</article>
	);
}

function ServiceFlags({ store }) {
	const services = [
		store.clinical_history_services_count > 0 && "Historia Clínica",
		store.optical_services_count > 0 && "Óptica",
	].filter(Boolean);

	if (!services.length) {
		return <Text className="text-xs">Sin servicios activos</Text>;
	}

	return (
		<div className="flex flex-wrap gap-1.5">
			{services.map((service) => (
				<Badge key={service} color="sky">
					{service}
				</Badge>
			))}
		</div>
	);
}

function displayDataQuality(store) {
	const coordinateState = coordinateQuality(store);

	if (coordinateState.value !== "ok") {
		return coordinateState;
	}

	if (store.data_quality?.value === "conflict") {
		return {
			...store.data_quality,
			label: "Conflicto GDA",
		};
	}

	return store.data_quality || coordinateState;
}

function StoreDrawer({
	open,
	store,
	brands,
	filterOptions,
	canUpdate,
	initialEditing = false,
	onClose,
}) {
	const [editing, setEditing] = useState(false);

	useEffect(() => {
		setEditing(Boolean(initialEditing));
	}, [initialEditing, store?.id]);

	const copyCoordinates = () => {
		if (!store?.latitude || !store?.longitude) return;
		window.navigator?.clipboard?.writeText(
			`${store.latitude}, ${store.longitude}`,
		);
	};

	return (
		<Headless.Dialog
			open={open}
			onClose={onClose}
			className="relative z-50"
		>
			<Headless.DialogBackdrop className="data-closed:opacity-0 fixed inset-0 bg-slate-950/40 transition" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-3xl flex-col bg-white shadow-xl dark:bg-slate-900">
					{store && (
						<>
							<header className="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
								<div className="flex items-start justify-between gap-3">
									<div className="min-w-0 space-y-2">
										<p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
											Sucursal de laboratorio
										</p>
										<Headless.DialogTitle className="truncate text-xl font-semibold text-slate-950 dark:text-white">
											{store.name}
										</Headless.DialogTitle>
										<div className="flex flex-wrap gap-1.5">
											<Badge>
												{brands[store.brand]?.name ||
													store.brand_label}
											</Badge>
											<Badge
												color={
													STATUS_BADGE[
														store.status_label
													] || "zinc"
												}
											>
												{store.status_label}
											</Badge>
											<Code>ID {store.id}</Code>
										</div>
									</div>
									<Button
										plain
										onClick={onClose}
										aria-label="Cerrar"
									>
										<XMarkIcon />
									</Button>
								</div>
								<div className="mt-4 flex flex-wrap gap-2">
									{store.google_maps_url && (
										<Button
											outline
											href={store.google_maps_url}
											target="_blank"
										>
											<MapPinIcon />
											Ver en mapa
										</Button>
									)}
									<Button
										outline
										href={store.public_url}
										target="_blank"
									>
										<MapPinIcon />
										Ver sucursal pública
									</Button>
									{store.latitude && store.longitude && (
										<Button
											outline
											onClick={copyCoordinates}
										>
											<ClipboardDocumentIcon />
											Copiar coordenadas
										</Button>
									)}
									{canUpdate && !editing && (
										<Button
											onClick={() => setEditing(true)}
											color="famedic-lime"
										>
											<PencilSquareIcon />
											Editar
										</Button>
									)}
								</div>
							</header>

							<div className="flex-1 overflow-y-auto px-5 py-5">
								{editing ? (
									<StoreEditSections
										store={store}
										filterOptions={filterOptions}
										onCancel={() => setEditing(false)}
									/>
								) : (
									<StoreDetail
										store={store}
										canUpdate={canUpdate}
										onEditLocation={() => setEditing(true)}
									/>
								)}
							</div>
						</>
					)}
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}

function StoreDetail({ store, canUpdate, onEditLocation }) {
	const [tab, setTab] = useState(0);
	const quality = displayDataQuality(store);

	return (
		<div className="space-y-5">
			<div className="overflow-x-auto">
				<div className="flex gap-2 border-b border-slate-200 dark:border-slate-800">
					{DETAIL_TABS.map((label, index) => (
						<button
							key={label}
							type="button"
							onClick={() => setTab(index)}
							className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime ${
								tab === index
									? "border-famedic-lime text-slate-950 dark:text-white"
									: "border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
							}`}
						>
							{label}
						</button>
					))}
				</div>
			</div>

			{tab === 0 && (
				<div className="space-y-5">
					<Notice tone="amber">
						Esta sucursal puede volver a sincronizarse desde GDA.
						Los cambios manuales podrían ser reemplazados en una
						futura importación.
					</Notice>
					<DetailSection title="Resumen">
						<DetailGrid
							items={[
								["ID", store.id],
								["Marca", store.brand_label],
								["Estado", store.status_label],
								["Calidad", quality.label],
								["Fuente", sourceLabel(store.source)],
								[
									"Citas relacionadas",
									store.laboratory_appointments_count,
								],
							]}
						/>
					</DetailSection>
					<DetailSection title="Contacto">
						<div className="flex flex-wrap gap-2">
							{store.phone ? (
								<>
									<Button outline href={`tel:${store.phone}`}>
										<PhoneIcon />
										{store.phone}
									</Button>
									<CopyButton value={store.phone}>
										Copiar teléfono
									</CopyButton>
								</>
							) : (
								<Text>Sin teléfono registrado</Text>
							)}
							{store.postal_code && (
								<CopyButton value={store.postal_code}>
									Copiar CP
								</CopyButton>
							)}
							{store.address && (
								<CopyButton value={store.address}>
									Copiar dirección
								</CopyButton>
							)}
						</div>
					</DetailSection>
					{canUpdate && (
						<DetailSection title="Estado">
							<StatusActions store={store} />
						</DetailSection>
					)}
				</div>
			)}

			{tab === 1 && (
				<div className="space-y-5">
					<DetailSection title="Mapa">
						<StoreMapPreview
							store={store}
							canUpdate={canUpdate}
							onEditLocation={onEditLocation}
						/>
					</DetailSection>
					<DetailSection title="Dirección y coordenadas">
						<DetailGrid
							items={[
								["Dirección", store.address],
								["Calle", store.street],
								["Número exterior", store.exterior_number],
								["Número interior", store.interior_number],
								["Colonia", store.neighborhood],
								["Municipio", store.municipality],
								["Ciudad", store.city],
								["Estado", store.state],
								["CP", store.postal_code],
								["Latitud", store.latitude],
								["Longitud", store.longitude],
							]}
						/>
					</DetailSection>
				</div>
			)}

			{tab === 2 && (
				<DetailSection title="Horarios">
					<HoursReadOnly hours={store.hours} />
				</DetailSection>
			)}

			{tab === 3 && (
				<div className="space-y-5">
					<DetailSection title="Servicios especiales">
						<ServicesReadOnly services={store.services} />
					</DetailSection>
					<DetailSection title="Capabilities">
						<ChipList
							items={store.capabilities.map(
								(capability) => capability.name,
							)}
							empty="Sin capabilities registradas."
						/>
					</DetailSection>
				</div>
			)}

			{tab === 4 && (
				<DetailSection title="Calidad del dato">
					<DataQualityPanel store={store} />
				</DetailSection>
			)}

			{tab === 5 && (
				<DetailSection title="Historial">
					<HistoryList history={store.history} />
				</DetailSection>
			)}
		</div>
	);
}

function StoreEditSections({ store, filterOptions, onCancel }) {
	return (
		<div className="space-y-6">
			<Notice tone="amber">
				Esta sucursal puede volver a sincronizarse desde GDA. Los
				cambios manuales podrían ser reemplazados en una futura
				importación.
			</Notice>
			<BasicFieldsForm store={store} onCancel={onCancel} />
			<HoursForm store={store} />
			<CapabilitiesForm
				store={store}
				capabilities={filterOptions.all_capabilities || []}
			/>
			<ServicesForm
				store={store}
				services={filterOptions.editable_services || []}
			/>
		</div>
	);
}

function BasicFieldsForm({ store, onCancel }) {
	const { data, setData, patch, processing, errors } = useForm({
		name: store.name || "",
		phone: store.phone || "",
		address: store.address || "",
		street: store.street || "",
		exterior_number: store.exterior_number || "",
		interior_number: store.interior_number || "",
		neighborhood: store.neighborhood || "",
		municipality: store.municipality || "",
		city: store.city || "",
		state: store.state || "",
		postal_code: store.postal_code || "",
		google_maps_url: store.google_maps_url || "",
		latitude: store.latitude || "",
		longitude: store.longitude || "",
		is_active: Boolean(store.is_active),
	});

	const submit = (event) => {
		event.preventDefault();
		patch(store.update_url, {
			preserveScroll: true,
			onSuccess: onCancel,
		});
	};

	return (
		<form className="space-y-4" onSubmit={submit}>
			<DetailSection title="Información general">
				<div className="grid gap-3 sm:grid-cols-2">
					<TextInput
						label="Nombre"
						value={data.name}
						error={errors.name}
						onChange={(value) => setData("name", value)}
					/>
					<TextInput
						label="Teléfono"
						value={data.phone}
						error={errors.phone}
						onChange={(value) => setData("phone", value)}
					/>
				</div>
				<label className="mt-3 flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
					<input
						type="checkbox"
						checked={data.is_active}
						onChange={(event) =>
							setData("is_active", event.target.checked)
						}
						className="size-4 rounded border-slate-300 text-famedic-dark focus:ring-famedic-lime"
					/>
					Sucursal activa
				</label>
			</DetailSection>

			<DetailSection title="Ubicación">
				<div className="space-y-3">
					<LocationEditMap
						store={store}
						latitude={data.latitude}
						longitude={data.longitude}
						onChange={({ latitude, longitude }) => {
							setData({
								...data,
								latitude,
								longitude,
							});
						}}
					/>
					<Field>
						<Label>Dirección completa</Label>
						<Textarea
							value={data.address}
							onChange={(event) =>
								setData("address", event.target.value)
							}
						/>
						{errors.address && (
							<ErrorMessage>{errors.address}</ErrorMessage>
						)}
					</Field>
					<div className="grid gap-3 sm:grid-cols-2">
						<TextInput
							label="Calle"
							value={data.street}
							error={errors.street}
							onChange={(value) => setData("street", value)}
						/>
						<TextInput
							label="Número exterior"
							value={data.exterior_number}
							error={errors.exterior_number}
							onChange={(value) =>
								setData("exterior_number", value)
							}
						/>
						<TextInput
							label="Número interior"
							value={data.interior_number}
							error={errors.interior_number}
							onChange={(value) =>
								setData("interior_number", value)
							}
						/>
						<TextInput
							label="Colonia"
							value={data.neighborhood}
							error={errors.neighborhood}
							onChange={(value) => setData("neighborhood", value)}
						/>
						<TextInput
							label="Municipio"
							value={data.municipality}
							error={errors.municipality}
							onChange={(value) => setData("municipality", value)}
						/>
						<TextInput
							label="Ciudad"
							value={data.city}
							error={errors.city}
							onChange={(value) => setData("city", value)}
						/>
						<TextInput
							label="Estado"
							value={data.state}
							error={errors.state}
							onChange={(value) => setData("state", value)}
						/>
						<TextInput
							label="CP"
							value={data.postal_code}
							error={errors.postal_code}
							onChange={(value) => setData("postal_code", value)}
						/>
						<TextInput
							label="Latitud"
							value={data.latitude}
							error={errors.latitude}
							onChange={(value) => setData("latitude", value)}
						/>
						<TextInput
							label="Longitud"
							value={data.longitude}
							error={errors.longitude}
							onChange={(value) => setData("longitude", value)}
						/>
					</div>
					<TextInput
						label="Google Maps URL"
						value={data.google_maps_url}
						error={errors.google_maps_url}
						onChange={(value) => setData("google_maps_url", value)}
					/>
				</div>
			</DetailSection>

			<FormActions processing={processing} onCancel={onCancel}>
				Guardar información general
			</FormActions>
		</form>
	);
}

function HoursForm({ store }) {
	const { data, setData, patch, processing, errors } = useForm({
		hours: store.hours.map((hour) => ({
			day_of_week: hour.day_of_week,
			day_label: hour.day_label,
			is_closed: Boolean(hour.is_closed),
			opens_at: hour.input_opens_at || "",
			closes_at: hour.input_closes_at || "",
		})),
	});

	const updateHour = (index, changes) => {
		setData(
			"hours",
			data.hours.map((hour, hourIndex) =>
				hourIndex === index ? { ...hour, ...changes } : hour,
			),
		);
	};

	const submit = (event) => {
		event.preventDefault();
		patch(store.update_hours_url, { preserveScroll: true });
	};

	return (
		<form className="space-y-3" onSubmit={submit}>
			<DetailSection title="Horarios">
				<div className="space-y-2">
					{data.hours.map((hour, index) => (
						<div
							key={hour.day_of_week}
							className="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-[1fr_auto_auto] sm:items-center dark:border-slate-800"
						>
							<label className="flex items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-100">
								<input
									type="checkbox"
									checked={!hour.is_closed}
									onChange={(event) =>
										updateHour(index, {
											is_closed: !event.target.checked,
											opens_at: event.target.checked
												? hour.opens_at
												: "",
											closes_at: event.target.checked
												? hour.closes_at
												: "",
										})
									}
									className="size-4 rounded border-slate-300 text-famedic-dark focus:ring-famedic-lime"
								/>
								{hour.day_label}
							</label>
							<Input
								type="time"
								value={hour.opens_at}
								disabled={hour.is_closed}
								onChange={(event) =>
									updateHour(index, {
										opens_at: event.target.value,
									})
								}
								aria-label={`Apertura ${hour.day_label}`}
							/>
							<Input
								type="time"
								value={hour.closes_at}
								disabled={hour.is_closed}
								onChange={(event) =>
									updateHour(index, {
										closes_at: event.target.value,
									})
								}
								aria-label={`Cierre ${hour.day_label}`}
							/>
							{errors[`hours.${index}.opens_at`] && (
								<ErrorMessage className="sm:col-span-3">
									{errors[`hours.${index}.opens_at`]}
								</ErrorMessage>
							)}
							{errors[`hours.${index}.closes_at`] && (
								<ErrorMessage className="sm:col-span-3">
									{errors[`hours.${index}.closes_at`]}
								</ErrorMessage>
							)}
						</div>
					))}
				</div>
			</DetailSection>
			<FormActions processing={processing}>Guardar horarios</FormActions>
		</form>
	);
}

function CapabilitiesForm({ store, capabilities }) {
	const [search, setSearch] = useState("");
	const { data, setData, patch, processing, errors } = useForm({
		capability_ids: store.capabilities.map((capability) => capability.id),
	});

	const filteredCapabilities = capabilities.filter((capability) =>
		capability.name.toLowerCase().includes(search.toLowerCase()),
	);

	const toggle = (id) => {
		const next = data.capability_ids.includes(id)
			? data.capability_ids.filter((capabilityId) => capabilityId !== id)
			: [...data.capability_ids, id];
		setData("capability_ids", next);
	};

	const submit = (event) => {
		event.preventDefault();
		patch(store.update_capabilities_url, { preserveScroll: true });
	};

	return (
		<form className="space-y-3" onSubmit={submit}>
			<DetailSection title="Capabilities">
				<ChipList
					items={capabilities
						.filter((capability) =>
							data.capability_ids.includes(capability.id),
						)
						.map((capability) => capability.name)}
					empty="Sin capabilities seleccionadas."
				/>
				<Input
					placeholder="Buscar capability"
					value={search}
					onChange={(event) => setSearch(event.target.value)}
					aria-label="Buscar capability"
				/>
				<div className="grid max-h-72 gap-2 overflow-y-auto rounded-lg border border-slate-200 p-3 sm:grid-cols-2 dark:border-slate-800">
					{filteredCapabilities.map((capability) => (
						<label
							key={capability.id}
							className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"
						>
							<input
								type="checkbox"
								checked={data.capability_ids.includes(
									capability.id,
								)}
								onChange={() => toggle(capability.id)}
								className="size-4 rounded border-slate-300 text-famedic-dark focus:ring-famedic-lime"
							/>
							<span>{capability.name}</span>
							{capability.is_active === false && (
								<Badge color="zinc">Inactiva</Badge>
							)}
						</label>
					))}
				</div>
				{errors.capability_ids && (
					<ErrorMessage>{errors.capability_ids}</ErrorMessage>
				)}
			</DetailSection>
			<FormActions processing={processing}>
				Guardar servicios y capacidades
			</FormActions>
		</form>
	);
}

function ServicesForm({ store, services }) {
	const { data, setData, patch, processing, errors } = useForm({
		services: services.map((service) => ({
			service_type: service.service_type,
			name: service.name,
			is_active: store.services.some(
				(current) =>
					current.service_type === service.service_type &&
					current.is_active,
			),
		})),
	});

	const updateService = (index, isActive) => {
		setData(
			"services",
			data.services.map((service, serviceIndex) =>
				serviceIndex === index
					? { ...service, is_active: isActive }
					: service,
			),
		);
	};

	const submit = (event) => {
		event.preventDefault();
		patch(store.update_services_url, { preserveScroll: true });
	};

	return (
		<form className="space-y-3" onSubmit={submit}>
			<DetailSection title="Servicios especiales">
				<div className="grid gap-2 sm:grid-cols-2">
					{data.services.map((service, index) => (
						<label
							key={service.service_type}
							className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-800"
						>
							<span className="font-medium text-slate-800 dark:text-slate-100">
								{service.name}
							</span>
							<input
								type="checkbox"
								checked={service.is_active}
								onChange={(event) =>
									updateService(index, event.target.checked)
								}
								className="size-4 rounded border-slate-300 text-famedic-dark focus:ring-famedic-lime"
							/>
						</label>
					))}
				</div>
				{errors.services && (
					<ErrorMessage>{errors.services}</ErrorMessage>
				)}
			</DetailSection>
			<FormActions processing={processing}>
				Guardar servicios especiales
			</FormActions>
		</form>
	);
}

function StoreMapPreview({ store, canUpdate, onEditLocation }) {
	const coordinateState = coordinateQuality(store);

	if (coordinateState.value !== "ok") {
		return (
			<div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-5 dark:border-slate-700 dark:bg-slate-900/60">
				<div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
					<div className="space-y-1">
						<Badge color={coordinateState.color}>
							{coordinateState.label}
						</Badge>
						<Text className="text-sm">
							Esta sucursal no tiene un pin listo para mostrarse en
							el mapa.
						</Text>
					</div>
					{canUpdate && (
						<Button
							type="button"
							outline
							onClick={onEditLocation}
						>
							<PencilSquareIcon />
							Editar ubicación
						</Button>
					)}
				</div>
			</div>
		);
	}

	return (
		<Suspense fallback={<DrawerMapLoadingState />}>
			<LaboratoryStoresMap
				stores={[store]}
				selectedStoreId={store.id}
				compact
				heightClass="min-h-[240px]"
			/>
		</Suspense>
	);
}

function LocationEditMap({ store, latitude, longitude, onChange }) {
	const coordinateState = coordinateQuality(latitude, longitude);
	const coordinatesChanged =
		String(latitude ?? "") !== String(store.latitude ?? "") ||
		String(longitude ?? "") !== String(store.longitude ?? "");
	const previewStore =
		coordinateState.value === "ok"
			? [
					{
						...store,
						latitude,
						longitude,
					},
				]
			: [];

	return (
		<div className="space-y-2">
			{coordinateState.value === "ok" ? (
				<Suspense fallback={<DrawerMapLoadingState />}>
					<LaboratoryStoresMap
						stores={previewStore}
						selectedStoreId={store.id}
						compact
						draggable
						onCoordinatesChange={onChange}
						heightClass="min-h-[240px]"
					/>
				</Suspense>
			) : (
				<div className="flex min-h-[180px] items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 text-center dark:border-slate-700 dark:bg-slate-900/60">
					<div className="space-y-2">
						<Badge color={coordinateState.color}>
							{coordinateState.label}
						</Badge>
						<Text className="text-sm">
							Ingresa latitud y longitud válidas para visualizar el
							pin.
						</Text>
					</div>
				</div>
			)}
			<div className="flex flex-wrap items-center gap-2">
				<Text className="text-xs">
					Mover el pin actualiza latitud/longitud del formulario. Los
					cambios no se guardan hasta confirmar.
				</Text>
				{coordinatesChanged && (
					<Badge color="amber">Cambios sin guardar</Badge>
				)}
			</div>
		</div>
	);
}

function DrawerMapLoadingState() {
	return (
		<div className="flex min-h-[260px] items-center justify-center rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
			Cargando mapa...
		</div>
	);
}

function FormActions({ processing, onCancel, children }) {
	return (
		<div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
			{onCancel && (
				<Button type="button" outline onClick={onCancel}>
					Cancelar
				</Button>
			)}
			<Button type="submit" color="famedic-lime" disabled={processing}>
				{children}
			</Button>
		</div>
	);
}

function HoursReadOnly({ hours }) {
	if (!hours.length) return <Text>Sin horarios normalizados.</Text>;

	return (
		<div className="grid gap-2 sm:grid-cols-2">
			{hours.map((hour) => (
				<div
					key={hour.day_of_week}
					className="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-800"
				>
					<div className="flex items-center justify-between gap-2">
						<Strong>{hour.day_label}</Strong>
						{hour.source && (
							<Badge color="zinc">
								{sourceLabel(hour.source)}
							</Badge>
						)}
					</div>
					<p className="mt-1 text-slate-600 dark:text-slate-300">
						{hour.is_closed
							? "Cerrado"
							: [hour.opens_at, hour.closes_at]
									.filter(Boolean)
									.join(" - ") ||
								hour.raw_text ||
								"Horario pendiente"}
					</p>
				</div>
			))}
		</div>
	);
}

function ServicesReadOnly({ services }) {
	if (!services.length)
		return <Text>Sin servicios especiales registrados.</Text>;

	return (
		<div className="space-y-2">
			{services.map((service) => (
				<div
					key={service.id}
					className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-800"
				>
					<div>
						<Strong>
							{service.name || serviceLabel(service.service_type)}
						</Strong>
						<Text className="text-xs">
							{serviceLabel(service.service_type)}
						</Text>
					</div>
					<div className="flex gap-1.5">
						<Badge color={service.is_active ? "green" : "zinc"}>
							{service.is_active ? "Activo" : "Inactivo"}
						</Badge>
					</div>
				</div>
			))}
		</div>
	);
}

function DataQualityPanel({ store }) {
	const coordinateState = coordinateQuality(store);
	const quality = displayDataQuality(store);

	return (
		<div className="space-y-3">
			<div className="flex flex-wrap gap-1.5">
				<Badge color={quality.color}>{quality.label}</Badge>
				{quality.value !== coordinateState.value &&
					coordinateState.value === "ok" && (
						<Badge color={coordinateState.color}>
							{coordinateState.label}
						</Badge>
					)}
			</div>
			{store.source_missing_at && (
				<Notice tone="amber">
					Histórica / No presente en fuente GDA.
				</Notice>
			)}
			{store.field_conflicts.length ? (
				<div className="space-y-2">
					<Notice tone="red">
						Conflicto detectado en importación GDA. La acción fue no
						sobrescribir el valor conservado.
					</Notice>
					{store.field_conflicts.map((conflict) => (
						<div
							key={`${conflict.row_id}-${conflict.field}`}
							className="rounded-lg border border-red-200 p-3 text-sm dark:border-red-500/30"
						>
							<Strong>{fieldLabel(conflict.field)}</Strong>
							<DetailGrid
								items={[
									["Origen", conflict.source_value],
									[
										"Valor conservado",
										conflict.existing_value,
									],
									["Acción", conflict.action],
									["Detectado", conflict.detected_at],
								]}
							/>
						</div>
					))}
				</div>
			) : (
				<Text>Sin conflictos GDA detectados.</Text>
			)}
		</div>
	);
}

function HistoryList({ history }) {
	if (!history.length) return <Text>Sin historial disponible.</Text>;

	return (
		<div className="space-y-2">
			{history.map((event) => (
				<div
					key={event.id}
					className="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-800"
				>
					<div className="flex flex-wrap items-center justify-between gap-2">
						<Strong>{event.label}</Strong>
						<Badge
							color={
								event.source === "gda" ? "sky" : "famedic-lime"
							}
						>
							{event.source === "gda" ? "GDA" : "Manual"}
						</Badge>
					</div>
					<Text className="text-xs">
						{event.date || "Sin fecha"} ·{" "}
						{event.actor || "Sin actor"} · {event.scope}
					</Text>
				</div>
			))}
		</div>
	);
}

function CopyButton({ value, children }) {
	return (
		<Button
			outline
			type="button"
			onClick={() =>
				window.navigator?.clipboard?.writeText(String(value))
			}
		>
			<ClipboardDocumentIcon />
			{children}
		</Button>
	);
}

function TextInput({ label, value, onChange, error }) {
	return (
		<Field>
			<Label>{label}</Label>
			<Input
				value={value}
				onChange={(event) => onChange(event.target.value)}
			/>
			{error && <ErrorMessage>{error}</ErrorMessage>}
		</Field>
	);
}

function StatusActions({ store }) {
	if (store.can_restore) {
		return (
			<Button
				outline
				onClick={() => {
					if (window.confirm("¿Reactivar esta sucursal?")) {
						router.post(
							store.restore_url,
							{},
							{ preserveScroll: true },
						);
					}
				}}
			>
				<ArrowPathIcon />
				Reactivar sucursal
			</Button>
		);
	}

	return (
		<div className="space-y-3">
			{store.laboratory_appointments_count > 0 && (
				<Notice tone="amber">
					Esta sucursal tiene {store.laboratory_appointments_count}{" "}
					citas relacionadas. La desactivación no modifica citas
					existentes.
				</Notice>
			)}
			<Button
				outline
				onClick={() => {
					if (window.confirm("¿Desactivar esta sucursal?")) {
						router.delete(store.delete_url, {
							preserveScroll: true,
						});
					}
				}}
			>
				<ArchiveBoxIcon />
				Desactivar sucursal
			</Button>
		</div>
	);
}

function DetailSection({ title, children }) {
	return (
		<section className="space-y-3">
			<h3 className="text-sm font-semibold text-slate-950 dark:text-white">
				{title}
			</h3>
			{children}
		</section>
	);
}

function DetailGrid({ items }) {
	return (
		<div className="grid gap-3 sm:grid-cols-2">
			{items.map(([label, value]) => (
				<div key={label} className="min-w-0">
					<p className="text-xs uppercase tracking-wide text-slate-400">
						{label}
					</p>
					<p className="mt-1 break-words text-sm text-slate-800 dark:text-slate-200">
						{value || "—"}
					</p>
				</div>
			))}
		</div>
	);
}

function ChipList({ items, empty }) {
	if (!items.length) return <Text>{empty}</Text>;

	return (
		<div className="flex flex-wrap gap-2">
			{items.slice(0, 24).map((item) => (
				<Badge key={item} color="sky">
					<CheckCircleIcon className="size-4" />
					{item}
				</Badge>
			))}
			{items.length > 24 && (
				<Badge color="zinc">+{items.length - 24}</Badge>
			)}
		</div>
	);
}

function Notice({ tone, children }) {
	const classes =
		tone === "red"
			? "border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"
			: "border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100";

	return (
		<div className={`rounded-lg border px-3 py-2 text-sm ${classes}`}>
			{children}
		</div>
	);
}

function serviceLabel(service) {
	return (
		{
			clinical_history: "Historia Clínica",
			historia_clinica: "Historia Clínica",
			optical: "Óptica",
			optica: "Óptica",
		}[service] || service
	);
}

function sourceLabel(source) {
	return (
		{
			gda: "GDA",
			manual: "Manual",
		}[source] ||
		source ||
		"Sin fuente"
	);
}

function fieldLabel(field) {
	return (
		{
			address: "Dirección",
			neighborhood: "Colonia",
			municipality: "Municipio",
			phone: "Teléfono",
			postal_code: "CP",
			latitude: "Latitud",
			longitude: "Longitud",
		}[field] || field
	);
}
