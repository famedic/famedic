import { ClipboardDocumentCheckIcon } from "@heroicons/react/24/outline";

export default function StudyInfoCard() {
  return (
    <div className="flex min-h-0 flex-col">
      <div className="mb-6 inline-flex size-14 items-center justify-center self-center rounded-2xl bg-blue-500/15 ring-1 ring-blue-400/30 sm:self-start">
        <ClipboardDocumentCheckIcon className="size-8 text-blue-300" aria-hidden />
      </div>

      <h2 className="text-center text-2xl font-bold tracking-tight text-white sm:text-left sm:text-[1.65rem] sm:leading-tight">
        Resultados de laboratorio disponibles
      </h2>

      <p className="mt-4 text-center text-base leading-relaxed text-slate-300 sm:text-left">
        Verifica tu identidad antes de mostrar los resultados.
      </p>

    </div>
  );
}
