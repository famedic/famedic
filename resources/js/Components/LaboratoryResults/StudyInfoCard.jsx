import { ClipboardDocumentCheckIcon } from "@heroicons/react/24/outline";

export default function StudyInfoCard({ patientDisplayName, orderNumber, studyDateLabel }) {
  return (
    <div className="flex min-h-0 flex-col">
      <div className="mb-6 inline-flex size-14 items-center justify-center rounded-2xl bg-blue-500/15 ring-1 ring-blue-400/30">
        <ClipboardDocumentCheckIcon className="size-8 text-blue-300" aria-hidden />
      </div>

      <h2 className="text-2xl font-bold tracking-tight text-white sm:text-[1.65rem] sm:leading-tight">
        Tus resultados de laboratorio ya están disponibles
      </h2>

      <p className="mt-4 text-base leading-relaxed text-slate-300">
        Para proteger tu información, necesitamos verificar tu identidad antes de mostrar los resultados.
      </p>

      {patientDisplayName ? (
        <p className="mt-3 text-sm text-slate-400">
          Paciente: <span className="font-medium text-slate-200">{patientDisplayName}</span>
        </p>
      ) : null}

      <div className="mt-6 rounded-2xl border border-slate-600/70 bg-slate-950/40 p-5 shadow-inner shadow-black/20 backdrop-blur-sm">
        <dl className="space-y-4 text-sm">
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Número de orden</dt>
            <dd className="mt-1 break-all font-mono text-base text-white">{orderNumber || "—"}</dd>
          </div>
          {studyDateLabel ? (
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Fecha del estudio</dt>
              <dd className="mt-1 text-base text-slate-100">{studyDateLabel}</dd>
            </div>
          ) : null}
        </dl>
      </div>

      <p className="mt-6 text-sm leading-relaxed text-slate-500">
        Si no solicitaste esta consulta, puedes ignorar este mensaje.
      </p>
    </div>
  );
}
