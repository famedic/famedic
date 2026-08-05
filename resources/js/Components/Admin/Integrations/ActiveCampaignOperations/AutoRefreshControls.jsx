import { useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import clsx from "clsx";

const OPTIONS = [
	{ key: "off", label: "Manual", ms: 0 },
	{ key: "30s", label: "30 s", ms: 30_000 },
	{ key: "1m", label: "1 min", ms: 60_000 },
	{ key: "5m", label: "5 min", ms: 300_000 },
];

export default function AutoRefreshControls({ urls, filters = {} }) {
	const [intervalKey, setIntervalKey] = useState("off");
	const timerRef = useRef(null);

	useEffect(() => {
		if (timerRef.current) {
			clearInterval(timerRef.current);
			timerRef.current = null;
		}

		const option = OPTIONS.find((item) => item.key === intervalKey);
		if (!option?.ms || !urls?.self) {
			return undefined;
		}

		timerRef.current = setInterval(() => {
			router.get(
				urls.self,
				{
					preset: filters.preset || "7d",
					start_date: filters.start_date,
					end_date: filters.end_date,
					laboratory: filters.laboratory || undefined,
					branch: filters.branch || undefined,
					purchase_type: filters.purchase_type || undefined,
					membership: filters.membership || undefined,
					owner: filters.owner || undefined,
					q: filters.q || undefined,
					refresh: 1,
				},
				{
					preserveScroll: true,
					preserveState: true,
					replace: true,
					only: [
						"platform",
						"health",
						"sync",
						"mirror",
						"intelligence",
						"activity",
						"diagnostics",
						"meta",
					],
				},
			);
		}, option.ms);

		return () => {
			if (timerRef.current) {
				clearInterval(timerRef.current);
			}
		};
	}, [intervalKey, filters, urls?.self]);

	return (
		<div className="flex flex-wrap items-center gap-2">
			<span className="text-[11px] font-medium uppercase tracking-wide text-zinc-500">
				Auto refresh
			</span>
			{OPTIONS.map((option) => (
				<button
					key={option.key}
					type="button"
					onClick={() => setIntervalKey(option.key)}
					className={clsx(
						"rounded-lg border px-2.5 py-1 text-[11px] font-medium transition",
						intervalKey === option.key
							? "border-emerald-600 bg-emerald-600 text-white"
							: "border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300",
					)}
				>
					{option.label}
				</button>
			))}
		</div>
	);
}
