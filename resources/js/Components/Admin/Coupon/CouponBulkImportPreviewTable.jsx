import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Checkbox } from "@/Components/Catalyst/checkbox";
import { Input } from "@/Components/Catalyst/input";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Text } from "@/Components/Catalyst/text";
import { getBulkRowStatusMeta, isBulkRowConfirmable } from "@/lib/couponBulkImportPreview";

export default function CouponBulkImportPreviewTable({
	rows,
	pageInfo,
	onToggleInclude,
	onRemoveRow,
	onUpdateRow,
}) {
	return (
		<div className="space-y-3">
			<Text className="text-sm text-zinc-600 dark:text-zinc-400">
				Mostrando {pageInfo.showingFrom}–{pageInfo.showingTo} de {pageInfo.total} registros
			</Text>

			<div className="overflow-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950">
				<Table dense tableClassName="table-fixed w-full">
					<TableHead>
						<TableRow>
							<TableHeader className="w-12">Incluir</TableHeader>
							<TableHeader>Nombre</TableHeader>
							<TableHeader className="w-[10.5rem]">Correo</TableHeader>
							<TableHeader className="w-[9.5rem]">Estado</TableHeader>
							<TableHeader className="w-16" />
						</TableRow>
					</TableHead>
					<TableBody>
						{rows.length === 0 ? (
							<TableRow>
								<TableCell colSpan={5} className="py-8 text-center text-sm text-zinc-500">
									No hay registros con los filtros actuales.
								</TableCell>
							</TableRow>
						) : (
							rows.map((row) => {
								const meta = getBulkRowStatusMeta(row);
								const confirmable = isBulkRowConfirmable(row);
								const surnames = [row.paternal_lastname, row.maternal_lastname]
									.filter(Boolean)
									.join(" ");

								return (
									<TableRow key={`${row._index}-${row.email}`}>
										<TableCell className="align-top">
											<Checkbox
												key={`${row._index}-${row.include ? 1 : 0}`}
												checked={!!row.include}
												disabled={!confirmable}
												onChange={(v) => onToggleInclude(row._index, v)}
											/>
										</TableCell>
										<TableCell className="min-w-[12rem] align-top">
											{row.editable === false ? (
												<span className="text-sm">
													{[row.first_name, surnames].filter(Boolean).join(" ") || "—"}
												</span>
											) : (
												<div className="space-y-1">
													<Input
														value={row.first_name ?? ""}
														placeholder="Nombre"
														onChange={(e) =>
															onUpdateRow(row._index, { first_name: e.target.value })
														}
													/>
													<div className="grid gap-1 sm:grid-cols-2">
														<Input
															value={row.paternal_lastname ?? ""}
															placeholder="Ap. paterno"
															onChange={(e) =>
																onUpdateRow(row._index, {
																	paternal_lastname: e.target.value,
																})
															}
														/>
														<Input
															value={row.maternal_lastname ?? ""}
															placeholder="Ap. materno"
															onChange={(e) =>
																onUpdateRow(row._index, {
																	maternal_lastname: e.target.value,
																})
															}
														/>
													</div>
												</div>
											)}
										</TableCell>
										<TableCell className="w-[10.5rem] max-w-[10.5rem] align-top overflow-hidden">
											<span
												className="block truncate font-mono text-xs text-zinc-800 dark:text-zinc-200"
												title={row.email}
											>
												{row.email}
											</span>
										</TableCell>
										<TableCell className="w-[9.5rem] align-top whitespace-normal">
											<Badge color={meta.color}>{meta.label}</Badge>
										</TableCell>
										<TableCell className="align-top text-right">
											<Button
												type="button"
												plain
												className="text-red-600 dark:text-red-400"
												onClick={() => onRemoveRow(row._index)}
											>
												Quitar
											</Button>
										</TableCell>
									</TableRow>
								);
							})
						)}
					</TableBody>
				</Table>
			</div>

			{pageInfo.totalPages > 1 ? (
				<div className="flex flex-wrap items-center justify-between gap-3">
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						Página {pageInfo.page} de {pageInfo.totalPages}
					</Text>
					<div className="flex gap-2">
						<Button
							type="button"
							outline
							disabled={pageInfo.page <= 1}
							onClick={() => pageInfo.onPageChange(pageInfo.page - 1)}
						>
							Anterior
						</Button>
						<Button
							type="button"
							outline
							disabled={pageInfo.page >= pageInfo.totalPages}
							onClick={() => pageInfo.onPageChange(pageInfo.page + 1)}
						>
							Siguiente
						</Button>
					</div>
				</div>
			) : null}
		</div>
	);
}
