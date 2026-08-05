import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

export default function ClinicalOrderSummary({
	order = null,
	enabled = false,
	onSave,
	onOpen,
	onCreateQuote,
	onCreateCart,
	busy = false,
}) {
	return (
		<section className="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
			<header className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
				<div className="space-y-1">
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Clinical Order Engine
					</p>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Clinical Order Summary
					</h2>
					<Text className="!text-xs text-zinc-500">
						Orden oficial de estudios de laboratorio validados · no es carrito ni
						pedido
					</Text>
				</div>
				<Badge color={order ? "emerald" : enabled ? "sky" : "amber"}>
					{order
						? order.status_label || order.status
						: enabled
							? "Lista para guardar"
							: "Pendiente validación"}
				</Badge>
			</header>

			{!enabled && !order ? (
				<p className="text-xs text-zinc-400">
					Completa Human Validation para generar la Clinical Order.
				</p>
			) : (
				<>
					<div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
						<div className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								ID
							</p>
							<p className="mt-1 truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{order?.id ?? "—"}
							</p>
							{order?.uuid && (
								<p className="truncate text-[10px] text-zinc-400">
									{order.uuid}
								</p>
							)}
						</div>
						<div className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								Estado
							</p>
							<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{order?.status_label || "Validada (preview)"}
							</p>
						</div>
						<div className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								Fecha
							</p>
							<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{order?.created_at
									? new Date(order.created_at).toLocaleString("es-MX")
									: "—"}
							</p>
						</div>
						<div className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								Operador
							</p>
							<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{order?.operator?.name || "—"}
							</p>
						</div>
						<div className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								Estudios
							</p>
							<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{order?.studies_count ?? "—"}
							</p>
						</div>
						<div className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								Total
							</p>
							<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{order?.total ?? "—"}
							</p>
						</div>
					</div>

					<div className="flex flex-wrap gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
						<Button disabled={!enabled || busy} onClick={onSave}>
							Guardar Clinical Order
						</Button>
						<Button
							outline
							disabled={!order?.id || busy}
							onClick={onOpen}
						>
							Abrir Clinical Order
						</Button>
						<Button
							outline
							disabled={!enabled || busy}
							onClick={onCreateQuote}
						>
							Crear Cotización
						</Button>
						<Button
							outline
							disabled={!enabled || busy}
							onClick={onCreateCart}
						>
							Crear Carrito
						</Button>
					</div>
				</>
			)}
		</section>
	);
}
