import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ArrowPathIcon, FlagIcon, ClockIcon, EyeIcon, LockClosedIcon } from "@heroicons/react/24/outline";

function formatCountdown(totalSeconds = 0) {
	const safe = Math.max(0, Number(totalSeconds) || 0);
	const mins = Math.floor(safe / 60);
	const secs = safe % 60;
	return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

import NewResultBadge from "@/Components/Laboratory/NewResultBadge";
import {
	patientResultStatusColor,
	patientResultStatusLabel,
	patientResultStatusMessage,
	resolveEffectiveResultStatus,
} from "@/lib/laboratoryPurchaseResultUi";

export default function ResultsSection({
	hasResults = false,
	resultsUploadedAt = null,
	onViewResults,
	isProcessing = false,
	otpRequired = false,
	otpVerified = false,
	otpExpiresIn = 0,
	isNewResult = false,
	resultControl = null,
	errorMessage = null,
}) {
	const statusLabel = patientResultStatusLabel(resultControl, hasResults);
	const statusColor = patientResultStatusColor(resultControl, hasResults);
	const statusMessage = patientResultStatusMessage(resultControl, hasResults);
	const effectiveStatus = resolveEffectiveResultStatus(resultControl, hasResults);
	const buttonLabel = resultControl?.button_label || "Ver resultados";
	const compactStatusBadge = effectiveStatus === "manual_review" || effectiveStatus === "error";
	const compactStatusLabel = "Señal de seguimiento";

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<h3 className="break-words text-base font-semibold text-zinc-900 dark:text-white">Resultados</h3>
				<div className="flex flex-wrap items-center gap-2">
					{isNewResult && <NewResultBadge compact />}
					<Badge
						color={statusColor}
						className={compactStatusBadge ? "size-6 justify-center rounded-full p-0" : undefined}
						title={compactStatusBadge ? compactStatusLabel : statusLabel}
						aria-label={compactStatusBadge ? compactStatusLabel : statusLabel}
					>
						{compactStatusBadge ? <FlagIcon className="size-3.5" /> : statusLabel}
					</Badge>
				</div>
			</div>
			{statusMessage ? (
				<Text className="text-sm text-zinc-600 dark:text-slate-400">{statusMessage}</Text>
			) : null}

			{hasResults ? (
				<>
					<div className="space-y-1">
						<Text className="text-sm text-zinc-600 dark:text-slate-400">
							Fecha: <Strong>{resultsUploadedAt || "Sin fecha registrada"}</Strong>
						</Text>
						{otpRequired && (
							<>
								<span
									className="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700 dark:bg-slate-800 dark:text-slate-200"
									title="Tus resultados están protegidos. Te pediremos un código OTP."
								>
									<LockClosedIcon className="size-3" />
									🔒 Protegido OTP
								</span>
								{otpVerified && otpExpiresIn > 0 ? (
									<span
										className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-200"
										title="Tiempo restante para ver resultados sin volver a validar OTP"
									>
										<ClockIcon className="size-3" />
										Sesión activa: {formatCountdown(otpExpiresIn)}
									</span>
								) : (
									<Text className="text-xs text-zinc-500 dark:text-slate-400">
										Al abrir resultados se pedirá OTP.
									</Text>
								)}
							</>
						)}
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
								Obteniendo resultados...
							</>
						) : (
							<>
								<EyeIcon className="size-4" />
								{buttonLabel}
							</>
						)}
					</Button>
					{errorMessage ? (
						<Text className="text-sm text-red-600 dark:text-red-300" role="alert">
							{errorMessage}
						</Text>
					) : null}
				</>
			) : null}
		</div>
	);
}
