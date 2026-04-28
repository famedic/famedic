import Card from "@/Components/LaboratoryResults/Card";
import LabResultsVerificationLayout from "@/Components/LaboratoryResults/LabResultsVerificationLayout";
import OtpInput from "@/Components/LaboratoryResults/OtpInput";
import SecurityNotice from "@/Components/LaboratoryResults/SecurityNotice";

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
  otpChannel = null,
  maskedPhone = null,
  maskedEmail = null,
  canUseSms = false,
  canUseEmail = false,
  onSwitchChannel,
  patientDisplayName,
  orderNumber,
  studyDateLabel,
  otpExpiryMinutes = 10,
}) {
  const deliveryHint =
    otpChannel === "sms"
      ? `Código enviado por SMS${maskedPhone ? ` a ${maskedPhone}` : ""}.`
      : otpChannel === "email"
        ? `Código enviado al correo${maskedEmail ? ` ${maskedEmail}` : ""}.`
        : null;

  const alternateChannel = otpChannel === "sms" && canUseEmail ? "email" : otpChannel === "email" && canUseSms ? "sms" : null;
  const alternateLabel =
    alternateChannel === "email"
      ? "Enviar código por correo electrónico"
      : alternateChannel === "sms"
        ? "Enviar código por SMS"
        : null;
  const alternateHint =
    alternateChannel === "email" && maskedEmail
      ? maskedEmail
      : alternateChannel === "sms" && maskedPhone
        ? maskedPhone
        : null;

  const useStudyLayout = Boolean(orderNumber);

  const codePanel = (
    <Card className="relative border-slate-600/70 bg-slate-900/50 shadow-lg shadow-black/20">
      {verifySubmitting ? (
        <div className="absolute inset-0 z-10 rounded-xl bg-slate-950/50 backdrop-blur-[2px]" />
      ) : null}

      <p className="text-center text-xs font-semibold uppercase tracking-wide text-blue-300/90">{title}</p>
      <h2 className="mt-1 text-center text-xl font-bold text-white sm:text-2xl">Código de verificación</h2>
      <p className="mt-2 text-center text-sm leading-relaxed text-slate-400">{subtitle}</p>

      {deliveryHint ? (
        <p className="mt-4 rounded-xl border border-slate-600/80 bg-slate-950/40 px-3 py-2.5 text-sm text-slate-200">
          {deliveryHint}
        </p>
      ) : null}

      {verifySubmitting ? (
        <div
          className="mt-4 flex items-center gap-3 rounded-xl border border-blue-500/40 bg-blue-500/10 p-3 text-sm text-blue-100"
          role="status"
          aria-live="polite"
        >
          <span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-blue-300 border-t-transparent" />
          Estamos validando tu código…
        </div>
      ) : null}

      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-400">
        <span>
          Tiempo: <strong className="text-slate-200">{formatCountdown(otpSecondsLeft)}</strong>
        </span>
        <span>
          Intentos: <strong className="text-slate-200">{remainingAttempts}</strong> / {maxAttempts}
        </span>
      </div>

      <div className="mt-6">
        <OtpInput disabled={verifyDisabled} resetKey={otpResetKey} onComplete={onCodeComplete} />
      </div>

      <p className="mt-4 text-center text-sm text-slate-500">{verifySubmitting ? "Validación en curso…" : ""}</p>

      {errorMessage ? (
        <div className="mt-4 rounded-xl border border-red-600/50 bg-red-950/40 p-3 text-sm text-red-200">
          {errorMessage}
        </div>
      ) : null}

      <div className="mt-5 space-y-3">
        <p className="text-center text-xs font-medium text-slate-500">¿No recibiste el código?</p>
        <button
          type="button"
          onClick={onResend}
          disabled={!canResend || resendProcessing}
          className="w-full rounded-xl border border-blue-500/60 bg-blue-500/5 px-4 py-3 text-sm font-semibold text-blue-200 transition hover:bg-blue-500/15 disabled:cursor-not-allowed disabled:border-slate-600 disabled:text-slate-500"
        >
          {resendProcessing
            ? "Reenviando…"
            : canResend
              ? "Reenviar código"
              : `Reenviar en ${formatCountdown(resendSecondsLeft)}`}
        </button>
      </div>

      {alternateChannel && typeof onSwitchChannel === "function" ? (
        <div className="mt-6 border-t border-slate-600/60 pt-6">
          <p className="text-center text-sm font-medium text-slate-200">¿No tienes acceso a este canal?</p>
          <p className="mt-1 text-center text-xs text-slate-500">
            Pide un código nuevo por el otro medio. El código anterior dejará de ser válido.
          </p>
          <button
            type="button"
            onClick={() => onSwitchChannel(alternateChannel)}
            disabled={resendProcessing}
            className="mt-3 w-full rounded-xl border border-slate-500/80 bg-slate-800/60 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700/80 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {alternateLabel}
            {alternateHint ? (
              <span className="mt-1 block break-all text-xs font-normal text-slate-400">{alternateHint}</span>
            ) : null}
          </button>
        </div>
      ) : null}
    </Card>
  );

  if (useStudyLayout) {
    return (
      <div className="space-y-6">
        <LabResultsVerificationLayout
          patientDisplayName={patientDisplayName}
          orderNumber={orderNumber}
          studyDateLabel={studyDateLabel}
        >
          <div className="flex flex-col gap-5">
            <SecurityNotice otpExpiryMinutes={otpExpiryMinutes} />
            {codePanel}
          </div>
        </LabResultsVerificationLayout>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold text-white">{title}</h1>
        <p className="text-slate-300">{subtitle}</p>
      </header>
      <div className="grid gap-6 lg:grid-cols-2">
        {codePanel}
        <Card>
          <h3 className="text-lg font-semibold text-white">Tu información está protegida</h3>
          <ul className="mt-4 space-y-3 text-sm text-slate-300">
            <li>Solo tú puedes ver estos resultados con el código.</li>
            <li>El código caduca en minutos por seguridad.</li>
            <li>Si no solicitaste acceso, ignora este mensaje.</li>
          </ul>
        </Card>
      </div>
    </div>
  );
}
