import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { XMarkIcon } from "@heroicons/react/16/solid";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	proximamente: { label: "Próximamente", color: "zinc" },
	instrumentacion: {
		label: "Requiere instrumentación",
		color: "violet",
	},
};

function Row({ label, value }) {
	return (
		<div className="flex justify-between gap-3 border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-zinc-800">
			<span className="text-zinc-400">{label}</span>
			<span className="max-w-[60%] text-right font-medium text-zinc-800 dark:text-zinc-200">
				{value || "No disponible"}
			</span>
		</div>
	);
}

export default function JourneySidebar({
	detail = null,
	loading = false,
	onClose,
}) {
	const truth = TRUTH[detail?.truth] || TRUTH.proximamente;

	return (
		<aside className="flex h-full min-h-[28rem] flex-col rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex items-start justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
						Detalle del nodo
					</p>
					<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						{loading
							? "Cargando…"
							: detail?.title || "Selecciona un nodo"}
					</p>
				</div>
				{onClose ? (
					<Button plain onClick={onClose} aria-label="Cerrar detalle">
						<XMarkIcon className="size-4" />
					</Button>
				) : null}
			</div>

			<div className="flex-1 space-y-4 overflow-y-auto px-4 py-4">
				{loading ? (
					<div className="space-y-2" aria-busy="true">
						{Array.from({ length: 5 }).map((_, i) => (
							<div
								key={i}
								className="h-8 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"
							/>
						))}
					</div>
				) : !detail ? (
					<Text className="text-sm text-zinc-500">
						Haz clic en un nodo del Journey para hidratar el detalle bajo
						demanda.
					</Text>
				) : (
					<>
						<div className="flex flex-wrap gap-2">
							<Badge color={truth.color}>{truth.label}</Badge>
							{detail.badge ? (
								<Badge color="sky">{detail.badge}</Badge>
							) : null}
						</div>

						<p className="text-sm text-zinc-600 dark:text-zinc-300">
							{detail.description}
						</p>

						<div>
							<Row label="Origen" value={detail.origin} />
							<Row label="Tabla" value={detail.table} />
							<Row label="Modelo" value={detail.model} />
							<Row label="Fecha" value={detail.date} />
							<Row label="Estado" value={detail.status} />
						</div>

						<div>
							<p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
								Acciones futuras
							</p>
							<ul className="space-y-1.5">
								{(detail.actions || []).map((action) => (
									<li
										key={action}
										className="rounded-lg border border-dashed border-zinc-200 px-3 py-2 text-xs text-zinc-500 dark:border-zinc-700"
									>
										{action}
									</li>
								))}
							</ul>
						</div>
					</>
				)}
			</div>
		</aside>
	);
}
