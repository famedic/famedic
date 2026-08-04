import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
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

const BADGE = {
	sky: "sky",
	blue: "blue",
	emerald: "emerald",
	purple: "violet",
	amber: "amber",
	orange: "orange",
	red: "red",
	zinc: "zinc",
};

const SEVERITY = {
	error: "red",
	warning: "amber",
	info: "sky",
};

function SkeletonRows() {
	return (
		<div className="space-y-2" aria-busy="true" aria-label="Cargando eventos">
			{Array.from({ length: 6 }).map((_, i) => (
				<div
					key={i}
					className="h-10 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800"
				/>
			))}
		</div>
	);
}

export default function EventTable({
	events = null,
	loading = false,
	selectedId = null,
	onSelect,
}) {
	if (loading || !events) {
		return (
			<section className="space-y-3">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Timeline global
					</h2>
					<p className="text-xs text-zinc-500">
						Eventos recientes del ecosistema Famedic.
					</p>
				</div>
				<SkeletonRows />
			</section>
		);
	}

	const items = events.items || [];

	if (!items.length) {
		return (
			<section className="space-y-3">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Timeline global
					</h2>
				</div>
				<EmptyListCard
					heading="Sin eventos"
					message="No hay eventos que coincidan con los filtros actuales."
				/>
			</section>
		);
	}

	return (
		<section className="space-y-3">
			<div className="flex flex-wrap items-end justify-between gap-2">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Timeline global
					</h2>
					<p className="text-xs text-zinc-500">
						{events.total} evento{events.total === 1 ? "" : "s"}
						{events.truncated ? " · mostrando los más recientes" : ""}
					</p>
				</div>
			</div>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Fecha</TableHeader>
							<TableHeader>Hora</TableHeader>
							<TableHeader>Tipo</TableHeader>
							<TableHeader>Paciente</TableHeader>
							<TableHeader className="hidden lg:table-cell">
								Origen
							</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader className="hidden md:table-cell">
								Badge
							</TableHeader>
							<TableHeader className="text-right">Detalle</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{items.map((event) => {
							const active = selectedId === event.id;
							return (
								<TableRow
									key={event.id}
									className={clsx(
										"cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-famedic-dark dark:focus-visible:outline-famedic-light",
										active && "bg-sky-50/80 dark:bg-sky-950/30",
									)}
									tabIndex={0}
									role="button"
									aria-label={`Ver detalle de ${event.type_label} · ${event.patient || "sin paciente"}`}
									onClick={() => onSelect?.(event)}
									onKeyDown={(e) => {
										if (e.key === "Enter" || e.key === " ") {
											e.preventDefault();
											onSelect?.(event);
										}
									}}
								>
									<TableCell className="whitespace-nowrap text-xs">
										{event.date}
									</TableCell>
									<TableCell className="whitespace-nowrap text-xs tabular-nums">
										{event.time}
									</TableCell>
									<TableCell>
										<div className="space-y-1">
											<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
												{event.type_label}
											</p>
											{event.event_type ? (
												<p className="text-[11px] text-zinc-400">
													{event.event_type}
												</p>
											) : null}
										</div>
									</TableCell>
									<TableCell>
										<div className="min-w-0">
											<p className="truncate text-sm text-zinc-800 dark:text-zinc-100">
												{event.patient || "—"}
											</p>
											{event.patient_email ? (
												<p className="truncate text-[11px] text-zinc-400">
													{event.patient_email}
												</p>
											) : null}
										</div>
									</TableCell>
									<TableCell className="hidden lg:table-cell">
										<Text className="text-xs">{event.source_label}</Text>
									</TableCell>
									<TableCell>
										<div className="flex flex-wrap gap-1">
											<Badge color={BADGE[event.color] || "zinc"}>
												{event.status_label}
											</Badge>
											<Badge
												color={SEVERITY[event.severity] || "zinc"}
												className="hidden sm:inline-flex"
											>
												{event.severity_label}
											</Badge>
										</div>
									</TableCell>
									<TableCell className="hidden md:table-cell">
										<Badge color={BADGE[event.color] || "zinc"}>
											{event.badge}
										</Badge>
									</TableCell>
									<TableCell className="text-right">
										<Button
											plain
											aria-label={`Ver detalle de ${event.type_label}`}
											onClick={(e) => {
												e.stopPropagation();
												onSelect?.(event);
											}}
										>
											<EyeIcon className="size-4" />
											Ver
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
