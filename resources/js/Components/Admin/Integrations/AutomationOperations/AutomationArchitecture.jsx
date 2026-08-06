import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { ChevronDownIcon } from "@heroicons/react/16/solid";

const ROLE_STYLES = {
	entry: "border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-100",
	layer: "border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-100",
	fanout: "border-indigo-300 bg-indigo-50 text-indigo-900 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-100",
	workers: "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100",
	destination: "border-famedic-navy/30 bg-famedic-navy/5 text-famedic-navy dark:border-famedic-light/30 dark:bg-famedic-light/10 dark:text-famedic-light",
	planned: "border-dashed border-zinc-300 bg-zinc-50 text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800/40 dark:text-zinc-400",
};

export default function AutomationArchitecture({ architecture, roadmap = [] }) {
	const flow = architecture?.flow_text || [];
	const planned = (architecture?.nodes || []).filter((n) => n.role === "planned");

	return (
		<div className="space-y-6">
			<div className="rounded-2xl border border-zinc-200 bg-gradient-to-b from-white to-zinc-50 p-6 dark:border-zinc-700 dark:from-zinc-900 dark:to-zinc-950">
				<p className="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">
					Flujo operativo
				</p>
				<div className="mt-6 flex flex-col items-center gap-2">
					{flow.map((label, index) => (
						<div key={label} className="flex w-full max-w-md flex-col items-center">
							<div
								className={[
									"w-full rounded-xl border px-4 py-3 text-center text-sm font-semibold",
									ROLE_STYLES[
										index === 0
											? "entry"
											: index === flow.length - 1
												? "destination"
												: index === 3
													? "fanout"
													: index === 4
														? "workers"
														: "layer"
									],
								].join(" ")}
							>
								{label}
							</div>
							{index < flow.length - 1 ? (
								<ChevronDownIcon className="my-1 size-5 text-zinc-400" />
							) : null}
						</div>
					))}
				</div>
			</div>

			<div className="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
				<p className="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">
					Extensiones previstas
				</p>
				<Text className="mt-2 text-sm text-zinc-500">
					Drivers futuros se registran en{" "}
					<code className="text-xs">config/order_automation.php</code> y
					aparecen en el catálogo de{" "}
					<code className="text-xs">config/automation_operations.php</code>.
				</Text>
				<div className="mt-4 flex flex-wrap gap-2">
					{(planned.length ? planned : roadmap).map((item) => (
						<Badge key={item.id || item.key} color="zinc">
							{item.label}
						</Badge>
					))}
				</div>
			</div>
		</div>
	);
}
