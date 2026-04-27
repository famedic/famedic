import Card from "@/Components/LaboratoryResults/Card";
import Stepper from "@/Components/LaboratoryResults/Stepper";

function formatCountdown(totalSeconds) {
  const safe = Math.max(0, totalSeconds);
  const mins = Math.floor(safe / 60);
  const secs = safe % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

export default function ResultsDownload({
  secondsLeft,
  onDownload,
  previewUrl = null,
  downloading = false,
}) {
  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold text-white">Resultados de laboratorio</h1>
        <p className="text-slate-300">Accede de forma segura a tus resultados medicos</p>
      </header>

      <Stepper currentStep={3} />

      <Card className="space-y-6">
        <div className="rounded-xl border border-green-500 bg-green-500/10 p-4 text-green-200">
          <p className="font-semibold">Tus resultados estan listos</p>
          <p className="mt-1 text-sm">Puedes descargarlos en formato PDF.</p>
        </div>

        <div className="text-sm text-slate-300">
          Esta pagina expirara en <strong>{formatCountdown(secondsLeft)}</strong> y se volvera
          a solicitar validacion OTP.
        </div>

        <div className="flex justify-center">
          <button
            type="button"
            onClick={onDownload}
            disabled={downloading}
            className="w-full max-w-xl rounded-xl bg-blue-600 px-6 py-4 text-lg font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-600"
          >
            {downloading ? "Iniciando descarga..." : "Descargar resultados (PDF)"}
          </button>
        </div>

        {previewUrl ? (
          <div className="space-y-3">
            <h2 className="text-lg font-semibold text-white">Vista previa de resultados</h2>
            <div className="overflow-hidden rounded-xl border border-slate-600 bg-slate-900">
              <iframe
                title="Vista previa de resultados PDF"
                src={previewUrl}
                className="h-[70vh] min-h-[480px] w-full bg-white"
              />
            </div>
          </div>
        ) : null}

        <p className="text-sm text-slate-400">
          Una vez descargados, los resultados quedan bajo tu responsabilidad. Famedic no se hace
          responsable del uso posterior del archivo.
        </p>
      </Card>
    </div>
  );
}
