/** Zona horaria del equipo concierge (CDMX). */
export const CONCIERGE_TIMEZONE = "America/Mexico_City";

/**
 * Horarios por día de la semana (0 = domingo).
 * Ajustar aquí si cambian las reglas de negocio del concierge.
 */
const SCHEDULE_BY_DAY = {
	0: { openMinutes: 8 * 60, closeMinutes: 14 * 60 },//Domingo
	1: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },//Lunes
	2: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },//Martes
	3: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },//Miercoles
	4: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },//Jueves
	5: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },//Viernes
	6: { openMinutes: 8 * 60, closeMinutes: 15 * 60 },//Sabado
};

export const CONCIERGE_SCHEDULE_LINES = [
	"Lunes a viernes: 7:00 a 20:00",
	"Sábado: 8:00 a 15:00",
	"Domingo: 8:00 a 14:00",
];

const WEEKDAY_SHORT_TO_INDEX = {
	Sun: 0,
	Mon: 1,
	Tue: 2,
	Wed: 3,
	Thu: 4,
	Fri: 5,
	Sat: 6,
};

const WEEKDAY_LABELS = [
	"Domingo",
	"Lunes",
	"Martes",
	"Miércoles",
	"Jueves",
	"Viernes",
	"Sábado",
];

function getMexicoCityParts(date) {
	const parts = new Intl.DateTimeFormat("en-US", {
		timeZone: CONCIERGE_TIMEZONE,
		weekday: "short",
		hour: "2-digit",
		minute: "2-digit",
		hour12: false,
	}).formatToParts(date);

	const value = (type) => parts.find((part) => part.type === type)?.value ?? "0";

	return {
		dayOfWeek: WEEKDAY_SHORT_TO_INDEX[value("weekday")] ?? 0,
		hour: Number(value("hour")),
		minute: Number(value("minute")),
	};
}

function toMinutes(hour, minute) {
	return hour * 60 + minute;
}

function formatMinutes(totalMinutes) {
	const hours = Math.floor(totalMinutes / 60);
	const minutes = totalMinutes % 60;
	return `${hours}:${String(minutes).padStart(2, "0")}`;
}

function isWithinSchedule(dayOfWeek, hour, minute) {
	const schedule = SCHEDULE_BY_DAY[dayOfWeek];
	if (!schedule) {
		return false;
	}

	const now = toMinutes(hour, minute);
	return now >= schedule.openMinutes && now < schedule.closeMinutes;
}

function getNextAvailableText(fromDate) {
	const current = getMexicoCityParts(fromDate);
	const now = toMinutes(current.hour, current.minute);
	const todaySchedule = SCHEDULE_BY_DAY[current.dayOfWeek];

	if (todaySchedule && now < todaySchedule.openMinutes) {
		return `Hoy a las ${formatMinutes(todaySchedule.openMinutes)}`;
	}

	for (let daysAhead = 1; daysAhead <= 7; daysAhead += 1) {
		const probe = new Date(fromDate.getTime() + daysAhead * 86_400_000);
		const parts = getMexicoCityParts(probe);
		const schedule = SCHEDULE_BY_DAY[parts.dayOfWeek];

		if (schedule) {
			const label =
				daysAhead === 1 ? "Mañana" : WEEKDAY_LABELS[parts.dayOfWeek];
			return `${label} a las ${formatMinutes(schedule.openMinutes)}`;
		}
	}

	return null;
}

/**
 * @param {Date} [date]
 * @returns {{
 *   isAvailable: boolean,
 *   label: string,
 *   message: string,
 *   nextAvailableText: string | null,
 *   scheduleText: string[],
 * }}
 */
export default function getConciergeAvailability(date = new Date()) {
	const { dayOfWeek, hour, minute } = getMexicoCityParts(date);
	const isAvailable = isWithinSchedule(dayOfWeek, hour, minute);

	if (isAvailable) {
		return {
			isAvailable: true,
			label: "Concierge en línea",
			message:
				"Nuestro equipo está disponible ahora para ayudarte a agendar tu cita.",
			nextAvailableText: null,
			scheduleText: CONCIERGE_SCHEDULE_LINES,
		};
	}

	return {
		isAvailable: false,
		label: "Concierge fuera de horario",
		message:
			"Nuestro equipo podrá ayudarte en el siguiente horario disponible.",
		nextAvailableText: getNextAvailableText(date),
		scheduleText: CONCIERGE_SCHEDULE_LINES,
	};
}
