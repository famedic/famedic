import { Head, useForm } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";

function formatCountdown(totalSeconds) {
  const safe = Math.max(0, totalSeconds);
  const mins = Math.floor(safe / 60);
  const secs = safe % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

export default function OtpAccess({
  token,
  expiresAt,
  resendAvailableAt,
  maxAttempts = 5,
  attempts = 0,
  alreadyVerified = false,
  pdfBase64 = null,
  maskedPhone = null,
  errorMessage = null,
}) {
  const verifyForm = useForm({
    token: token ?? "",
    code: "",
  });

  const resendForm = useForm({
    token: token ?? "",
  });

  const initialOtpSeconds = useMemo(() => {
    if (!expiresAt) return 0;
    const diff = Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000);
    return Math.max(0, diff);
  }, [expiresAt]);

  const initialResendSeconds = useMemo(() => {
    if (!resendAvailableAt) return 0;
    const diff = Math.floor((new Date(resendAvailableAt).getTime() - Date.now()) / 1000);
    return Math.max(0, diff);
  }, [resendAvailableAt]);

  const [otpSecondsLeft, setOtpSecondsLeft] = useState(initialOtpSeconds);
  const [resendSecondsLeft, setResendSecondsLeft] = useState(initialResendSeconds);

  useEffect(() => setOtpSecondsLeft(initialOtpSeconds), [initialOtpSeconds]);
  useEffect(() => setResendSecondsLeft(initialResendSeconds), [initialResendSeconds]);

  useEffect(() => {
    const timer = setInterval(() => {
      setOtpSecondsLeft((prev) => Math.max(0, prev - 1));
      setResendSecondsLeft((prev) => Math.max(0, prev - 1));
    }, 1000);

    return () => clearInterval(timer);
  }, []);

  const canResend = resendSecondsLeft === 0 && !!token && !alreadyVerified;
  const attemptsLeft = Math.max(0, maxAttempts - attempts);

  const submitVerify = (e) => {
    e.preventDefault();
    verifyForm.post(route("lab-results.verify"));
  };

  const submitResend = () => {
    if (!canResend) return;
    resendForm.post(route("lab-results.resend"));
  };

  return (
    <>
      <Head title="Acceso a resultados de laboratorio" />
      <div className="min-h-screen bg-zinc-50 px-4 py-10 dark:bg-slate-950">
        <div className="mx-auto max-w-5xl rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h1 className="text-2xl font-bold text-zinc-900 dark:text-white">
            Resultados de laboratorio
          </h1>

          {errorMessage && (
            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
              {errorMessage}
            </div>
          )}

          {!errorMessage && !alreadyVerified && (
            <div className="mt-5 grid gap-6 md:grid-cols-2">
              <div className="rounded-lg border border-zinc-200 p-4 dark:border-slate-700">
                <h2 className="font-semibold text-zinc-900 dark:text-white">Validación OTP</h2>
                <p className="mt-2 text-sm text-zinc-600 dark:text-slate-300">
                  Enviamos un código SMS de 6 dígitos a {maskedPhone || "tu teléfono"}.
                </p>

                <div className="mt-3 space-y-1 text-sm">
                  <p className="text-zinc-700 dark:text-slate-300">
                    Expira en: <strong>{formatCountdown(otpSecondsLeft)}</strong>
                  </p>
                  <p className="text-zinc-700 dark:text-slate-300">
                    Intentos restantes: <strong>{attemptsLeft}</strong> de {maxAttempts}
                  </p>
                </div>

                <form className="mt-4 space-y-3" onSubmit={submitVerify}>
                  <input type="hidden" name="token" value={verifyForm.data.token} />
                  <input
                    type="text"
                    inputMode="numeric"
                    maxLength={6}
                    value={verifyForm.data.code}
                    onChange={(e) =>
                      verifyForm.setData("code", e.target.value.replace(/\D/g, "").slice(0, 6))
                    }
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-center text-lg tracking-[0.25em] text-zinc-900 focus:border-blue-500 focus:outline-none dark:border-slate-600 dark:bg-slate-800 dark:text-white"
                    placeholder="000000"
                  />

                  {(verifyForm.errors.otp || verifyForm.errors.code || verifyForm.errors.attempts) && (
                    <div className="rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
                      {verifyForm.errors.otp || verifyForm.errors.code || verifyForm.errors.attempts}
                    </div>
                  )}

                  {resendForm.errors.otp && (
                    <div className="rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
                      {resendForm.errors.otp}
                    </div>
                  )}

                  <button
                    type="submit"
                    disabled={verifyForm.processing || otpSecondsLeft === 0}
                    className="w-full rounded-md bg-blue-600 px-4 py-2 font-medium text-white disabled:cursor-not-allowed disabled:bg-blue-400"
                  >
                    {verifyForm.processing ? "Validando..." : "Validar código"}
                  </button>
                </form>

                <button
                  type="button"
                  onClick={submitResend}
                  disabled={!canResend || resendForm.processing}
                  className="mt-3 w-full rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-600 dark:text-slate-200"
                >
                  {resendForm.processing
                    ? "Reenviando..."
                    : canResend
                    ? "Reenviar código"
                    : `Reenviar en ${formatCountdown(resendSecondsLeft)}`}
                </button>
              </div>

              <div className="rounded-lg border border-zinc-200 p-4 dark:border-slate-700">
                <h3 className="font-semibold text-zinc-900 dark:text-white">Seguridad</h3>
                <ul className="mt-2 list-disc space-y-1 pl-4 text-sm text-zinc-600 dark:text-slate-300">
                  <li>El acceso requiere OTP para cada orden.</li>
                  <li>El código es temporal y se almacena hasheado.</li>
                  <li>Se invalidan códigos previos al generar uno nuevo.</li>
                </ul>
              </div>
            </div>
          )}

          {alreadyVerified && pdfBase64 && (
            <div className="mt-5">
              <p className="mb-3 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-950/30 dark:text-green-300">
                Código verificado correctamente. Mostrando resultados.
              </p>
              <iframe
                title="Resultados de laboratorio"
                src={`data:application/pdf;base64,${pdfBase64}`}
                className="h-[75vh] w-full rounded-md border border-zinc-200 dark:border-slate-700"
              />
            </div>
          )}
        </div>
      </div>
    </>
  );
}
