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
	equal: "emerald",
	different: "amber",
	pending: "zinc",
};

export default function QaTable({
	rows = [],
	selectedId = null,
	onSelect,
	total = 0,
}) {
	if (!rows.length) {
		return (
			<section className="space-y-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Comparador
				</h2>
				<EmptyListCard
					heading="Sin filas"
					message="No hay comparaciones que coincidan con los filtros."
				/>
			</section>
		);
	}

	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Comparador
				</h2>
				<p className="text-xs text-zinc-500">
					{total} fila{total === 1 ? "" : "s"} · por categorías · sin secretos
				</p>
			</div>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Nombre</TableHeader>
							<TableHeader>Categoría</TableHeader>
							<TableHeader>QA</TableHeader>
							<TableHeader>Producción</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader className="text-right">Detalle</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{rows.map((item) => {
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
										</div>
									</TableCell>
									<TableCell>
										<Badge color="sky">{item.category}</Badge>
									</TableCell>
									<TableCell>
										<div className="space-y-1">
											<span className="font-mono text-xs">{item.qa_value}</span>
											<AnalyticsTruthBadge truth={item.qa_truth} />
										</div>
									</TableCell>
									<TableCell>
										<div className="space-y-1">
											<span className="font-mono text-xs">{item.prod_value}</span>
											<AnalyticsTruthBadge truth={item.prod_truth} />
										</div>
									</TableCell>
									<TableCell>
										<Badge color={STATUS[item.compare_status] || "zinc"}>
											{item.compare_status_label}
										</Badge>
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
