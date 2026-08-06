import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";
import { EyeIcon } from "@heroicons/react/16/solid";
import clsx from "clsx";

const LEVEL = {
	error: "red",
	warning: "amber",
	info: "sky",
};

const STATUS = {
	failed: "red",
	pending: "amber",
	processing: "amber",
	skipped: "zinc",
	synced: "emerald",
	open: "orange",
	paused: "amber",
};

export default function LogsTable({
	logs = [],
	selectedId = null,
	onSelect,
	total = 0,
}) {
	if (!logs.length) {
		return (
			<section className="space-y-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Listado
				</h2>
				<EmptyListCard
					heading="Sin logs"
					message="No hay registros que coincidan con los filtros."
				/>
			</section>
		);
	}

	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Listado
				</h2>
				<p className="text-xs text-zinc-500">
					{total} registro{total === 1 ? "" : "s"} · ordenados por recencia
				</p>
			</div>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Fecha</TableHeader>
							<TableHeader>Hora</TableHeader>
							<TableHeader>Nivel</TableHeader>
							<TableHeader className="hidden md:table-cell">Origen</TableHeader>
							<TableHeader className="hidden lg:table-cell">Módulo</TableHeader>
							<TableHeader>Evento</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader className="text-right">Detalle</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{logs.map((item) => {
							const active = selectedId === item.id;
							return (
								<TableRow
									key={item.id}
									className={clsx(
										"cursor-pointer transition",
										active && "bg-zinc-50 dark:bg-zinc-800/60",
									)}
									onClick={() => onSelect?.(item)}
									onKeyDown={(e) => {
										if (e.key === "Enter" || e.key === " ") {
											e.preventDefault();
											onSelect?.(item);
										}
									}}
									tabIndex={0}
									aria-selected={active}
								>
									<TableCell className="whitespace-nowrap text-sm">
										{item.date}
									</TableCell>
									<TableCell className="whitespace-nowrap text-sm text-zinc-500">
										{item.time}
									</TableCell>
									<TableCell>
										<div className="flex flex-col gap-1">
											<Badge color={LEVEL[item.level] || "zinc"}>
												{item.level_label}
											</Badge>
											<AnalyticsTruthBadge truth={item.truth} />
										</div>
									</TableCell>
									<TableCell className="hidden max-w-[10rem] truncate text-xs text-zinc-500 md:table-cell">
										{item.origin}
									</TableCell>
									<TableCell className="hidden max-w-[10rem] truncate text-xs text-zinc-500 lg:table-cell">
										{item.module}
									</TableCell>
									<TableCell className="max-w-[14rem]">
										<span className="line-clamp-2 font-medium">{item.event}</span>
										{item.patient && item.patient !== "—" ? (
											<p className="mt-0.5 text-[11px] text-zinc-400">
												{item.patient}
											</p>
										) : null}
									</TableCell>
									<TableCell>
										<Badge color={STATUS[item.status] || "zinc"}>
											{item.status_label}
										</Badge>
									</TableCell>
									<TableCell className="text-right">
										<Button
											plain
											aria-label={`Ver detalle: ${item.event}`}
											onClick={(e) => {
												e.stopPropagation();
												onSelect?.(item);
											}}
										>
											<EyeIcon className="size-4" />
										</Button>
									</TableCell>
								</TableRow>
							);
						})}
					</TableBody>
				</Table>
			</div>
		</section>
	);
}
