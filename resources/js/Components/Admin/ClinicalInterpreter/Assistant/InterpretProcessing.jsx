import { useEffect, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import DocumentThumbnail from "./DocumentThumbnail";
import { ESTIMATED_PROCESS_MS, PROCESSING_STEPS } from "./interpretApi";

function formatEta(ms) {
	if (ms <= 0) return "Casi listo…";
	const seconds = Math.max(1, Math.ceil(ms / 1000));
	return seconds === 1 ? "Queda ~1 s" : `Quedan ~${seconds} s`;
}

/**
 * FASE 2 refinements — illuminated steps, ETA, thumbnail, human errors.
 */
export default function InterpretProcessing({
	activeIndex = 0,
	error = null,
	previewUrl = null,
	fileName = null,
	startedAt = null,
	onRetry,
	onChangeFile,
}) {
	const [now, setNow] = useState(() => Date.now());

	useEffect(() => {
		if (error || !startedAt) return undefined;
		const id = setInterval(() => setNow(Date.now()), 500);
		return () => clearInterval(id);
	}, [error, startedAt]);

	const stepProgress = Math.min(
		1,
		(Math.max(0, activeIndex) + (error ? 0 : 0.35)) / PROCESSING_STEPS.length,
	);
	const elapsed = startedAt ? Math.max(0, now - startedAt) : 0;
	const timeProgress = Math.min(1, elapsed / ESTIMATED_PROCESS_MS);
	const blended = Math.max(stepProgress, timeProgress * 0.85);
	const remainingMs = error
		? 0
		: Math.max(0, ESTIMATED_PROCESS_MS * (1 - blended));

	if (error) {
		return (
			<div className="mx-auto max-w-md space-y-8 py-2">
				{previewUrl && (
					<div className="flex justify-center">
						<DocumentThumbnail
							previewUrl={previewUrl}
							fileName={fileName}
							size="sm"
						/>
					</div>
				)}

				<div className="space-y-4 text-center">
					<div className="mx-auto flex size-14 items-center justify-center rounded-full bg-amber-50 text-amber-700 ring-1 ring-amber-200/80 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-800/60">
						<svg
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							strokeWidth="1.75"
							className="size-7"
							aria-hidden
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
							/>
						</svg>
					</div>
					<div className="space-y-2">
						<h3 className="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
							No pudimos completar la interpretación
						</h3>
						<p className="mx-auto max-w-sm text-sm leading-relaxed text-zinc-500">
							{error}
						</p>
					</div>
				</div>

				<div className="flex flex-wrap justify-center gap-2.5">
					{onRetry && (
						<Button onClick={onRetry}>Intentar nuevamente</Button>
					)}
					{onChangeFile && (
						<Button outline onClick={onChangeFile}>
							Cambiar receta
						</Button>
					)}
				</div>
			</div>
		);
	}

	return (
		<div className="mx-auto max-w-md space-y-7 py-2">
			{previewUrl && (
				<div className="flex justify-center">
					<DocumentThumbnail
						previewUrl={previewUrl}
						fileName={fileName}
						size="sm"
					/>
				</div>
			)}

			<div className="space-y-1.5 text-center">
				<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					Analizando tu orden…
				</p>
				<p className="text-xs tabular-nums text-zinc-400">
					{formatEta(remainingMs)}
				</p>
			</div>

			<ol className="space-y-2.5">
				{PROCESSING_STEPS.map((step, index) => {
					const done = index < activeIndex;
					const current = index === activeIndex;
					const upcoming = index > activeIndex;

					return (
						<li
							key={step.id}
							className={`interpret-step flex items-center gap-3 rounded-xl border px-4 py-3 transition-all duration-500 ease-out ${
								current
									? "interpret-step-current border-famedic-light/50 bg-famedic-light/10 shadow-[0_0_0_1px_rgba(56,189,248,0.12)] dark:border-famedic-light/40 dark:bg-famedic-light/10"
									: done
										? "interpret-step-done border-emerald-200/70 bg-emerald-50/70 dark:border-emerald-800/50 dark:bg-emerald-950/30"
										: "border-transparent bg-transparent opacity-40"
							}`}
							style={
								upcoming
									? undefined
									: { transitionDelay: `${Math.min(index, 4) * 40}ms` }
							}
						>
							<span
								className={`relative flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-all duration-500 ${
									done
										? "bg-emerald-600 text-white shadow-sm shadow-emerald-600/25"
										: current
											? "bg-famedic-dark text-white ring-4 ring-famedic-light/25 dark:bg-famedic-light dark:text-zinc-950 dark:ring-famedic-light/20"
											: "bg-zinc-100 text-zinc-400 dark:bg-zinc-800"
								}`}
							>
								{done ? (
									<svg
										viewBox="0 0 16 16"
										fill="currentColor"
										className="size-3.5 animate-[interpretCheck_320ms_ease-out]"
										aria-hidden
									>
										<path
											fillRule="evenodd"
											d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z"
											clipRule="evenodd"
										/>
									</svg>
								) : current ? (
									<span className="size-1.5 animate-pulse rounded-full bg-white dark:bg-zinc-950" />
								) : (
									index + 1
								)}
							</span>
							<span
								className={`text-sm transition-colors duration-300 ${
									current
										? "font-semibold text-zinc-900 dark:text-zinc-50"
										: done
											? "font-medium text-emerald-800 dark:text-emerald-300"
											: "text-zinc-400"
								}`}
							>
								{step.label}
							</span>
							{done && (
								<span className="ml-auto text-[10px] font-medium uppercase tracking-wide text-emerald-600/80 dark:text-emerald-400/80">
									Listo
								</span>
							)}
							{current && (
								<span className="ml-auto text-[10px] font-medium uppercase tracking-wide text-famedic-dark/70 dark:text-famedic-light/80">
									En curso
								</span>
							)}
						</li>
					);
				})}
			</ol>

			<div className="space-y-1.5">
				<div className="h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
					<div
						className="h-full rounded-full bg-famedic-light transition-[width] duration-700 ease-out"
						style={{ width: `${Math.round(blended * 100)}%` }}
					/>
				</div>
				<p className="text-center text-[11px] text-zinc-400">
					Paso {Math.min(activeIndex + 1, PROCESSING_STEPS.length)} de{" "}
					{PROCESSING_STEPS.length}
				</p>
			</div>

			<style>{`
				@keyframes interpretCheck {
					from { opacity: 0; transform: scale(0.6); }
					to { opacity: 1; transform: scale(1); }
				}
				@media (prefers-reduced-motion: reduce) {
					.interpret-step,
					.interpret-step svg {
						animation: none !important;
						transition: none !important;
					}
				}
			`}</style>
		</div>
	);
}
