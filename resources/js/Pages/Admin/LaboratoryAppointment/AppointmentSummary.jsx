import { Badge } from "@/Components/Catalyst/badge";

export default function AppointmentSummary({ summary }) {
	return (
		<section className="rounded-2xl border border-zinc-200/70 bg-white/80 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
			<h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				Resumen compacto
			</h3>
			<div className="space-y-3 text-sm">
				<Row label="Estado">
					<Badge color={summary.statusColor}>{summary.statusLabel}</Badge>
				</Row>
				<Row label="Laboratorio">{summary.laboratory}</Row>
				<Row label="Total estudios">{summary.totalStudies}</Row>
				<Row label="Canal">{summary.channel}</Row>
				<Row label="Tiempo total">{summary.totalTime}</Row>
			</div>
		</section>
	);
}

function Row({ label, children }) {
	return (
		<div className="flex items-center justify-between gap-3 border-b border-zinc-200/70 pb-2 dark:border-zinc-800">
			<span className="text-zinc-500 dark:text-zinc-400">{label}</span>
			<span className="text-right text-zinc-900 dark:text-zinc-100">{children}</span>
		</div>
	);
}
