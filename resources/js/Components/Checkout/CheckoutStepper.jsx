import clsx from "clsx";
import { CheckIcon } from "@heroicons/react/24/solid";

export default function CheckoutStepper({ steps, currentStep }) {
    return (
        <nav aria-label="Progreso del checkout" className="mb-8">
            <ol className="flex items-center justify-between">
                {steps.map((step, index) => {
                    const isCompleted = index < currentStep;
                    const isCurrent = index === currentStep;
                    const isLast = index === steps.length - 1;

                    return (
                        <li
                            key={step.id}
                            className={clsx(
                                "flex flex-1 items-center",
                                !isLast &&
                                    "after:mx-2 after:h-0.5 after:flex-1 after:content-[''] sm:after:mx-4",
                                !isLast &&
                                    (isCompleted
                                        ? "after:bg-famedic-dark dark:after:bg-famedic-lime"
                                        : "after:bg-zinc-200 dark:after:bg-slate-700"),
                            )}
                        >
                            <div className="flex flex-col items-center gap-1.5 sm:flex-row sm:gap-2">
                                <div
                                    className={clsx(
                                        "flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold transition-colors sm:size-9",
                                        isCompleted &&
                                            "bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker",
                                        isCurrent &&
                                            !isCompleted &&
                                            "bg-famedic-dark text-white ring-4 ring-famedic-dark/20 dark:bg-famedic-lime dark:text-famedic-darker dark:ring-famedic-lime/20",
                                        !isCompleted &&
                                            !isCurrent &&
                                            "bg-zinc-100 text-zinc-500 dark:bg-slate-800 dark:text-slate-400",
                                    )}
                                >
                                    {isCompleted ? (
                                        <CheckIcon
                                            className="size-4 sm:size-5"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        step.number
                                    )}
                                </div>
                                <span
                                    className={clsx(
                                        "hidden text-xs font-medium sm:block sm:text-sm",
                                        isCurrent
                                            ? "text-famedic-dark dark:text-famedic-lime"
                                            : isCompleted
                                              ? "text-zinc-700 dark:text-slate-300"
                                              : "text-zinc-400 dark:text-slate-500",
                                    )}
                                >
                                    {step.label}
                                </span>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
