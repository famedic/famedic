import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";

const STATUS_COLOR = {
	active: "lime",
	planned: "zinc",
	inactive: "amber",
};

function formatTime(iso) {
	if (!iso) return "—";
	try {
		return new Intl.DateTimeFormat("es-MX", {
			dateStyle: "short",
			timeStyle: "short",
		}).format(new Date(iso));
	} catch {
		return iso;
	}
}

export default function AutomationDriversTable({ drivers = [], onSelectDriver }) {
	if (!drivers.length) {
		return (
			<div className="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
				<Text className="text-sm text-zinc-500">Sin drivers en el catálogo.</Text>
			</div>
		);
	}

	return (
		<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
			<div className="overflow-x-auto">
				<table className="min-w-full text-left text-sm">
					<thead className="border-b border-zinc-200 bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
						<tr>
							<th className="px-4 py-3 font-medium">Driver</th>
							<th className="px-4 py-3 font-medium">Estado</th>
							<th className="px-4 py-3 font-medium">Versión</th>
							<th className="px-4 py-3 font-medium">Última ejecución</th>
							<th className="px-4 py-3 font-medium">Promedio ms</th>
							<th className="px-4 py-3 font-medium">Errores</th>
							<th className="px-4 py-3 font-medium">Retryables</th>
						</tr>
					</thead>
					<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
						{drivers.map((driver) => (
							<tr
								key={driver.key}
								className="cursor-pointer transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
								onClick={() => onSelectDriver?.(driver)}
							>
								<td className="px-4 py-3">
									<p className="font-medium text-zinc-900 dark:text-zinc-50">
										{driver.name}
									</p>
									<p className="text-xs text-zinc-400">{driver.layer}</p>
								</td>
								<td className="px-4 py-3">
									<Badge color={STATUS_COLOR[driver.status] || "zinc"}>
										{driver.status}
									</Badge>
								</td>
								<td className="px-4 py-3 tabular-nums text-zinc-600">
									{driver.version || "—"}
								</td>
								<td className="px-4 py-3 text-zinc-600">
									{formatTime(driver.last_execution_at)}
								</td>
								<td className="px-4 py-3 tabular-nums text-zinc-600">
									{driver.avg_duration_ms != null
										? driver.avg_duration_ms
										: "—"}
								</td>
								<td className="px-4 py-3 tabular-nums text-zinc-600">
									{driver.errors}
								</td>
								<td className="px-4 py-3 tabular-nums text-zinc-600">
									{driver.retryables}
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>
		</div>
	);
}
