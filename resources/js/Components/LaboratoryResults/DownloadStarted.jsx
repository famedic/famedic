import Card from "@/Components/LaboratoryResults/Card";

function formatCountdown(totalSeconds) {
  const safe = Math.max(0, totalSeconds);
  const mins = Math.floor(safe / 60);
  const secs = safe % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

export default function DownloadStarted({ secondsLeft }) {
  return (
    <Card className="space-y-4 text-center">
      <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-500/20 text-2xl text-green-300">
        ✓
      </div>
      <h2 className="text-2xl font-bold text-white">Descarga iniciada</h2>
      <p className="text-slate-300">
        Por seguridad, esta sesion expirara en breve.
      </p>
      <p className="text-sm text-slate-400">
        Tiempo restante: <strong>{formatCountdown(secondsLeft)}</strong>
      </p>
      <a
        href="/"
        className="inline-flex rounded-lg border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-200 transition hover:bg-slate-700"
      >
        Volver al inicio
      </a>
    </Card>
  );
}
