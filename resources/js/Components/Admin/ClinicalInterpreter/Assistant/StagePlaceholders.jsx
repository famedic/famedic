/**
 * Neutral placeholders for FASE 1 visual review.
 * Avoid competing colors with the shell chrome.
 */
export function StagePlaceholder({ title, bullets = [] }) {
	return (
		<div className="mx-auto max-w-lg space-y-8">
			<div className="space-y-2 text-center sm:text-left">
				<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					{title}
				</p>
				<p className="text-sm leading-relaxed text-zinc-500">
					Vista previa del Shell. El contenido real de esta etapa llegará en
					fases posteriores — sin lógica de negocio todavía.
				</p>
			</div>

			<ul className="space-y-2.5">
				{bullets.map((item) => (
					<li
						key={item}
						className="flex items-start gap-3 rounded-xl border border-zinc-100 bg-zinc-50/80 px-4 py-3.5 transition hover:border-zinc-200 dark:border-zinc-800 dark:bg-zinc-950/40 dark:hover:border-zinc-700"
					>
						<span
							className="mt-1.5 size-1.5 shrink-0 rounded-full bg-zinc-300 dark:bg-zinc-600"
							aria-hidden
						/>
						<span className="text-sm leading-snug text-zinc-600 dark:text-zinc-300">
							{item}
						</span>
					</li>
				))}
			</ul>

			<div
				className="space-y-3 rounded-xl border border-dashed border-zinc-200 px-4 py-6 dark:border-zinc-700"
				aria-hidden
			>
				<div className="mx-auto h-3 max-w-[12rem] animate-pulse rounded-full bg-zinc-100 dark:bg-zinc-800" />
				<div className="mx-auto h-3 max-w-[16rem] animate-pulse rounded-full bg-zinc-100 dark:bg-zinc-800" />
				<div className="mx-auto h-3 max-w-[10rem] animate-pulse rounded-full bg-zinc-100 dark:bg-zinc-800" />
			</div>
		</div>
	);
}

export const STAGE_PLACEHOLDERS = {
	interpret: {
		title: "Espacio para Interpretar",
		bullets: [
			"Subir receta (drag & drop)",
			"Procesamiento con progreso",
			"Resumen IA antes del matching",
			"Documento colapsable",
		],
	},
	validate: {
		title: "Espacio para Validar",
		bullets: [
			"Cards de matching por estudio",
			"Acordeón de alternativas",
			"Checklist de estudios elegidos",
			"Confirmar Orden",
		],
	},
	finalize: {
		title: "Espacio para Finalizar",
		bullets: [
			"Resumen comercial",
			"Generar Laboratory Order",
			"Expediente clínico",
			"Cotizar / Preparar carrito",
		],
	},
};
