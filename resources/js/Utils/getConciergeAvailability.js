/** @typedef {{ openMinutes: number, closeMinutes: number }} DaySchedule */

import {
	buildDisplayLines,
	CONCIERGE_DISPLAY_GROUPS,
	evaluateServiceHours,
	formatMinutesAmPm,
	getTimezoneParts,
	normalizeScheduleByDay,
} from "@/Utils/serviceHours";

const WEEKDAY_LABELS = [
	"Domingo",
	"Lunes",
	"Martes",
	"Miércoles",
	"Jueves",
	"Viernes",
	"Sábado",
];

/** Fallback aligned with config/famedic.php concierge defaults. */
export const DEFAULT_CONCIERGE_CONFIG = {
	timezone: "America/Mexico_City",
	scheduleByDay: {
		0: { openMinutes: 8 * 60, closeMinutes: 14 * 60 },
		1: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
		2: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
		3: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
		4: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
		5: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
		6: { openMinutes: 8 * 60, closeMinutes: 15 * 60 },
	},
	scheduleLines: buildDisplayLines(
		{
			0: { openMinutes: 8 * 60, closeMinutes: 14 * 60 },
			1: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
			2: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
			3: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
			4: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
			5: { openMinutes: 7 * 60, closeMinutes: 20 * 60 },
			6: { openMinutes: 8 * 60, closeMinutes: 15 * 60 },
		},
		CONCIERGE_DISPLAY_GROUPS,
	),
	availability: {
		online_label: "Concierge en línea",
		online_message:
			"Nuestro equipo está disponible ahora para ayudarte a agendar tu cita.",
		offline_label: "Concierge fuera de horario",
		offline_message:
			"Nuestro equipo podrá ayudarte en el siguiente horario disponible.",
	},
	checkoutOfflineMessages: [
		"Ahora no estamos disponibles por teléfono.",
		"Puedes dejar tu solicitud y te llamaremos en el siguiente horario disponible.",
	],
};

/** @deprecated Use config timezone from famedicConcierge */
export const CONCIERGE_TIMEZONE = DEFAULT_CONCIERGE_CONFIG.timezone;

/** @deprecated Use scheduleLines from famedicConcierge */
export const CONCIERGE_SCHEDULE_LINES = DEFAULT_CONCIERGE_CONFIG.scheduleLines;

/**
 * @param {import('@inertiajs/react').PageProps['famedicConcierge']} raw
 */
export function normalizeConciergeConfig(raw) {
	if (!raw || typeof raw !== "object") {
		return {
			timezone: DEFAULT_CONCIERGE_CONFIG.timezone,
			scheduleLines: DEFAULT_CONCIERGE_CONFIG.scheduleLines,
			scheduleByDay: DEFAULT_CONCIERGE_CONFIG.scheduleByDay,
			availability: DEFAULT_CONCIERGE_CONFIG.availability,
			checkoutOfflineMessages: DEFAULT_CONCIERGE_CONFIG.checkoutOfflineMessages,
			phoneDisplay: "",
			phoneTel: "",
			afterHoursMessage: "",
			description: "",
		};
	}

	const scheduleByDay = normalizeScheduleByDay(raw.scheduleByDay);
	const resolvedScheduleByDay =
		Object.keys(scheduleByDay).length > 0
			? scheduleByDay
			: DEFAULT_CONCIERGE_CONFIG.scheduleByDay;

	return {
		timezone: raw.timezone || DEFAULT_CONCIERGE_CONFIG.timezone,
		scheduleLines:
			Array.isArray(raw.scheduleLines) && raw.scheduleLines.length > 0
				? raw.scheduleLines
				: buildDisplayLines(resolvedScheduleByDay, CONCIERGE_DISPLAY_GROUPS),
		scheduleByDay: resolvedScheduleByDay,
		availability: {
			online_label:
				raw.availability?.online_label ??
				DEFAULT_CONCIERGE_CONFIG.availability.online_label,
			online_message:
				raw.availability?.online_message ??
				DEFAULT_CONCIERGE_CONFIG.availability.online_message,
			offline_label:
				raw.availability?.offline_label ??
				DEFAULT_CONCIERGE_CONFIG.availability.offline_label,
			offline_message:
				raw.availability?.offline_message ??
				DEFAULT_CONCIERGE_CONFIG.availability.offline_message,
		},
		checkoutOfflineMessages:
			Array.isArray(raw.checkoutOfflineMessages) &&
			raw.checkoutOfflineMessages.length > 0
				? raw.checkoutOfflineMessages
				: DEFAULT_CONCIERGE_CONFIG.checkoutOfflineMessages,
		phoneDisplay: raw.phoneDisplay ?? "",
		phoneTel: raw.phoneTel ?? "",
		afterHoursMessage: raw.afterHoursMessage ?? "",
		description: raw.description ?? "",
	};
}

function getNextAvailableText(fromDate, timeZone, scheduleByDay) {
	const current = getTimezoneParts(fromDate, timeZone);
	const now = current.hour * 60 + current.minute;
	const todaySchedule = scheduleByDay[current.dayOfWeek];

	if (todaySchedule && now < todaySchedule.openMinutes) {
		return `Hoy a las ${formatMinutesAmPm(todaySchedule.openMinutes)}`;
	}

	for (let daysAhead = 1; daysAhead <= 7; daysAhead += 1) {
		const probe = new Date(fromDate.getTime() + daysAhead * 86_400_000);
		const parts = getTimezoneParts(probe, timeZone);
		const schedule = scheduleByDay[parts.dayOfWeek];

		if (schedule) {
			const label =
				daysAhead === 1 ? "Mañana" : WEEKDAY_LABELS[parts.dayOfWeek];
			return `${label} a las ${formatMinutesAmPm(schedule.openMinutes)}`;
		}
	}

	return null;
}

/**
 * @param {Date} [date]
 * @param {import('@inertiajs/react').PageProps['famedicConcierge']} [conciergeConfig]
 */
export default function getConciergeAvailability(date = new Date(), conciergeConfig) {
	const config = normalizeConciergeConfig(conciergeConfig);
	const { isAvailable } = evaluateServiceHours(date, {
		timezone: config.timezone,
		scheduleByDay: config.scheduleByDay,
	});

	if (isAvailable) {
		return {
			isAvailable: true,
			label: config.availability.online_label,
			message: config.availability.online_message,
			nextAvailableText: null,
			scheduleText: config.scheduleLines,
			checkoutOfflineMessages: config.checkoutOfflineMessages,
		};
	}

	return {
		isAvailable: false,
		label: config.availability.offline_label,
		message: config.availability.offline_message,
		nextAvailableText: getNextAvailableText(
			date,
			config.timezone,
			config.scheduleByDay,
		),
		scheduleText: config.scheduleLines,
		checkoutOfflineMessages: config.checkoutOfflineMessages,
	};
}
