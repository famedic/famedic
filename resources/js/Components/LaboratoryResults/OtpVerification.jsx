import Card from "@/Components/LaboratoryResults/Card";
import OtpInput from "@/Components/LaboratoryResults/OtpInput";
import Stepper from "@/Components/LaboratoryResults/Stepper";

function formatCountdown(totalSeconds) {
  const safe = Math.max(0, totalSeconds);
  const mins = Math.floor(safe / 60);
  const secs = safe % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

export default function OtpVerification({
  title,
  subtitle,
  otpSecondsLeft,
  resendSecondsLeft,
  remainingAttempts,
  maxAttempts,
  verifySubmitting,
  verifyDisabled,
  canResend,
  resendProcessing,
  errorMessage,
  otpResetKey,
  onCodeComplete,
  onResend,
}) {
  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold text-white">{title}</h1>
        <p className="text-slate-300">{subtitle}</p>
      </header>

      <Stepper currentStep={2} />

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="relative">
          {verifySubmitting ? (
            <div className="absolute inset-0 z-10 rounded-xl bg-slate-950/45 backdrop-blur-[1px]" />
          ) : null}

          <h2 className="text-xl font-semibold text-white">Codigo de verificacion</h2>
          <p className="mt-2 text-slate-300">
            Escribe el codigo de 6 digitos para liberar tu descarga.
          </p>

          {verifySubmitting ? (
            <div
              className="mt-4 flex items-center gap-3 rounded-lg border border-blue-500/50 bg-blue-500/10 p-3 text-sm text-blue-100"
              role="status"
              aria-live="polite"
            >
              <span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-blue-300 border-t-transparent" />
              Estamos validando tu codigo, espera un momento...
            </div>
          ) : null}

          <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-300">
            <span>
              Tiempo restante: <strong>{formatCountdown(otpSecondsLeft)}</strong>
            </span>
            <span>
              Intentos: <strong>{remainingAttempts}</strong> / {maxAttempts}
            </span>
          </div>

          <div className="mt-6">
            <OtpInput
              disabled={verifyDisabled}
              resetKey={otpResetKey}
              onComplete={onCodeComplete}
            />
          </div>

          <p className="mt-4 text-center text-sm text-slate-400">
            {verifySubmitting ? "Validacion en curso..." : ""}
          </p>

          {errorMessage ? (
            <div className="mt-4 rounded-lg border border-red-700 bg-red-900/30 p-3 text-sm text-red-200">
              Error de verificacion: {errorMessage}
            </div>
          ) : null}

          <button
            type="button"
            onClick={onResend}
            disabled={!canResend || resendProcessing}
            className="mt-6 w-full rounded-xl border border-blue-500 px-4 py-3 text-sm font-semibold text-blue-300 transition hover:bg-blue-500/10 disabled:cursor-not-allowed disabled:border-slate-600 disabled:text-slate-500"
          >
            {resendProcessing
              ? "Reenviando..."
              : canResend
                ? "Reenviar codigo"
                : `Reenviar en ${formatCountdown(resendSecondsLeft)}`}
          </button>
        </Card>

        <Card>
          <h3 className="text-lg font-semibold text-white">Tu informacion esta protegida</h3>
          <ul className="mt-4 space-y-3 text-sm text-slate-300">
            <li>Solo tu puedes ver estos resultados con el codigo.</li>
            <li>El codigo caduca en minutos por seguridad.</li>
            <li>Si no solicitaste acceso, ignora este mensaje.</li>
          </ul>
        </Card>
      </div>
    </div>
  );
}
