import ApplicationLogo from "@/Components/ApplicationLogo";
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
      <Head title="Resultados de laboratorio | Famedic" />

      {/* HEADER */}
      <div className="border-b bg-white dark:bg-slate-900 dark:border-slate-800">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
          <a href="/" className="flex items-center gap-2" aria-label="Famedic — inicio">
            <ApplicationLogo className="h-8 w-auto sm:h-9" />
            <span className="text-xl font-bold text-zinc-900 dark:text-white">Famedic</span>
          </a>

          <a
            href="/dashboard"
            className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
          >
            Ir a mi cuenta
          </a>
        </div>
      </div>

      {/* MAIN */}
      <div className="min-h-screen bg-zinc-50 px-4 py-10 dark:bg-slate-950">
        <div className="mx-auto max-w-5xl space-y-6">

          {/* TITLE */}
          <div>
            <h1 className="text-2xl font-bold text-zinc-900 dark:text-white">
              Resultados de laboratorio
            </h1>
            <p className="text-sm text-zinc-600 dark:text-slate-400">
              Accede de forma segura a tus resultados médicos
            </p>
          </div>

          {/* ERROR */}
          {errorMessage && (
            <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
              {errorMessage}
            </div>
          )}

          {/* OTP */}
          {!errorMessage && !alreadyVerified && (
            <div className="grid gap-6 md:grid-cols-2">

              {/* OTP CARD */}
              <div className="rounded-xl border bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 className="text-lg font-semibold text-zinc-900 dark:text-white">
                  Verificación de seguridad
                </h2>

                <p className="mt-2 text-sm text-zinc-600 dark:text-slate-300">
                  Enviamos un código SMS a{" "}
                  <strong>{maskedPhone || "tu teléfono"}</strong>
                </p>

                <div className="mt-4 flex justify-between text-sm text-zinc-600 dark:text-slate-300">
                  <span>Expira en: <strong>{formatCountdown(otpSecondsLeft)}</strong></span>
                  <span>Intentos: <strong>{attemptsLeft}/{maxAttempts}</strong></span>
                </div>

                <form className="mt-5 space-y-4" onSubmit={submitVerify}>
                  <input type="hidden" name="token" value={verifyForm.data.token} />

                  <input
                    type="text"
                    inputMode="numeric"
                    maxLength={6}
                    value={verifyForm.data.code}
                    onChange={(e) =>
                      verifyForm.setData("code", e.target.value.replace(/\D/g, "").slice(0, 6))
                    }
                    className="w-full rounded-lg border border-zinc-300 px-4 py-3 text-center text-2xl tracking-[0.4em] focus:border-blue-500 focus:outline-none dark:border-slate-600 dark:bg-slate-800 dark:text-white"
                    placeholder="000000"
                  />

                  <button
                    type="submit"
                    disabled={verifyForm.processing || otpSecondsLeft === 0}
                    className="w-full rounded-lg bg-blue-600 py-3 font-medium text-white hover:bg-blue-700 disabled:bg-blue-400"
                  >
                    {verifyForm.processing ? "Validando..." : "Validar código"}
                  </button>
                </form>

                {/* RESEND */}
                <button
                  type="button"
                  onClick={submitResend}
                  disabled={!canResend || resendForm.processing}
                  className="mt-4 w-full text-sm text-blue-600 hover:underline disabled:opacity-50"
                >
                  {resendForm.processing
                    ? "Reenviando..."
                    : canResend
                    ? "Reenviar código"
                    : `Reenviar en ${formatCountdown(resendSecondsLeft)}`}
                </button>
              </div>

              {/* INFO */}
              <div className="rounded-xl border bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h3 className="font-semibold text-zinc-900 dark:text-white">
                  🔒 Seguridad de tu información
                </h3>

                <ul className="mt-3 space-y-2 text-sm text-zinc-600 dark:text-slate-300">
                  <li>• Acceso protegido con verificación SMS</li>
                  <li>• Código temporal y seguro</li>
                  <li>• Protección de datos médicos sensibles</li>
                </ul>
              </div>
            </div>
          )}

          {/* PDF */}
          {alreadyVerified && pdfBase64 && (
            <div className="rounded-xl border bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
              <div className="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                ✅ Verificación exitosa. Aquí están tus resultados.
              </div>

              <iframe
                title="Resultados"
                src={`data:application/pdf;base64,${pdfBase64}`}
                className="h-[75vh] w-full rounded-lg border"
              />
            </div>
          )}
        </div>
      </div>
    </>
  );
}
