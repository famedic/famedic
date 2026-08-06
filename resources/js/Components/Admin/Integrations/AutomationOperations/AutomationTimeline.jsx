import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";

const RESULT_COLOR = {
	success: "lime",
	failed: "rose",
	skipped: "zinc",
	partial: "amber",
	pending: "amber",
	active: "lime",
	planned: "zinc",
	inactive: "zinc",
};

function formatTime(iso) {
	if (!iso) return "—";
	try {
		return new Intl.DateTimeFormat("es-MX", {
			dateStyle: "short",
			timeStyle: "medium",
		}).format(new Date(iso));
	} catch {
		return iso;
	}
}

export default function AutomationTimeline({
	items = [],
	onSelect,
	compact = false,
}) {
	if (!items.length) {
		return (
			<div className="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
				<Text className="text-sm text-zinc-500">
					Sin eventos aún. Ejecuta un diagnóstico o espera telemetría de
					automatizaciones.
				</Text>
			</div>
		);
	}

	return (
		<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
			<div className="overflow-x-auto">
				<table className="min-w-full text-left text-sm">
					<thead className="border-b border-zinc-200 bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
						<tr>
							<th className="px-4 py-3 font-medium">Hora</th>
							<th className="px-4 py-3 font-medium">Driver</th>
							<th className="px-4 py-3 font-medium">Automation</th>
							<th className="px-4 py-3 font-medium">Resultado</th>
							<th className="px-4 py-3 font-medium">Duración</th>
							<th className="px-4 py-3 font-medium">Retryable</th>
							{!compact ? (
								<th className="px-4 py-3 font-medium">Fuente</th>
							) : null}
						</tr>
					</thead>
					<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
						{items.map((item) => (
							<tr
								key={item.id}
								className="cursor-pointer transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
								onClick={() => onSelect?.(item)}
							>
								<td className="whitespace-nowrap px-4 py-3 tabular-nums text-zinc-600 dark:text-zinc-300">
									{formatTime(item.occurred_at)}
								</td>
								<td className="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-50">
									{item.driver || "—"}
								</td>
								<td className="px-4 py-3 text-zinc-600 dark:text-zinc-300">
									{item.automation}
								</td>
								<td className="px-4 py-3">
									<Badge color={RESULT_COLOR[item.result] || "zinc"}>
										{item.result}
									</Badge>
								</td>
								<td className="px-4 py-3 tabular-nums text-zinc-600">
									{item.duration_ms != null ? `${item.duration_ms} ms` : "—"}
								</td>
								<td className="px-4 py-3">
									{item.retryable == null ? (
										"—"
									) : (
										<Badge color={item.retryable ? "amber" : "zinc"}>
											{item.retryable ? "Sí" : "No"}
										</Badge>
									)}
								</td>
								{!compact ? (
									<td className="px-4 py-3 text-xs text-zinc-400">
										{item.source}
									</td>
								) : null}
							</tr>
						))}
					</tbody>
				</table>
			</div>
		</div>
	);
}
