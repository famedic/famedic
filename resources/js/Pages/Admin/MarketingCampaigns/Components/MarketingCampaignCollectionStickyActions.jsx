import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";

export default function MarketingCampaignCollectionStickyActions({
	processing = false,
	onCancel,
	onSave,
	onSaveAndReturn,
	saveLabel = "Guardar",
	showSaveAndReturn = true,
}) {
	return (
		<div className="sticky bottom-4 z-10 rounded-xl border border-zinc-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
			<div className="flex flex-wrap items-center justify-between gap-3">
				{onCancel ? (
					<Button type="button" outline onClick={onCancel}>
						Cancelar
					</Button>
				) : (
					<span />
				)}
				<div className="flex flex-wrap gap-2">
					<Button
						type="button"
						outline
						disabled={processing}
						onClick={onSave}
					>
						{processing && <ArrowPathIcon className="animate-spin" />}
						{processing ? "Guardando…" : saveLabel}
					</Button>
					{showSaveAndReturn && onSaveAndReturn && (
						<Button
							type="button"
							color="lime"
							disabled={processing}
							onClick={onSaveAndReturn}
						>
							{processing ? "Guardando…" : "Guardar y volver a campaña"}
						</Button>
					)}
				</div>
			</div>
		</div>
	);
}
