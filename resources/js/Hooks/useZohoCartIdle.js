import { useEffect, useRef } from "react";
import { getZohoCurrentPage, trackZohoBusinessEvent } from "@/lib/zohoSalesIqEvents";

const DEFAULT_IDLE_SECONDS = 120;
const ACTIVITY_EVENTS = [
	"mousedown",
	"mousemove",
	"keydown",
	"scroll",
	"touchstart",
	"click",
];

/**
 * Dispara `cart_idle` una sola vez por montaje tras inactividad.
 * Reinicia el timer ante interacción del usuario.
 */
export default function useZohoCartIdle({
	source,
	brand,
	itemCount = 0,
	cartTotalCents = 0,
	idleSeconds = DEFAULT_IDLE_SECONDS,
	enabled = true,
}) {
	const firedRef = useRef(false);
	const timerRef = useRef(null);

	useEffect(() => {
		if (!enabled || typeof window === "undefined") {
			return undefined;
		}

		const clearIdleTimer = () => {
			if (timerRef.current) {
				clearTimeout(timerRef.current);
				timerRef.current = null;
			}
		};

		const scheduleIdleEvent = () => {
			clearIdleTimer();

			if (firedRef.current) {
				return;
			}

			timerRef.current = setTimeout(() => {
				if (firedRef.current) {
					return;
				}

				firedRef.current = true;

				trackZohoBusinessEvent("cart_idle", {
					source,
					brand,
					idle_seconds: idleSeconds,
					item_count: itemCount,
					cart_total_cents: cartTotalCents,
					page: getZohoCurrentPage(),
				});
			}, idleSeconds * 1000);
		};

		const handleActivity = () => {
			if (firedRef.current) {
				return;
			}

			scheduleIdleEvent();
		};

		scheduleIdleEvent();

		for (const eventName of ACTIVITY_EVENTS) {
			window.addEventListener(eventName, handleActivity, { passive: true });
		}

		return () => {
			clearIdleTimer();

			for (const eventName of ACTIVITY_EVENTS) {
				window.removeEventListener(eventName, handleActivity);
			}
		};
	}, [source, brand, itemCount, cartTotalCents, idleSeconds, enabled]);
}
