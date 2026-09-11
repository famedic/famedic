import { Button } from "@/Components/Catalyst/button";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import { useEffect, useMemo, useRef } from "react";
import {
	CircleMarker,
	MapContainer,
	Marker,
	Popup,
	TileLayer,
	useMap,
} from "react-leaflet";
import {
	formatTodayHours,
	getDirectionsUrl,
	hasCoordinates,
} from "./laboratoryStoreDirectory";

const storeMarkerIcon = L.divIcon({
	className: "laboratory-store-map-marker",
	html: '<span style="display:flex;height:30px;width:30px;align-items:center;justify-content:center;border-radius:9999px;border:3px solid #ffffff;background:#00594c;box-shadow:0 10px 22px rgba(15,23,42,.28);"><span style="height:12px;width:12px;border-radius:9999px;background:#c2f340;box-shadow:inset 0 0 0 2px rgba(0,89,76,.18);"></span></span>',
	iconSize: [30, 30],
	iconAnchor: [15, 15],
	popupAnchor: [0, -15],
});

export default function LaboratoryStoreMap({
	stores = [],
	selectedStoreId = null,
	onSelectStore,
	onViewList,
	userLocation = null,
}) {
	const markerRefs = useRef({});
	const mappedStores = useMemo(
		() =>
			stores.filter(hasCoordinates).map((store) => ({
				...store,
				position: [Number(store.latitude), Number(store.longitude)],
			})),
		[stores],
	);
	const selectedStore = mappedStores.find(
		(store) => store.id === selectedStoreId,
	);
	const center = selectedStore?.position ||
		mappedStores[0]?.position || [19.4326, -99.1332];

	useEffect(() => {
		if (!selectedStoreId || !markerRefs.current[selectedStoreId]) {
			return;
		}

		markerRefs.current[selectedStoreId].openPopup();
	}, [selectedStoreId, mappedStores]);

	if (mappedStores.length === 0) {
		return (
			<div className="flex min-h-[420px] items-center justify-center rounded-lg border border-dashed border-zinc-300 bg-white px-4 text-center shadow-sm sm:min-h-[min(680px,calc(100vh-16rem))] dark:border-slate-700 dark:bg-slate-900">
				<div>
					<h3 className="font-poppins text-lg font-semibold text-zinc-950 dark:text-white">
						No contamos con ubicacion en mapa para estas sucursales.
					</h3>
					<p className="mt-2 text-sm text-zinc-600 dark:text-slate-300">
						La lista sigue disponible con direccion, telefono y
						servicios.
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className="overflow-hidden rounded-lg border border-zinc-950/10 bg-white shadow-sm dark:border-white/10 dark:bg-slate-900">
			<MapContainer
				center={center}
				zoom={selectedStore ? 15 : 11}
				scrollWheelZoom
				className="min-h-[420px] w-full sm:min-h-[min(680px,calc(100vh-16rem))]"
			>
				<TileLayer
					attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
					url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
				/>
				<MapBounds
					stores={mappedStores}
					selectedStore={selectedStore}
				/>
				{userLocation && (
					<CircleMarker
						center={[userLocation.latitude, userLocation.longitude]}
						pathOptions={{
							color: "#0f766e",
							fillColor: "#99f6e4",
							fillOpacity: 0.8,
						}}
						radius={10}
					>
						<Popup>Tu ubicacion</Popup>
					</CircleMarker>
				)}
				{mappedStores.map((store) => (
					<Marker
						key={store.id}
						icon={storeMarkerIcon}
						position={store.position}
						ref={(marker) => {
							if (marker) {
								markerRefs.current[store.id] = marker;
							}
						}}
						eventHandlers={{
							click: () => onSelectStore?.(store.id),
						}}
					>
						<Popup>
							<div className="w-[min(14rem,70vw)] space-y-2 text-sm">
								<div>
									<p className="font-semibold leading-snug text-zinc-950">
										{formatTitle(store.name)}
									</p>
									<p className="mt-0.5 text-xs text-zinc-600">
										{[store.municipality, store.state]
											.filter(Boolean)
											.map(formatTitle)
											.join(" - ")}
									</p>
								</div>
								<p className="text-xs text-zinc-700">
									{formatTodayHours(store.today)}
								</p>
								<p className="text-xs text-zinc-600">
									{shortAddress(store)}
								</p>
								<div className="flex flex-wrap gap-2 pt-1">
									<Button
										type="button"
										outline
										onClick={() => {
											onSelectStore?.(store.id);
											onViewList?.();
										}}
									>
										Ver sucursal
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
							</div>
						</Popup>
					</Marker>
				))}
			</MapContainer>
		</div>
	);
}

function MapBounds({ stores, selectedStore }) {
	const map = useMap();

	useEffect(() => {
		if (selectedStore) {
			map.setView(selectedStore.position, Math.max(map.getZoom(), 15), {
				animate: true,
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
				padding: [32, 32],
				maxZoom: 14,
			},
		);
	}, [map, selectedStore, stores]);

	return null;
}

function shortAddress(store) {
	const primary = [store.street, store.exterior_number]
		.filter(Boolean)
		.join(" ");
	const line = [primary, store.neighborhood].filter(Boolean).join(", ");

	return line || store.address || "Direccion no disponible";
}

function formatTitle(value) {
	if (!value) {
		return "";
	}

	return String(value)
		.toLocaleLowerCase("es-MX")
		.replace(/(^|\s)\S/g, (letter) => letter.toLocaleUpperCase("es-MX"));
}
