import { useEffect, useMemo, useState } from "react";
import { evaluateServiceHours, normalizeScheduleByDay } from "@/Utils/serviceHours";

/**
 * @param {{ timezone?: string, scheduleByDay?: Record<number, unknown> } | null | undefined} scheduleConfig
 */
export default function useServiceHoursAvailability(scheduleConfig) {
	const [now, setNow] = useState(() => new Date());

	useEffect(() => {
		const intervalId = setInterval(() => setNow(new Date()), 60_000);

		return () => clearInterval(intervalId);
	}, []);

	const normalizedConfig = useMemo(
		() => ({
			timezone: scheduleConfig?.timezone ?? "America/Monterrey",
			scheduleByDay: normalizeScheduleByDay(scheduleConfig?.scheduleByDay),
		}),
		[scheduleConfig],
	);

	return useMemo(
		() => evaluateServiceHours(now, normalizedConfig),
		[now, normalizedConfig],
	);
}
