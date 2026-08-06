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
import { EyeIcon } from "@heroicons/react/16/solid";
import clsx from "clsx";

const PRIORITY = {
	critical: "red",
	warning: "amber",
	info: "sky",
};

const STATUS = {
	nueva: "blue",
	vista: "zinc",
	en_proceso: "amber",
	resuelta: "emerald",
};

export default function NotificationTable({
	notifications = [],
	selectedId = null,
	onSelect,
	total = 0,
}) {
	const items = notifications || [];

	if (!items.length) {
		return (
			<section className="space-y-3">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Bandeja principal
					</h2>
				</div>
				<EmptyListCard
					heading="Sin notificaciones"
					message="No hay elementos que coincidan con los filtros actuales."
				/>
			</section>
		);
	}

	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Bandeja principal
				</h2>
				<p className="text-xs text-zinc-500">
					{total} notificación{total === 1 ? "" : "es"} · priorizadas por
					recencia
				</p>
			</div>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Prioridad</TableHeader>
							<TableHeader>Título</TableHeader>
							<TableHeader className="hidden lg:table-cell">
								Descripción
							</TableHeader>
							<TableHeader>Paciente</TableHeader>
							<TableHeader className="hidden md:table-cell">
								Origen
							</TableHeader>
							<TableHeader>Fecha</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader className="text-right">Detalle</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{items.map((item) => {
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
									<TableCell>
										<Badge color={PRIORITY[item.priority] || "zinc"}>
											{item.priority_label}
										</Badge>
									</TableCell>
									<TableCell className="max-w-[14rem] font-medium">
										<span className="line-clamp-2">{item.title}</span>
									</TableCell>
									<TableCell className="hidden max-w-[18rem] lg:table-cell">
										<span className="line-clamp-2 text-zinc-500">
											{item.description}
										</span>
									</TableCell>
									<TableCell className="max-w-[10rem] truncate text-sm">
										{item.patient || "—"}
									</TableCell>
									<TableCell className="hidden max-w-[12rem] truncate text-xs text-zinc-500 md:table-cell">
										{item.origin}
									</TableCell>
									<TableCell className="whitespace-nowrap text-sm">
										{item.date}
										<span className="ml-1 text-zinc-400">{item.time}</span>
									</TableCell>
									<TableCell>
										<Badge color={STATUS[item.status] || "zinc"}>
											{item.status_label}
										</Badge>
									</TableCell>
									<TableCell className="text-right">
										<Button
											plain
											aria-label={`Ver detalle: ${item.title}`}
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
