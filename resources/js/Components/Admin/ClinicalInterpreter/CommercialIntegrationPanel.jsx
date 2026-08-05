import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

function MoneyRow({ label, value, strong = false }) {
	return (
		<div
			className={`flex items-center justify-between gap-3 text-sm ${
				strong
					? "font-semibold text-zinc-900 dark:text-zinc-50"
					: "text-zinc-600 dark:text-zinc-300"
			}`}
		>
			<span>{label}</span>
			<span>{value ?? "—"}</span>
		</div>
	);
}

export default function CommercialIntegrationPanel({
	enabled = false,
	proposal = null,
	loading = false,
	actionMessage = null,
	actionTone = "success",
	onCreateQuote,
	onAddToCart,
	onSaveDraft,
}) {
	const summary = proposal?.summary;
	const labs = proposal?.groups?.laboratories || [];
	const packages = proposal?.packages || [];
	const participatingLabs = [
		...new Set(labs.map((l) => l.laboratory).filter(Boolean)),
	];
	const estimatedDelivery =
		labs.find((l) => l.delivery_time)?.delivery_time || null;
	const totalSavings =
		packages.reduce((sum, pkg) => sum + (Number(pkg.savings_cents) || 0), 0) ||
		null;

	return (
		<section className="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
			<header className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
				<div className="space-y-1">
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Commercial Integration Engine
					</p>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Propuesta comercial Famedic
					</h2>
					<Text className="!text-xs text-zinc-500">
						{enabled
							? "Estudios validados · propuesta de laboratorio lista (sin checkout)"
							: "Completa la validación de estudios para habilitar el motor comercial"}
					</Text>
				</div>
				<div className="flex flex-wrap gap-1.5">
					<Badge color={enabled ? "emerald" : "amber"}>
						{enabled ? "Listo" : "Bloqueado"}
					</Badge>
					<Badge color="sky">Solo laboratorio</Badge>
				</div>
			</header>

			{!enabled ? (
				<p className="rounded-lg border border-amber-200/70 bg-amber-50/50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/20 dark:text-amber-300">
					Los botones Crear Cotización, Agregar al Carrito y Guardar borrador se
					habilitan cuando todos los estudios estén validados.
				</p>
			) : loading ? (
				<div className="space-y-2">
					{[1, 2, 3].map((i) => (
						<div
							key={i}
							className="h-14 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800"
						/>
					))}
				</div>
			) : (
				<>
					<div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
						<section className="rounded-lg border border-zinc-100 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-950/40">
							<p className="mb-3 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
								Resumen comercial
							</p>
							<ul className="space-y-2 text-sm text-zinc-700 dark:text-zinc-200">
								<li className="flex justify-between gap-2">
									<span>Número de estudios</span>
									<span className="font-medium">
										{summary?.studies_count ?? labs.length}
									</span>
								</li>
								<li className="flex justify-between gap-2">
									<span>Laboratorios participantes</span>
									<span className="font-medium text-right">
										{participatingLabs.length
											? participatingLabs.join(", ")
											: "—"}
									</span>
								</li>
								<li className="flex justify-between gap-2">
									<span>Tiempo estimado</span>
									<span className="font-medium">
										{estimatedDelivery || "Según laboratorio"}
									</span>
								</li>
								<li className="flex justify-between gap-2">
									<span>Paquetes encontrados</span>
									<span className="font-medium">{packages.length}</span>
								</li>
							</ul>
							<div className="mt-4 space-y-1.5 border-t border-zinc-200 pt-3 dark:border-zinc-700">
								<MoneyRow label="Subtotal" value={summary?.subtotal} />
								<MoneyRow
									label="Ahorro estimado"
									value={
										summary?.discounts ||
										(totalSavings
											? `$${(totalSavings / 100).toFixed(2)}`
											: null)
									}
								/>
								<MoneyRow label="Total" value={summary?.total} strong />
							</div>
						</section>

						<section className="rounded-lg border border-zinc-100 p-4 dark:border-zinc-800 lg:col-span-2">
							<p className="mb-3 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
								Laboratorios
							</p>
							{labs.length === 0 ? (
								<p className="text-xs text-zinc-400">
									Sin estudios confirmados en la propuesta.
								</p>
							) : (
								<div className="overflow-x-auto">
									<table className="min-w-full text-left text-xs">
										<thead className="text-[11px] uppercase tracking-wide text-zinc-400">
											<tr>
												<th className="py-2 pr-3 font-semibold">Nombre</th>
												<th className="py-2 pr-3 font-semibold">Código</th>
												<th className="py-2 pr-3 font-semibold">Laboratorio</th>
												<th className="py-2 pr-3 font-semibold">Precio</th>
												<th className="py-2 pr-3 font-semibold">Entrega</th>
												<th className="py-2 pr-3 font-semibold">Cita</th>
												<th className="py-2 font-semibold">Disponible</th>
											</tr>
										</thead>
										<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
											{labs.map((lab) => (
												<tr key={lab.detection_id || lab.code || lab.name}>
													<td className="py-2 pr-3 text-zinc-800 dark:text-zinc-100">
														{lab.name}
													</td>
													<td className="py-2 pr-3 text-zinc-500">{lab.code}</td>
													<td className="py-2 pr-3 text-zinc-500">
														{lab.laboratory}
													</td>
													<td className="py-2 pr-3 font-medium text-zinc-800 dark:text-zinc-100">
														{lab.price}
													</td>
													<td className="py-2 pr-3 text-zinc-500">
														{lab.delivery_time}
													</td>
													<td className="py-2 pr-3">
														<Badge
															color={lab.requires_appointment ? "amber" : "zinc"}
															className="!text-[10px]"
														>
															{lab.requires_appointment ? "Sí" : "No"}
														</Badge>
													</td>
													<td className="py-2">
														<Badge
															color={lab.available ? "emerald" : "red"}
															className="!text-[10px]"
														>
															{lab.available ? "Sí" : "No"}
														</Badge>
													</td>
												</tr>
											))}
										</tbody>
									</table>
								</div>
							)}
						</section>
					</div>

					{packages.length > 0 && (
						<section className="space-y-2">
							<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
								Paquetes encontrados
							</p>
							{packages.map((pkg) => (
								<div
									key={pkg.package_id}
									className="rounded-lg border border-emerald-200/70 bg-emerald-50/40 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20"
								>
									<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
										{pkg.message}
									</p>
									<p className="mt-1 text-xs text-zinc-500">
										{pkg.name} · {pkg.code} · {pkg.laboratory}
									</p>
									<div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
										<MoneyRow
											label="Precio individual"
											value={pkg.individual_price}
										/>
										<MoneyRow
											label="Precio paquete"
											value={pkg.package_price}
										/>
										<MoneyRow
											label="Ahorro estimado"
											value={pkg.savings}
											strong
										/>
									</div>
								</div>
							))}
						</section>
					)}

					<section className="rounded-lg border border-zinc-100 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-950/40">
						<p className="mb-3 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
							Resumen económico
						</p>
						<div className="max-w-sm space-y-1.5">
							<MoneyRow label="Subtotal" value={summary?.subtotal} />
							<MoneyRow label="Ahorro estimado" value={summary?.discounts} />
							<MoneyRow label="Total" value={summary?.total} strong />
						</div>
					</section>

					<div className="flex flex-wrap gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
						<Button disabled={!enabled || loading} onClick={onCreateQuote}>
							Crear Cotización
						</Button>
						<Button
							outline
							disabled={!enabled || loading}
							onClick={onAddToCart}
						>
							Agregar al Carrito
						</Button>
						<Button
							outline
							disabled={!enabled || loading}
							onClick={onSaveDraft}
						>
							Guardar como borrador
						</Button>
					</div>

					{actionMessage && (
						<p
							className={`text-xs ${
								actionTone === "error"
									? "text-red-600 dark:text-red-400"
									: "text-emerald-700 dark:text-emerald-400"
							}`}
						>
							{actionMessage}
						</p>
					)}
				</>
			)}
		</section>
	);
}
