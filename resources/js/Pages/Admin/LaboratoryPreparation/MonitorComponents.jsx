import { Badge } from "@/Components/Catalyst/badge";
import { statusLabel } from "./monitorPresentation";
const colors = {
	AUTO_CONSOLIDATED: "emerald",
	FALLBACK_ORIGINAL: "amber",
	failed: "red",
	queued: "sky",
	processing: "sky",
	generated: "emerald",
	succeeded: "emerald",
	stale: "amber",
	AI_READY: "emerald",
};
export function StatusBadge({ value }) {
	return (
		<span className="inline-flex max-w-full whitespace-normal [overflow-wrap:anywhere]">
			<Badge color={colors[value] || "zinc"}>
				<span className="whitespace-normal">{statusLabel(value)}</span>
			</Badge>
		</span>
	);
}
export function Section({ title, children }) {
	return (
		<section className="min-w-0 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
			<h2 className="text-base font-semibold text-zinc-950 dark:text-white">
				{title}
			</h2>
			<div className="mt-4 min-w-0">{children}</div>
		</section>
	);
}
export function Field({ label, children }) {
	return (
		<div className="min-w-0">
			<dt className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
				{label}
			</dt>
			<dd className="mt-1 whitespace-pre-wrap text-sm [overflow-wrap:anywhere]">
				{children ?? "—"}
			</dd>
		</div>
	);
}
export function JsonBlock({ value }) {
	return (
		<pre className="max-h-96 overflow-y-auto whitespace-pre-wrap rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100 [overflow-wrap:anywhere]">
			{JSON.stringify(value, null, 2) || "Sin datos disponibles."}
		</pre>
	);
}
