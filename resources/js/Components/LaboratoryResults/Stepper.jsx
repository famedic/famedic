const STEPS = [
  { n: 1, label: "Canal" },
  { n: 2, label: "Codigo" },
  { n: 3, label: "Resultados" },
];

export default function Stepper({ currentStep = 1 }) {
  return (
    <nav aria-label="Progreso de resultados">
      <ol className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        {STEPS.map((step) => {
          const done = currentStep > step.n;
          const active = currentStep === step.n;

          return (
            <li key={step.n} className="flex flex-1 items-center gap-3">
              <span
                className={`flex h-11 w-11 items-center justify-center rounded-full text-sm font-semibold ${
                  done
                    ? "bg-green-600 text-white"
                    : active
                      ? "bg-blue-600 text-white ring-2 ring-blue-400/50"
                      : "bg-slate-700 text-slate-300"
                }`}
              >
                {done ? "✓" : step.n}
              </span>
              <span
                className={`text-sm font-medium ${
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
          className="h-full rounded-full bg-blue-600 transition-all duration-300"
          style={{ width: `${Math.min(100, ((currentStep - 1) / 2) * 100)}%` }}
        />
      </div>
    </nav>
  );
}
