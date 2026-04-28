import {
  ChatBubbleLeftRightIcon,
  ClipboardDocumentCheckIcon,
  KeyIcon,
} from "@heroicons/react/24/outline";

const STEPS = [
  { n: 1, label: "Canal", Icon: ChatBubbleLeftRightIcon },
  { n: 2, label: "Codigo", Icon: KeyIcon },
  { n: 3, label: "Resultados", Icon: ClipboardDocumentCheckIcon },
];

export default function Stepper({ currentStep = 1 }) {
  return (
    <nav aria-label="Progreso de resultados">
      <ol className="flex items-center justify-between gap-2 sm:gap-4">
        {STEPS.map((step) => {
          const done = currentStep > step.n;
          const active = currentStep === step.n;
          const Icon = step.Icon;

          return (
            <li key={step.n} className="flex min-w-0 flex-1 items-center justify-center gap-2 sm:justify-start sm:gap-3">
              <span
                className={`flex h-10 w-10 items-center justify-center rounded-full sm:h-11 sm:w-11 ${
                  done
                    ? "bg-green-600 text-white"
                    : active
                      ? "bg-green-500 text-white ring-2 ring-green-300/60"
                      : "bg-green-900/40 text-green-300 ring-1 ring-green-600/50"
                }`}
                title={step.label}
                aria-label={step.label}
              >
                {done ? "✓" : <Icon className="h-5 w-5" aria-hidden />}
              </span>
              <span
                className={`hidden text-sm font-medium sm:inline ${
                  active ? "text-white" : "text-slate-400"
                }`}
              >
                {step.label}
              </span>
            </li>
          );
        })}
      </ol>

      <div className="mt-4 h-2 w-full overflow-hidden rounded-full bg-slate-700">
        <div
          className="h-full rounded-full bg-green-500 transition-all duration-300"
          style={{ width: `${Math.min(100, ((currentStep - 1) / 2) * 100)}%` }}
        />
      </div>
    </nav>
  );
}
