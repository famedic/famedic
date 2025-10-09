import { useEffect } from "react";
import { usePage } from "@inertiajs/react";

export default function useTrackingEvents() {
	const { trackingEvents } = usePage().props;

	useEffect(() => {
		if (!trackingEvents || !trackingEvents.length) return;
		trackingEvents.forEach((evt) => {
			const { eventID, eventName, ...params } = evt;

			fbq("track", eventName, params, {
				eventID: eventID,
			});
		});
	}, [trackingEvents]);
}
