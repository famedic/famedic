import { useMemo, useState } from "react";
import * as Headless from "@headlessui/react";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Select } from "@/Components/Catalyst/select";
import {
	applyLaboratoryStrategy,
	collectLaboratoryBrands,
} from "./labSelection";

const STRATEGIES = [
	{
		id: "best_price",
		label: "Mejor precio",
		hint: "Elige la opción más económica entre coincidencias de cada estudio.",
	},
	{
		id: "fastest",
		label: "Menor tiempo de entrega",
		hint: "Prioriza la entrega más rápida disponible.",
	},
	{
		id: "specific",
		label: "Laboratorio específico",
		hint: "Construye la orden con una sola marca cuando sea posible.",
	},
];

/**
 * Light modal before generating Laboratory Order.
 */
export default function LabSelectionModal({
	open,
	onClose,
	validatedItems = [],
	onConfirm,
	busy = false,
}) {
	const brands = useMemo(
		() => collectLaboratoryBrands(validatedItems),
		[validatedItems],
	);
	const [strategy, setStrategy] = useState("best_price");
	const [brand, setBrand] = useState("");

	const preview = useMemo(() => {
		if (strategy === "specific" && !brand) {
			return { selected: [], unavailable: [], ready: false };
		}
		const result = applyLaboratoryStrategy(
			validatedItems,
			strategy,
			strategy === "specific" ? brand : null,
		);
		return {
			selected: result.selected,
			unavailable: result.unavailable,
			ready: result.selected.length > 0,
			compose: result.composeOrderItems,
		};
	}, [validatedItems, strategy, brand]);

	const submit = (mode) => {
		if (!preview.compose) return;
		const items = preview.compose(mode);
		onConfirm?.({
			strategy,
			brand: strategy === "specific" ? brand : null,
			items,
			selectedCount: preview.selected.length,
			unavailableCount: preview.unavailable.length,
		});
	};

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop
				transition
				className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0"
			/>
			<div className="fixed inset-0 flex items-center justify-center p-4">
				<Headless.DialogPanel
					transition
					className="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-5 shadow-xl transition data-closed:scale-95 data-closed:opacity-0 dark:border-zinc-700 dark:bg-zinc-900 sm:p-6"
				>
					<div className="space-y-1.5">
						<Headless.DialogTitle className="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
							Seleccionar laboratorio
						</Headless.DialogTitle>
						<p className="text-sm text-zinc-500">
							Elige cómo construir la Laboratory Order. Siempre puedes
							continuar.
						</p>
					</div>

					<div className="mt-5 space-y-2.5">
						{STRATEGIES.map((opt) => (
							<label
								key={opt.id}
								className={`flex cursor-pointer gap-3 rounded-xl border px-3.5 py-3 transition ${
									strategy === opt.id
										? "border-famedic-light/50 bg-famedic-light/5 dark:border-famedic-light/40"
										: "border-zinc-200 hover:border-zinc-300 dark:border-zinc-700"
								}`}
							>
								<input
									type="radio"
									name="lab-strategy"
									className="mt-1"
									checked={strategy === opt.id}
									onChange={() => setStrategy(opt.id)}
								/>
								<span className="min-w-0">
									<span className="block text-sm font-medium text-zinc-900 dark:text-zinc-50">
										{opt.label}
									</span>
									<span className="mt-0.5 block text-xs text-zinc-400">
										{opt.hint}
									</span>
								</span>
							</label>
						))}
					</div>

					{strategy === "specific" && (
						<div className="mt-4">
							<Field>
								<Label>Marca / laboratorio</Label>
								<Select
									value={brand}
									onChange={(e) => setBrand(e.target.value)}
								>
									<option value="">Selecciona una marca</option>
									{brands.map((b) => (
										<option key={b} value={b}>
											{b}
										</option>
									))}
								</Select>
							</Field>
							{brands.length === 0 && (
								<p className="mt-2 text-xs text-amber-700 dark:text-amber-300">
									No hay marcas en los estudios incluidos.
								</p>
							)}
						</div>
					)}

					{preview.unavailable.length > 0 && (
						<div className="mt-4 rounded-xl border border-amber-200/80 bg-amber-50/60 px-3.5 py-3 dark:border-amber-800/40 dark:bg-amber-950/25">
							<p className="text-sm font-medium text-amber-900 dark:text-amber-200">
								{preview.unavailable.length} estudio
								{preview.unavailable.length === 1 ? "" : "s"} no disponible
								{preview.unavailable.length === 1 ? "" : "s"} en esta opción
							</p>
							<ul className="mt-2 space-y-1">
								{preview.unavailable.map((item) => (
									<li
										key={item.detection_id}
										className="text-xs text-zinc-600 dark:text-zinc-300"
									>
										{item.detected_name || item.match?.name || "Estudio"}
									</li>
								))}
							</ul>
							<p className="mt-2 text-xs text-zinc-500">
								Puedes cambiar de laboratorio o continuar solo con los estudios
								disponibles.
							</p>
						</div>
					)}

					<div className="mt-6 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-end">
						<Button outline disabled={busy} onClick={onClose} className="!text-sm">
							Cancelar
						</Button>
						{preview.unavailable.length > 0 && strategy === "specific" && (
							<Button
								outline
								disabled={busy}
								onClick={() => {
									setBrand("");
								}}
								className="!text-sm"
							>
								Cambiar laboratorio
							</Button>
						)}
						{preview.unavailable.length > 0 && preview.ready && (
							<Button
								outline
								disabled={busy}
								onClick={() => submit("include_available_only")}
								className="!text-sm"
							>
								Continuar con disponibles
							</Button>
						)}
						<Button
							disabled={
								busy ||
								!preview.ready ||
								(strategy === "specific" && !brand)
							}
							onClick={() => submit("include_available_only")}
							className="!text-sm"
						>
							{busy ? "Generando…" : "Aceptar"}
						</Button>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
