import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChartCard } from "./chartTheme.jsx";

function formatMoney(value) {
	return `$${Number(value || 0).toLocaleString("es-MX", {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})}`;
}

function RankTable({ title, description, rows, columns, emptyLabel }) {
	return (
		<ChartCard title={title} description={description}>
			{!rows?.length ? (
				<Text className="text-sm text-zinc-500">{emptyLabel}</Text>
			) : (
				<div className="overflow-x-auto">
					<table className="min-w-full text-left text-sm">
						<thead className="text-xs uppercase tracking-wide text-zinc-500">
							<tr>
								<th className="pb-2 pr-2 font-medium">#</th>
								{columns.map((column) => (
									<th key={column.key} className="pb-2 pr-3 font-medium">
										{column.label}
									</th>
								))}
							</tr>
						</thead>
						<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
							{rows.map((row, index) => (
								<tr key={`${row.id}-${index}`}>
									<td className="py-2.5 pr-2">
										<Badge color="zinc">{index + 1}</Badge>
									</td>
									{columns.map((column) => (
										<td
											key={column.key}
											className={`py-2.5 pr-3 ${column.className || ""}`}
										>
											{column.render
												? column.render(row)
												: row[column.key]}
										</td>
									))}
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}
		</ChartCard>
	);
}

export default function TopStudies({ data }) {
	return (
		<div className="grid gap-4 xl:grid-cols-3">
			<RankTable
				title="Estudios más vendidos"
				description="Por ingreso en pedidos del periodo."
				emptyLabel="Sin estudios vendidos en el periodo."
				rows={data?.sold || []}
				columns={[
					{
						key: "name",
						label: "Estudio",
						render: (row) => (
							<span className="font-medium text-zinc-900 dark:text-zinc-50">
								{row.name}
							</span>
						),
					},
					{
						key: "quantity",
						label: "Cant.",
						className: "tabular-nums",
					},
					{
						key: "revenue",
						label: "Ingreso",
						className: "tabular-nums text-emerald-700 dark:text-emerald-400",
						render: (row) => formatMoney(row.revenue),
					},
				]}
			/>
			<RankTable
				title="Estudios con más abandonos"
				description="Más frecuentes en carritos abandonados."
				emptyLabel="Sin estudios abandonados en el periodo."
				rows={data?.abandoned || []}
				columns={[
					{
						key: "name",
						label: "Estudio",
						render: (row) => (
							<span className="font-medium text-zinc-900 dark:text-zinc-50">
								{row.name}
							</span>
						),
					},
					{
						key: "carts",
						label: "Carritos",
						className: "tabular-nums text-rose-600 dark:text-rose-400",
					},
					{
						key: "value",
						label: "Valor",
						className: "tabular-nums",
						render: (row) => formatMoney(row.value),
					},
				]}
			/>
			<RankTable
				title="Mayor ingreso por estudio"
				description="Ranking por revenue (misma base de ventas)."
				emptyLabel="Sin ingreso de estudios en el periodo."
				rows={data?.by_revenue || []}
				columns={[
					{
						key: "name",
						label: "Estudio",
						render: (row) => (
							<span className="line-clamp-2 font-medium text-zinc-900 dark:text-zinc-50">
								{row.name}
							</span>
						),
					},
					{
						key: "revenue",
						label: "Ingreso",
						className: "tabular-nums text-blue-700 dark:text-blue-300",
						render: (row) => (
							<>
								<Strong>{formatMoney(row.revenue)}</Strong>
								<span className="mt-0.5 block text-[11px] text-zinc-400">
									{row.quantity} uds
								</span>
							</>
						),
					},
				]}
			/>
		</div>
	);
}
