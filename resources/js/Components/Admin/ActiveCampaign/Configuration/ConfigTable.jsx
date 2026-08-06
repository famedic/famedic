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

const STATUS = {
	ok: "emerald",
	pending: "amber",
	critical: "red",
	disabled: "zinc",
};

export default function ConfigTable({
	configs = [],
	selectedId = null,
	onSelect,
	total = 0,
}) {
	if (!configs.length) {
		return (
			<section className="space-y-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Listado
				</h2>
				<EmptyListCard
					heading="Sin configuraciones"
					message="No hay configuraciones que coincidan con los filtros."
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
					{total} configuración{total === 1 ? "" : "es"} · valores sensibles
					sanitizados
				</p>
			</div>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Nombre</TableHeader>
							<TableHeader>Categoría</TableHeader>
							<TableHeader>Valor</TableHeader>
							<TableHeader className="hidden md:table-cell">Origen</TableHeader>
							<TableHeader className="hidden lg:table-cell">
								Ambiente
							</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader className="hidden xl:table-cell">
								Última actualización
							</TableHeader>
							<TableHeader className="text-right">Detalle</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{configs.map((item) => {
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
									<TableCell className="max-w-[14rem]">
										<span className="line-clamp-2 font-medium">{item.name}</span>
										<div className="mt-1 flex flex-wrap gap-1">
											<AnalyticsTruthBadge truth={item.truth} />
											{item.critical ? (
												<Badge color="orange">Crítica</Badge>
											) : null}
											{item.sensitive ? (
												<Badge color="zinc">Secreto</Badge>
											) : null}
										</div>
									</TableCell>
									<TableCell>
										<Badge color="sky">{item.category}</Badge>
									</TableCell>
									<TableCell className="max-w-[12rem]">
										<span className="line-clamp-2 font-mono text-xs text-zinc-600 dark:text-zinc-300">
											{item.value}
										</span>
									</TableCell>
									<TableCell className="hidden max-w-[10rem] truncate text-xs text-zinc-500 md:table-cell">
										{item.origin}
									</TableCell>
									<TableCell className="hidden text-sm lg:table-cell">
										{item.environment}
									</TableCell>
									<TableCell>
										<Badge color={STATUS[item.status] || "zinc"}>
											{item.status_label}
										</Badge>
									</TableCell>
									<TableCell className="hidden whitespace-nowrap text-sm xl:table-cell">
										{item.last_updated}
										<div className="mt-0.5">
											<AnalyticsTruthBadge truth={item.last_updated_truth} />
										</div>
									</TableCell>
									<TableCell className="text-right">
										<Button
											plain
											aria-label={`Ver detalle: ${item.name}`}
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
