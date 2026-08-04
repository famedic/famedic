import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	ArrowPathIcon,
	ChevronRightIcon,
	ExclamationTriangleIcon,
	QueueListIcon,
} from "@heroicons/react/16/solid";

export default function DashboardHeader({
	filters,
	meta,
	onRefresh,
	refreshing = false,
	alertsUrl,
	logsUrl,
}) {
	const activeSource = (meta?.sources || []).find((s) => s.status === "active");

	return (
		<div className="relative space-y-6">
			<nav
				aria-label="Breadcrumb"
				className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
			>
				<span className="font-medium text-zinc-400">Marketing Intelligence</span>
				<ChevronRightIcon className="size-3 text-zinc-300 dark:text-zinc-600" />
				<span className="font-semibold text-zinc-700 dark:text-zinc-200">
					Dashboard
				</span>
			</nav>

			<div className="flex flex-wrap items-start justify-between gap-5">
				<div className="max-w-2xl space-y-3">
					<div className="flex flex-wrap items-center gap-2">
						<Heading className="!tracking-tight">Dashboard Ejecutivo</Heading>
						<Badge color="famedic">Core</Badge>
						{activeSource ? (
							<Badge color="sky">{activeSource.label}</Badge>
						) : null}
					</div>
					<Text className="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
						Pulso de automatización marketing: salud de sync, proxies de negocio
						y actividad operativa.
					</Text>
					<div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-400">
						<span className="inline-flex items-center gap-1.5">
							<span className="size-1.5 rounded-full bg-emerald-400 shadow-[0_0_0_3px_rgba(52,211,153,0.2)]" />
							Datos reales
						</span>
						<span className="text-zinc-300 dark:text-zinc-600">·</span>
						<span>
							Periodo {filters?.start_date} → {filters?.end_date}
						</span>
						{meta?.generated_at ? (
							<>
								<span className="text-zinc-300 dark:text-zinc-600">·</span>
								<span>Actualizado {meta.generated_at}</span>
							</>
						) : null}
					</div>
				</div>

				<div className="flex flex-wrap gap-2">
					<Button outline onClick={onRefresh} disabled={refreshing}>
						<ArrowPathIcon className="size-4" />
						Actualizar
					</Button>
					{alertsUrl ? (
						<Button href={alertsUrl} outline>
							<ExclamationTriangleIcon className="size-4" />
							Alertas
						</Button>
					) : null}
					{logsUrl ? (
						<Button href={logsUrl} outline>
							<QueueListIcon className="size-4" />
							Logs
						</Button>
					) : null}
				</div>
			</div>
		</div>
	);
}
