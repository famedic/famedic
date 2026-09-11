import { useMemo, useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import clsx from "clsx";
import {
	ChevronDownIcon,
	ChevronUpIcon,
	ClockIcon,
	MapPinIcon,
	PhoneIcon,
} from "@heroicons/react/24/outline";
import {
	formatHourRange,
	formatTodayHours,
	formatTodayStatus,
	getDirectionsUrl,
	weekdayLabel,
} from "./laboratoryStoreDirectory";

const PRIORITY_CAPABILITIES = [
	"resonancia_magnetica",
	"tomografia",
	"mastografia",
	"rayos_x",
	"ultrasonido_convencional",
	"densitometria",
	"electrocardio",
];

const CAPABILITY_LABELS = {
	resonancia_magnetica: "Resonancia",
	tomografia: "Tomografía",
	mastografia: "Mastografía",
	rayos_x: "Rayos X",
	ultrasonido_convencional: "Ultrasonido",
	densitometria: "Densitometria",
	electrocardio: "Electrocardio",
};

const SERVICE_LABELS = {
	historia_clinica: "Historia clínica",
	optica: "Óptica",
};

export default function LaboratoryStoreCard({
	store,
	selected = false,
	onViewMap = null,
}) {
	const [servicesExpanded, setServicesExpanded] = useState(false);
	const [hoursExpanded, setHoursExpanded] = useState(false);
	const capabilities = useMemo(
		() => sortCapabilities(store.capabilities || []),
		[store.capabilities],
	);
	const services = store.services || [];
	const visibleCapabilities = servicesExpanded
		? capabilities
		: capabilities.slice(0, 5);
	const hiddenCount = Math.max(capabilities.length - 5, 0);
	const hasAdditionalServices = hiddenCount > 0;
	const mapsUrl = getDirectionsUrl(store);
	const phone = formatPhone(store.phone);
	const weeklySchedule = store.weekly_schedule || [];
	const todayDayOfWeek = store.today?.day_of_week;
	const servicesPanelId = `laboratory-store-${store.id}-services`;
	const hoursPanelId = `laboratory-store-${store.id}-hours`;
	const locationLine = [store.municipality, store.state]
		.filter(Boolean)
		.map(formatTitle)
		.join(" - ");

	return (
		<article
			id={`laboratory-store-${store.id}`}
			className={clsx(
				"rounded-lg border bg-white p-4 shadow-sm transition hover:border-famedic-dark/25 hover:shadow-md dark:bg-slate-900 dark:hover:border-famedic-lime/30",
				selected
					? "border-famedic-dark ring-2 ring-famedic-dark/15 dark:border-famedic-lime dark:ring-famedic-lime/20"
					: "border-zinc-950/10 dark:border-white/10",
			)}
		>
			<div className="flex h-full flex-col gap-4">
				<div className="space-y-2">
					<div className="flex flex-wrap items-start justify-between gap-3">
						<div className="min-w-0">
							<p className="text-[11px] font-semibold uppercase tracking-normal text-famedic-dark dark:text-famedic-lime">
								{String(store.brand || "").toUpperCase()}
							</p>
							<h3 className="mt-0.5 font-poppins text-lg font-semibold text-zinc-950 dark:text-white">
								{formatTitle(store.name)}
							</h3>
							{store.distance_km !== undefined && (
								<p className="mt-1 text-sm font-semibold text-famedic-dark dark:text-famedic-lime">
									A {store.distance_km} km
								</p>
							)}
						</div>
						<Badge color={todayBadgeColor(store.today)}>
							{formatTodayStatus(store.today)}
						</Badge>
					</div>
					{locationLine && (
						<p className="text-sm font-medium text-zinc-700 dark:text-slate-200">
							{locationLine}
						</p>
					)}
				</div>

				<div className="grid gap-3 text-sm text-zinc-600 sm:grid-cols-[1fr_auto] dark:text-slate-300">
					<div className="min-w-0 space-y-1">
						<p className="line-clamp-3 sm:line-clamp-2">
							{addressLine(store)}
						</p>
						{store.postal_code && (
							<p className="font-medium text-zinc-700 dark:text-slate-200">
								CP {store.postal_code}
							</p>
						)}
					</div>
					<div className="space-y-1.5 sm:text-right">
						<p className="inline-flex items-center gap-2 text-xs font-medium text-zinc-500 dark:text-slate-400">
							<ClockIcon className="size-4 text-famedic-dark dark:text-famedic-lime" />
							{formatTodayHours(store.today)}
						</p>
						{phone && (
							<a
								href={`tel:${digitsOnly(store.phone)}`}
								className="flex items-center gap-2 font-medium text-famedic-dark hover:underline sm:justify-end dark:text-famedic-lime"
							>
								<PhoneIcon className="size-4" />
								{phone}
							</a>
						)}
					</div>
				</div>

				<div className="space-y-3">
					<div className="flex flex-wrap gap-2">
						{visibleCapabilities.map((capability) => (
							<Badge key={capability.slug} color="slate">
								{CAPABILITY_LABELS[capability.slug] ||
									capability.name}
							</Badge>
						))}
						{services.map((service) => (
							<Badge key={service.type} color="sky">
								{SERVICE_LABELS[service.type] || service.name}
							</Badge>
						))}
					</div>

					{servicesExpanded && hasAdditionalServices && (
						<div
							id={servicesPanelId}
							className="flex flex-wrap gap-2 border-t border-zinc-950/10 pt-3 dark:border-white/10"
						>
							{capabilities.slice(5).map((capability) => (
								<Badge key={capability.slug} color="slate">
									{capability.name}
								</Badge>
							))}
						</div>
					)}

					{hoursExpanded && (
						<div
							id={hoursPanelId}
							className="border-t border-zinc-950/10 pt-3 text-sm dark:border-white/10"
						>
							<dl className="grid grid-cols-[minmax(6rem,auto)_1fr] gap-x-4 gap-y-2">
								{weeklySchedule.map((hours) => (
									<div
										key={hours.day_of_week}
										className={clsx(
											"contents",
											hours.day_of_week ===
												todayDayOfWeek &&
												"font-semibold text-famedic-dark dark:text-famedic-lime",
										)}
									>
										<dt>
											{hours.label ||
												weekdayLabel(hours.day_of_week)}
										</dt>
										<dd>{formatHourRange(hours)}</dd>
									</div>
								))}
							</dl>
						</div>
					)}

					<div className="flex flex-wrap gap-x-4 gap-y-2 text-sm font-semibold">
						{hasAdditionalServices && (
							<button
								type="button"
								onClick={() =>
									setServicesExpanded((current) => !current)
								}
								aria-expanded={servicesExpanded}
								aria-controls={servicesPanelId}
								className="inline-flex items-center gap-1 text-famedic-dark underline-offset-4 hover:underline focus:outline-none focus:outline-2 focus:outline-offset-2 focus:outline-famedic-dark dark:text-famedic-lime dark:focus:outline-white"
							>
								{servicesExpanded ? (
									<ChevronUpIcon className="size-4" />
								) : (
									<ChevronDownIcon className="size-4" />
								)}
								{servicesExpanded
									? "Ocultar servicios"
									: `Ver ${hiddenCount} servicios más`}
							</button>
						)}
						{weeklySchedule.length > 0 && (
							<button
								type="button"
								onClick={() =>
									setHoursExpanded((current) => !current)
								}
								aria-expanded={hoursExpanded}
								aria-controls={hoursPanelId}
								className="inline-flex items-center gap-1 text-zinc-700 underline-offset-4 hover:underline focus:outline-none focus:outline-2 focus:outline-offset-2 focus:outline-famedic-dark dark:text-slate-200 dark:focus:outline-white"
							>
								{hoursExpanded ? (
									<ChevronUpIcon className="size-4" />
								) : (
									<ChevronDownIcon className="size-4" />
								)}
								{hoursExpanded
									? "Ocultar horarios"
									: "Ver horarios"}
							</button>
						)}
					</div>
				</div>

				<div className="mt-auto grid grid-cols-1 gap-2 border-t border-zinc-950/10 pt-3 sm:flex sm:items-center sm:justify-end dark:border-white/10">
					{onViewMap && (
						<Button type="button" outline onClick={onViewMap}>
							<MapPinIcon data-slot="icon" />
							Ver en mapa
						</Button>
					)}
					<a
						href={mapsUrl}
						target="_blank"
						rel="noopener noreferrer"
						className="relative isolate inline-flex items-center justify-center gap-x-2 rounded-lg border border-famedic-dark/90 bg-famedic-dark px-3.5 py-2.5 font-poppins text-base/6 font-semibold text-white shadow hover:bg-famedic-dark/90 focus:outline-none focus:outline-2 focus:outline-offset-2 focus:outline-famedic-dark sm:px-3 sm:py-1.5 sm:text-sm/6 dark:border-famedic-lime/80 dark:bg-famedic-lime dark:text-famedic-darker dark:focus:outline-white"
					>
						<MapPinIcon className="size-5 text-famedic-lime sm:size-4 dark:text-famedic-darker" />
						Cómo llegar
					</a>
				</div>
			</div>
		</article>
	);
}

function sortCapabilities(capabilities) {
	return [...capabilities].sort((a, b) => {
		const aIndex = PRIORITY_CAPABILITIES.indexOf(a.slug);
		const bIndex = PRIORITY_CAPABILITIES.indexOf(b.slug);

		if (aIndex !== -1 || bIndex !== -1) {
			return (
				(aIndex === -1 ? 999 : aIndex) - (bIndex === -1 ? 999 : bIndex)
			);
		}

		return String(a.name).localeCompare(String(b.name), "es-MX");
	});
}

function todayBadgeColor(today) {
	return today?.status === "open" ? "green" : "amber";
}

function addressLine(store) {
	const primary = [store.street, store.exterior_number, store.interior_number]
		.filter(Boolean)
		.join(" ");
	const secondary = [store.neighborhood].filter(Boolean).join(" ");
	const line = [primary, secondary].filter(Boolean).join(", ");

	return line || store.address || "Dirección no disponible";
}

function formatPhone(value) {
	const digits = digitsOnly(value);

	if (!digits) {
		return null;
	}

	if (digits.length === 10) {
		return `${digits.slice(0, 2)} ${digits.slice(2, 6)} ${digits.slice(6)}`;
	}

	return value;
}

function digitsOnly(value) {
	return String(value || "").replace(/\D/g, "");
}

function formatTitle(value) {
	if (!value) {
		return "";
	}

	return String(value)
		.toLocaleLowerCase("es-MX")
		.replace(/(^|\s)\S/g, (letter) => letter.toLocaleUpperCase("es-MX"));
}
