import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ArrowPathIcon, EyeIcon, LockClosedIcon } from "@heroicons/react/24/outline";

export default function ResultsSection({
	hasResults = false,
	resultsUploadedAt = null,
	onViewResults,
	isProcessing = false,
}) {
	return (
		<div className="space-y-4">
			<div className="flex items-center justify-between gap-2">
				<h3 className="break-words text-base font-semibold text-zinc-900 dark:text-white">Resultados</h3>
				<Badge color={hasResults ? "green" : "slate"}>{hasResults ? "Disponible" : "Pendiente"}</Badge>
			</div>

			{hasResults ? (
				<>
					<div className="space-y-1">
						<Text className="text-sm text-zinc-600 dark:text-slate-400">
							Fecha de carga: <Strong>{resultsUploadedAt || "Sin fecha registrada"}</Strong>
						</Text>
						<span
							className="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700 dark:bg-slate-800 dark:text-slate-200"
							title="Tus resultados están protegidos. Te pediremos un código OTP."
						>
							<LockClosedIcon className="size-3" />
							🔒 Protegido OTP
						</span>
					</div>

					<Button
						onClick={onViewResults}
						disabled={isProcessing}
						color="famedic-lime"
						className="w-full justify-center"
					>
						{isProcessing ? (
							<>
								<ArrowPathIcon className="size-4 animate-spin" />
								Validando...
							</>
						) : (
							<>
								<EyeIcon className="size-4" />
								Ver resultados
							</>
						)}
					</Button>
				</>
			) : (
				<Text className="text-sm text-zinc-500 dark:text-slate-400">
					Aún no hay resultados disponibles para esta orden.
				</Text>
			)}
		</div>
	);
}
