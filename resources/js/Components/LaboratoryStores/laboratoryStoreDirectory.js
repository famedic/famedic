const WEEKDAY_LABELS = {
	1: "Lunes",
	2: "Martes",
	3: "Miércoles",
	4: "Jueves",
	5: "Viernes",
	6: "Sábado",
	7: "Domingo",
};

export function getDirectionsUrl(store) {
	if (isValidHttpUrl(store.google_maps_url)) {
		return store.google_maps_url;
	}

	if (hasCoordinates(store)) {
		return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
			`${store.latitude},${store.longitude}`,
		)}`;
	}

	const query =
		store.address ||
		[store.name, store.municipality, store.state]
			.filter(Boolean)
			.join(", ");

	return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
		query,
	)}`;
}

export function hasCoordinates(store) {
	return (
		Number.isFinite(Number(store.latitude)) &&
		Number.isFinite(Number(store.longitude))
	);
}

export function formatTime12Hour(value) {
	if (!value) {
		return null;
	}

	const [hoursValue, minutesValue = "00"] = String(value).split(":");
	const hours24 = Number(hoursValue);
	const minutes = Number(minutesValue);

	if (!Number.isInteger(hours24) || !Number.isInteger(minutes)) {
		return value;
	}

	const period = hours24 >= 12 ? "PM" : "AM";
	const hours12 = hours24 % 12 || 12;

	return `${hours12}:${String(minutes).padStart(2, "0")} ${period}`;
}

export function formatHourRange(hours) {
	if (!hours || hours.is_closed === null) {
		return "Horario no disponible";
	}

	if (hours.is_closed) {
		return "Cerrado";
	}

	const opensAt = formatTime12Hour(hours.opens_at);
	const closesAt = formatTime12Hour(hours.closes_at);

	return opensAt && closesAt
		? `${opensAt} - ${closesAt}`
		: "Horario no disponible";
}

export function formatTodayStatus(today) {
	if (!today || today.is_closed === null) {
		return "Horario no disponible";
	}

	if (today.status === "opens_later" && today.opens_at) {
		return `Abre a las ${formatTime12Hour(today.opens_at)}`;
	}

	if (today.is_closed) {
		return "Cerrado hoy";
	}

	if (today.status === "open") {
		const closeLabel = formatMinutesUntilClose(today.minutes_until_close);

		return closeLabel ? `Abierto - ${closeLabel}` : "Abierto";
	}

	return "Cerrado hoy";
}

export function formatTodayHours(today) {
	if (!today || today.is_closed === null) {
		return "Horario no disponible";
	}

	if (today.is_closed) {
		return "Cerrado hoy";
	}

	return formatHourRange(today);
}

export function formatMinutesUntilClose(minutes) {
	if (!Number.isFinite(Number(minutes)) || Number(minutes) <= 0) {
		return null;
	}

	const totalMinutes = Math.ceil(Number(minutes));

	if (totalMinutes >= 120) {
		return `Cierra en ${Math.floor(totalMinutes / 60)} h`;
	}

	if (totalMinutes >= 60) {
		return "Cierra en 1 h";
	}

	return `Cierra en ${totalMinutes} min`;
}

export function weekdayLabel(dayOfWeek) {
	return WEEKDAY_LABELS[dayOfWeek] || "";
}

function isValidHttpUrl(value) {
	if (!value) {
		return false;
	}

	try {
		const url = new URL(value);

		return ["http:", "https:"].includes(url.protocol);
	} catch {
		return false;
	}
}
