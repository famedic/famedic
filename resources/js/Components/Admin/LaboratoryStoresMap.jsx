import { Button } from "@/Components/Catalyst/button";
import { getDirectionsUrl } from "@/Components/LaboratoryStores/laboratoryStoreDirectory";
import {
	findSelectedMapStore,
	hasValidCoordinatePair,
} from "@/lib/adminLaboratoryStoresMap";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import { useEffect, useMemo, useRef } from "react";
import {
	MapContainer,
	Marker,
	Popup,
	TileLayer,
	useMap,
} from "react-leaflet";

const DEFAULT_CENTER = [19.4326, -99.1332];

const markerIcons = {
	active: createMarkerIcon("#00594c", "#c2f340", 30),
	inactive: createMarkerIcon("#64748b", "#e2e8f0", 30),
	alert: createMarkerIcon("#b45309", "#fde68a", 30),
	selected: createMarkerIcon("#1f1646", "#c2f340", 34),
	edit: createMarkerIcon("#00594c", "#c2f340", 36),
};

export default function LaboratoryStoresMap({
	stores = [],
	selectedStoreId = null,
	onSelectStore,
	onOpenStore,
	onEditStore,
	heightClass = "min-h-[520px]",
	compact = false,
	draggable = false,
	onCoordinatesChange,
}) {
	const markerRefs = useRef({});
	const mappedStores = useMemo(
		() =>
			stores.filter(hasValidCoordinatePair).map((store) => ({
				...store,
				position: [Number(store.latitude), Number(store.longitude)],
			})),
		[stores],
	);
	const selectedStore = findSelectedMapStore(mappedStores, selectedStoreId);
	const center =
		selectedStore?.position || mappedStores[0]?.position || DEFAULT_CENTER;

	useEffect(() => {
		if (!selectedStoreId || !markerRefs.current[selectedStoreId]) {
			return;
		}

		markerRefs.current[selectedStoreId].openPopup();
	}, [mappedStores, selectedStoreId]);

	if (mappedStores.length === 0) {
		return (
			<div
				className={`${heightClass} flex items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white px-4 text-center shadow-sm dark:border-slate-700 dark:bg-slate-900`}
			>
				<div>
					<p className="font-semibold text-slate-950 dark:text-white">
						Sin sucursales con coordenadas
					</p>
					<p className="mt-1 text-sm text-slate-500 dark:text-slate-300">
						Ajusta filtros o revisa sucursales sin ubicación.
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
			<MapContainer
				center={center}
				zoom={selectedStore ? 15 : mappedStores.length === 1 ? 14 : 11}
				scrollWheelZoom={!compact}
				className={`${heightClass} w-full`}
			>
				<TileLayer
					attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
					url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
				/>
				<MapBounds
					stores={mappedStores}
					selectedStore={selectedStore}
					compact={compact}
				/>
				{mappedStores.map((store) => (
					<Marker
						key={store.id}
						icon={iconForStore(store, selectedStoreId)}
						position={store.position}
						draggable={draggable && mappedStores.length === 1}
						ref={(marker) => {
							if (marker) {
								markerRefs.current[store.id] = marker;
							}
						}}
						eventHandlers={{
							click: () => onSelectStore?.(store.id),
							dragend: (event) => {
								const next = event.target.getLatLng();
								onCoordinatesChange?.({
									latitude: next.lat.toFixed(7),
									longitude: next.lng.toFixed(7),
								});
							},
						}}
					>
						<Popup>
							<StorePopup
								store={store}
								onEditStore={onEditStore}
								onOpenStore={onOpenStore}
								compact={compact}
							/>
						</Popup>
					</Marker>
				))}
			</MapContainer>
		</div>
	);
}

function MapBounds({ stores, selectedStore, compact }) {
	const map = useMap();

	useEffect(() => {
		if (selectedStore) {
			map.setView(selectedStore.position, Math.max(map.getZoom(), 15), {
				animate: !compact,
			});

			return;
		}

		if (stores.length === 1) {
			map.setView(stores[0].position, 14);

			return;
		}

		map.fitBounds(
			stores.map((store) => store.position),
			{
				padding: compact ? [18, 18] : [36, 36],
				maxZoom: 14,
			},
		);
	}, [compact, map, selectedStore, stores]);

	return null;
}

function StorePopup({ store, onEditStore, onOpenStore, compact }) {
	return (
		<div className="w-[min(13.5rem,72vw)] space-y-2 text-sm">
			<div>
				<p className="font-semibold leading-snug text-slate-950">
					{formatTitle(store.name)}
				</p>
				<p className="mt-0.5 text-xs text-slate-600">
					{store.brand_label || formatTitle(store.brand)}
				</p>
			</div>
			<p className="text-xs font-medium text-slate-700">
				{store.status_label}
				{store.data_quality?.label
					? ` · ${store.data_quality.label}`
					: ""}
			</p>
			<p className="text-xs text-slate-600">
				{[store.municipality, store.state]
					.filter(Boolean)
					.map(formatTitle)
					.join(" · ")}
			</p>
			{store.postal_code && (
				<p className="text-xs text-slate-600">CP {store.postal_code}</p>
			)}
			{!compact && (
				<div className="flex flex-wrap gap-2 pt-1">
					{onEditStore && (
						<Button
							type="button"
							outline
							onClick={() => onEditStore(store)}
						>
							Editar
						</Button>
					)}
					<Button
						type="button"
						outline
						onClick={() => onOpenStore?.(store)}
					>
						Detalle
					</Button>
					<a
						href={getDirectionsUrl(store)}
						target="_blank"
						rel="noopener noreferrer"
						className="inline-flex items-center rounded-md bg-famedic-dark px-3 py-2 text-sm font-semibold text-white hover:bg-famedic-dark/90 focus:outline-none focus:outline-2 focus:outline-offset-2 focus:outline-famedic-dark"
					>
						Cómo llegar
					</a>
				</div>
			)}
		</div>
	);
}

function iconForStore(store, selectedStoreId) {
	if (store.id === selectedStoreId) {
		return markerIcons.selected;
	}

	if (
		store.data_quality?.value === "warning" ||
		store.data_quality?.value === "conflict"
	) {
		return markerIcons.alert;
	}

	return store.is_active ? markerIcons.active : markerIcons.inactive;
}

function createMarkerIcon(outerColor, innerColor, size) {
	const innerSize = Math.round(size * 0.42);

	return L.divIcon({
		className: "admin-laboratory-store-map-marker",
		html: `<span style="display:flex;height:${size}px;width:${size}px;align-items:center;justify-content:center;border-radius:9999px;border:3px solid #ffffff;background:${outerColor};box-shadow:0 10px 22px rgba(15,23,42,.28);"><span style="height:${innerSize}px;width:${innerSize}px;border-radius:9999px;background:${innerColor};box-shadow:inset 0 0 0 2px rgba(0,89,76,.18);"></span></span>`,
		iconSize: [size, size],
		iconAnchor: [size / 2, size / 2],
		popupAnchor: [0, -(size / 2)],
	});
}

function formatTitle(value) {
	if (!value) {
		return "";
	}

	return String(value)
		.toLocaleLowerCase("es-MX")
		.replace(/(^|\s)\S/g, (letter) => letter.toLocaleUpperCase("es-MX"));
}
