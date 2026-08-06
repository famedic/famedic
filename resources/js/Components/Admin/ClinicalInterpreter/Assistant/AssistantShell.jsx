import { Link } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import {
	ChevronLeftIcon,
	ChevronRightIcon,
	CodeBracketIcon,
	SparklesIcon,
} from "@heroicons/react/16/solid";
import { PRODUCT_SCOPE, WIZARD_STAGES } from "../productScope";
import StageStepper from "./StageStepper";
import ProcessContextCard from "./ProcessContextCard";

/**
 * Wizard shell — FASE 1 refinements (hero, progress, natural copy).
 */
export default function AssistantShell({
	stageId = "interpret",
	completedStageIds = [],
	onStageChange,
	onBack,
	onContinue,
	continueLabel = "Continuar",
	continueDisabled = false,
	showContinue = true,
	showBack = true,
	onOpenAiDetails,
	children,
}) {
	const stage = WIZARD_STAGES.find((s) => s.id === stageId) || WIZARD_STAGES[0];
	const stageIndex = WIZARD_STAGES.findIndex((s) => s.id === stageId);

	return (
		<div className="relative mx-auto flex min-h-[calc(100dvh-7rem)] max-w-3xl flex-col lg:max-w-4xl">
			<header className="shrink-0 space-y-5 pb-2 pt-1">
				<div className="flex items-center justify-between gap-3">
					<nav
						aria-label="Breadcrumb"
						className="flex min-w-0 items-center gap-1.5 text-[11px] uppercase tracking-[0.14em]"
					>
						<Link
							href={route("admin.clinical-interpreter.index")}
							className="truncate font-medium text-zinc-400 transition hover:text-famedic-light"
						>
							{PRODUCT_SCOPE.productName}
						</Link>
						<span className="text-zinc-300 dark:text-zinc-600" aria-hidden>
							/
						</span>
						<span className="font-semibold text-zinc-600 dark:text-zinc-300">
							Asistente
						</span>
					</nav>

					{onOpenAiDetails && (
						<button
							type="button"
							onClick={onOpenAiDetails}
							title="Herramienta de soporte: OCR, JSON y métricas"
							className="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-[11px] font-medium text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
						>
							<CodeBracketIcon className="size-3.5 opacity-70" aria-hidden />
							<span className="hidden sm:inline">Detalles IA</span>
							<span className="sm:hidden">IA</span>
						</button>
					)}
				</div>

				{/* Discrete product hero */}
				<section className="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white px-5 py-5 dark:border-zinc-700 dark:bg-zinc-900 sm:px-6 sm:py-6">
					<div
						aria-hidden
						className="pointer-events-none absolute -right-8 -top-10 size-40 rounded-full bg-famedic-light/10 blur-2xl dark:bg-famedic-light/15"
					/>
					<div className="relative flex gap-3 sm:gap-4">
						<span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-famedic-light/15 text-famedic-dark dark:bg-famedic-light/20 dark:text-famedic-light">
							<SparklesIcon className="size-5" aria-hidden />
						</span>
						<div className="min-w-0 space-y-1.5">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Producto IA
							</p>
							<h1 className="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50 sm:text-xl">
								{PRODUCT_SCOPE.productName}
							</h1>
							<p className="max-w-xl text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
								Transforma una receta médica en una orden de laboratorio lista
								para cotizar.
							</p>
						</div>
					</div>
				</section>

				<ProcessContextCard />

				<div className="rounded-2xl border border-zinc-200/70 bg-white px-3 py-4 dark:border-zinc-700/80 dark:bg-zinc-900/80 sm:px-6 sm:py-5">
					<StageStepper
						currentStageId={stageId}
						completedStageIds={completedStageIds}
						onStageSelect={onStageChange}
					/>
				</div>
			</header>

			<main className="flex min-h-0 flex-1 flex-col px-0 pb-28 pt-6 sm:pt-8">
				<div
					key={stageId}
					className="assistant-stage-panel flex flex-1 flex-col rounded-2xl border border-zinc-200/80 bg-white px-5 py-6 dark:border-zinc-700 dark:bg-zinc-900 sm:px-8 sm:py-8"
				>
					<div className="mb-8 space-y-2">
						<p className="text-[11px] font-medium uppercase tracking-[0.16em] text-zinc-400">
							{stage.label}
						</p>
						<h2 className="max-w-xl text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50 sm:text-[1.75rem] sm:leading-snug">
							{stage.description}
						</h2>
					</div>

					<div className="flex-1">{children}</div>
				</div>
			</main>

			<footer className="pointer-events-none fixed inset-x-0 bottom-0 z-20">
				<div className="pointer-events-auto border-t border-zinc-200/80 bg-white/90 px-4 py-3 backdrop-blur-md dark:border-zinc-700/80 dark:bg-zinc-950/85 sm:px-6">
					<div className="mx-auto flex max-w-3xl items-center justify-between gap-3 lg:max-w-4xl">
						<div>
							{showBack && stageIndex > 0 ? (
								<Button outline onClick={onBack} className="!text-sm">
									<ChevronLeftIcon data-slot="icon" />
									Atrás
								</Button>
							) : (
								<Button
									plain
									href={route("admin.clinical-interpreter.index")}
									className="!text-sm text-zinc-500"
								>
									Cancelar interpretación
								</Button>
							)}
						</div>
						{showContinue && onContinue && (
							<Button
								disabled={continueDisabled}
								onClick={onContinue}
								className="!text-sm"
							>
								{continueLabel}
								<ChevronRightIcon data-slot="icon" />
							</Button>
						)}
					</div>
				</div>
			</footer>

			<style>{`
				.assistant-stage-panel {
					animation: assistantFadeSlide 320ms cubic-bezier(0.22, 1, 0.36, 1);
				}
				@keyframes assistantFadeSlide {
					from {
						opacity: 0;
						transform: translateY(10px);
					}
					to {
						opacity: 1;
						transform: translateY(0);
					}
				}
				@media (prefers-reduced-motion: reduce) {
					.assistant-stage-panel {
						animation: none;
					}
				}
			`}</style>
		</div>
	);
}
