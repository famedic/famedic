const WEEKDAY_SHORT_TO_INDEX = {
	Sun: 0,
	Mon: 1,
	Tue: 2,
	Wed: 3,
	Thu: 4,
	Fri: 5,
	Sat: 6,
};

/**
 * @param {Date} date
 * @param {string} timeZone
 */
export function getTimezoneParts(date, timeZone) {
	const parts = new Intl.DateTimeFormat("en-US", {
		timeZone,
		weekday: "short",
		hour: "2-digit",
		minute: "2-digit",
		hour12: false,
	}).formatToParts(date);

	const value = (type) =>
		parts.find((part) => part.type === type)?.value ?? "0";

	return {
		dayOfWeek: WEEKDAY_SHORT_TO_INDEX[value("weekday")] ?? 0,
		hour: Number(value("hour")),
		minute: Number(value("minute")),
	};
}

function toMinutes(hour, minute) {
	return hour * 60 + minute;
}

/**
 * @param {number} totalMinutes
 */
export function formatMinutesAmPm(totalMinutes) {
	const hours24 = Math.floor(totalMinutes / 60);
	const minutes = totalMinutes % 60;
	const period = hours24 >= 12 ? "PM" : "AM";
	let hours12 = hours24 % 12;
	if (hours12 === 0) {
		hours12 = 12;
	}
	return `${hours12}:${String(minutes).padStart(2, "0")} ${period}`;
}

/**
 * @param {Record<number, { openMinutes: number, closeMinutes: number }|null|undefined>} scheduleByDay
 * @param {Array<{ label: string, days: number[] }>} groups
 */
export function buildDisplayLines(scheduleByDay, groups) {
	return groups.map((group) => {
		const schedule = scheduleByDay[group.days[0]];
		if (!schedule) {
			return `${group.label}: Cerrado`;
		}
		return `${group.label}: ${formatMinutesAmPm(schedule.openMinutes)} a ${formatMinutesAmPm(schedule.closeMinutes)}`;
	});
}

export const SUPPORT_GENERAL_SCHEDULE_BY_DAY = {
	0: null,
	1: { openMinutes: 8 * 60 + 30, closeMinutes: 18 * 60 },
	2: { openMinutes: 8 * 60 + 30, closeMinutes: 18 * 60 },
	3: { openMinutes: 8 * 60 + 30, closeMinutes: 18 * 60 },
	4: { openMinutes: 8 * 60 + 30, closeMinutes: 18 * 60 },
	5: { openMinutes: 8 * 60 + 30, closeMinutes: 18 * 60 },
	6: null,
};

export const SUPPORT_GENERAL_DISPLAY_GROUPS = [
	{ label: "Lunes a viernes", days: [1, 2, 3, 4, 5] },
	{ label: "Sábado", days: [6] },
	{ label: "Domingo", days: [0] },
];

export const CONCIERGE_DISPLAY_GROUPS = [
	{ label: "Lunes a viernes", days: [1] },
	{ label: "Sábado", days: [6] },
	{ label: "Domingo", days: [0] },
];

/**
 * @param {number} dayOfWeek
 * @param {number} hour
 * @param {number} minute
 * @param {Record<number, { openMinutes: number, closeMinutes: number }|null|undefined>} scheduleByDay
 */
export function isWithinSchedule(dayOfWeek, hour, minute, scheduleByDay) {
	const schedule = scheduleByDay?.[dayOfWeek];
	if (!schedule) {
		return false;
	}

	const now = toMinutes(hour, minute);
	return now >= schedule.openMinutes && now < schedule.closeMinutes;
}

/**
 * @param {Date} date
 * @param {{ timezone: string, scheduleByDay: Record<number, { openMinutes: number, closeMinutes: number }|null|undefined> }} config
 */
export function evaluateServiceHours(date, config) {
	const timeZone = config?.timezone ?? "America/Monterrey";
	const scheduleByDay = config?.scheduleByDay ?? {};
	const { dayOfWeek, hour, minute } = getTimezoneParts(date, timeZone);

	return {
		isAvailable: isWithinSchedule(dayOfWeek, hour, minute, scheduleByDay),
		timezone: timeZone,
	};
}

/**
 * @param {Record<number, { openMinutes: number, closeMinutes: number }|null|undefined>} scheduleByDay
 */
export function normalizeScheduleByDay(scheduleByDay) {
	if (!scheduleByDay || typeof scheduleByDay !== "object") {
		return {};
	}

	const normalized = {};
	Object.keys(scheduleByDay).forEach((key) => {
		const day = scheduleByDay[key];
		if (day?.openMinutes != null && day?.closeMinutes != null) {
			normalized[Number(key)] = {
				openMinutes: Number(day.openMinutes),
				closeMinutes: Number(day.closeMinutes),
			};
		} else {
			normalized[Number(key)] = null;
		}
	});

	return normalized;
}
