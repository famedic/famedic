import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

function Stat({ label, value, color = "zinc", unit = "items" }) {
	return (
		<div className="rounded-lg border border-zinc-100 bg-zinc-50/80 p-3 dark:border-zinc-800 dark:bg-zinc-950/40">
			<p className="text-[11px] uppercase tracking-wide text-zinc-400">{label}</p>
			<div className="mt-1 flex items-baseline gap-2">
				<p className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
					{value}
				</p>
				{unit ? (
					<Badge color={color} className="!text-[10px]">
						{unit}
					</Badge>
				) : null}
			</div>
		</div>
	);
}

const ACTION_LABELS = {
	add_to_cart: "Crear carrito",
	create_quote: "Cotizar",
	create_order: "Crear pedido",
	save_interpretation: "Guardar Clinical Order",
	patient_timeline: "Timeline del paciente",
};

export default function ResultPanel({
	summary,
	futureActions = [],
	phase,
	validationComplete = false,
	validationPending = 0,
	onAction,
}) {
	const busy = phase === "searching" || phase === "analyzing";

	const gatedIds = new Set([
		"add_to_cart",
		"create_quote",
		"save_interpretation",
	]);

	const unavailableIds = new Set(["create_order", "patient_timeline"]);

	return (
		<section className="flex h-full min-h-[320px] flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<header className="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
				<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
					Panel 4
				</p>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					{validationComplete ? "Resultado confirmado" : "Resultado"}
				</h2>
				<Text className="mt-1 !text-xs text-zinc-500">
					{validationComplete
						? "Interpretación validada por el operador"
						: "Resumen de coincidencias Famedic"}
				</Text>
			</header>

			<div className="flex-1 space-y-4 overflow-y-auto p-4">
				{busy ? (
					<div className="space-y-3">
						{[1, 2, 3, 4].map((i) => (
							<div
								key={i}
								className="h-16 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800"
							/>
						))}
					</div>
				) : (
					<>
						<div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
							<Stat
								label="Estudios encontrados"
								value={summary?.studies_found ?? 0}
								color="emerald"
							/>
							<Stat
								label="Coincidencias parciales"
								value={summary?.studies_similar ?? 0}
								color="amber"
							/>
							<Stat
								label="No encontrados"
								value={summary?.studies_not_found ?? 0}
								color="red"
							/>
							<Stat
								label="Porcentaje de éxito"
								value={`${summary?.success_rate ?? 0}%`}
								color="sky"
								unit="éxito"
							/>
						</div>

						<div
							className={`rounded-lg border p-3 ${
								validationComplete
									? "border-emerald-200/70 bg-emerald-50/50 dark:border-emerald-900/40 dark:bg-emerald-950/20"
									: "border-sky-200/70 bg-sky-50/50 dark:border-sky-900/40 dark:bg-sky-950/20"
							}`}
						>
							<p
								className={`text-[11px] font-semibold uppercase tracking-wide ${
									validationComplete
										? "text-emerald-700 dark:text-emerald-400"
										: "text-sky-700 dark:text-sky-400"
								}`}
							>
								{validationComplete
									? "Validación completa"
									: "Elementos pendientes de validación"}
							</p>
							<p className="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
								{validationComplete ? "Listo" : validationPending}
							</p>
							<p className="text-xs text-zinc-500">
								{validationComplete
									? "Usa las acciones abajo o el panel Comercial"
									: "Confirma o ignora cada ítem en Human Validation Center"}
							</p>
						</div>
					</>
				)}

				<div className="space-y-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
					<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
						Acciones de resultado
					</p>
					<div className="flex flex-col gap-1.5">
						{futureActions.map((action) => {
							const unavailable = unavailableIds.has(action.id);
							const gated = gatedIds.has(action.id);
							const enabled =
								!unavailable && gated && validationComplete && Boolean(onAction);

							return (
								<Button
									key={action.id}
									outline
									disabled={!enabled}
									className="justify-start"
									onClick={() => enabled && onAction?.(action.id)}
									title={
										unavailable
											? "No disponible en v1.0"
											: gated && !validationComplete
												? "Completa la validación humana primero"
												: undefined
									}
								>
									{ACTION_LABELS[action.id] || action.label}
									{unavailable
										? " (próximamente)"
										: gated && !validationComplete
											? " (bloqueado)"
											: ""}
								</Button>
							);
						})}
					</div>
					{!validationComplete && (
						<p className="text-[11px] text-zinc-400">
							Crear carrito / Guardar / Cotizar se habilitan solo con validación
							al 100%.
						</p>
					)}
				</div>
			</div>
		</section>
	);
}
