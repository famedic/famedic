function parseScheduleLine(line) {
	const colonIndex = line.indexOf(":");
	if (colonIndex === -1) {
		return { label: line, hours: "" };
	}

	return {
		label: line.slice(0, colonIndex).trim(),
		hours: line
			.slice(colonIndex + 1)
			.trim()
			.replace(/\s+a\s+/i, "–"),
	};
}

export function compactGeneralHoursLines(lines) {
	if (!lines?.length) {
		return [];
	}

	const parsed = lines.map(parseScheduleLine);
	const weekday = parsed.find((entry) => entry.label === "Lunes a viernes");
	const saturday = parsed.find((entry) => entry.label === "Sábado");
	const sunday = parsed.find((entry) => entry.label === "Domingo");
	const result = [];

	if (weekday) {
		result.push(weekday);
	}

	if (
		saturday?.hours === "Cerrado" &&
		sunday?.hours === "Cerrado"
	) {
		result.push({ label: "Sábado y domingo", hours: "Cerrado" });
	} else {
		if (saturday) {
			result.push(saturday);
		}
		if (sunday) {
			result.push(sunday);
		}
	}

	return result;
}

export default function CompactHoursList({ lines, compactGeneral = false }) {
	const entries = compactGeneral
		? compactGeneralHoursLines(lines)
		: lines?.map(parseScheduleLine) ?? [];

	if (!entries.length) {
		return null;
	}

	return (
		<dl className="space-y-2">
			{entries.map(({ label, hours }) => (
				<div
					key={label}
					className="grid grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] items-baseline gap-x-3 text-sm"
				>
					<dt className="text-zinc-600 dark:text-zinc-400">{label}</dt>
					<dd className="text-right font-medium tabular-nums text-zinc-900 dark:text-zinc-100">
						{hours}
					</dd>
				</div>
			))}
		</dl>
	);
}
