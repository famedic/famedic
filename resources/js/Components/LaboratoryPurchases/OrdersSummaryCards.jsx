import clsx from "clsx";
import { ClockIcon, CheckCircleIcon, DocumentTextIcon } from "@heroicons/react/24/outline";

const CARD_BASE =
	"group relative flex flex-col rounded-2xl border border-zinc-200/80 bg-white/60 p-4 text-left shadow-sm backdrop-blur-sm transition duration-200 dark:border-slate-700/80 dark:bg-slate-900/60 dark:shadow-none sm:p-5";

const CARD_INTERACTIVE =
	"cursor-pointer hover:border-zinc-300 hover:shadow-md dark:hover:border-slate-600 dark:hover:bg-slate-900/90";

const ICON_WRAP = "mb-3 inline-flex size-10 items-center justify-center rounded-xl bg-zinc-100 text-zinc-700 dark:bg-slate-800 dark:text-slate-200";

function SummaryCard({ title, count, description, icon: Icon, active, onClick }) {
	const interactive = typeof onClick === "function";
	const className = clsx(
		CARD_BASE,
		interactive && CARD_INTERACTIVE,
		active && "ring-2 ring-famedic-dark/40 dark:ring-famedic-light/50",
	);
	const body = (
		<>
			<div className={clsx(ICON_WRAP, active && "bg-famedic-dark/10 text-famedic-darker dark:bg-white/10 dark:text-white")}>
				<Icon className="size-5" aria-hidden />
			</div>
			<p className="text-3xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{count}</p>
			<p className="mt-1 text-sm font-medium text-zinc-800 dark:text-slate-200">{title}</p>
			<p className="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-slate-400">{description}</p>
		</>
	);
	if (interactive) {
		return (
			<button type="button" onClick={onClick} className={className}>
				{body}
			</button>
		);
	}
	return <div className={className}>{body}</div>;
}

export default function OrdersSummaryCards({ summary, activePipeline, onPipelineSelect }) {
	const processing = summary?.processing_count ?? summary?.pending_count ?? 0;
	const completed = summary?.completed_count ?? summary?.ready_count ?? 0;
	const invoiced = summary?.invoiced_count ?? 0;

	return (
		<div className="grid gap-3 sm:grid-cols-3 sm:gap-4">
			<SummaryCard
				title="En proceso"
				count={processing}
				description="Sin resultados disponibles aún"
				icon={ClockIcon}
				active={activePipeline === "processing"}
				onClick={onPipelineSelect ? () => onPipelineSelect("processing") : undefined}
			/>
			<SummaryCard
				title="Completados"
				count={completed}
				description="Resultados listos para consultar"
				icon={CheckCircleIcon}
				active={activePipeline === "completed"}
				onClick={onPipelineSelect ? () => onPipelineSelect("completed") : undefined}
			/>
			<SummaryCard
				title="Facturados"
				count={invoiced}
				description="Con factura y solicitud registrada"
				icon={DocumentTextIcon}
				active={activePipeline === "invoiced"}
				onClick={onPipelineSelect ? () => onPipelineSelect("invoiced") : undefined}
			/>
		</div>
	);
}
