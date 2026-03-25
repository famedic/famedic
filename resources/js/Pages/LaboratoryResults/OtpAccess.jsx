import ApplicationLogo from "@/Components/ApplicationLogo";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

const LEGAL_NOTICE =
  "Una vez descargados, los resultados quedan bajo tu responsabilidad. Famedic no se hace responsable del uso posterior del archivo.";

function formatCountdown(totalSeconds) {
  const safe = Math.max(0, totalSeconds);
  const mins = Math.floor(safe / 60);
  const secs = safe % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

function formatDuration(totalSeconds) {
  const safe = Math.max(0, totalSeconds);
  const hours = Math.floor(safe / 3600);
  const minutes = Math.floor((safe % 3600) / 60);

  if (hours > 0) {
    return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}`;
  }

  return `${String(minutes).padStart(2, "0")} min`;
}

function StepIndicator({ currentStep }) {
  const steps = [
    { n: 1, label: "Canal" },
    { n: 2, label: "Código" },
    { n: 3, label: "Resultados" },
  ];

  return (
    <nav className="mb-8" aria-label="Pasos para ver tus resultados">
      <ol className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        {steps.map((s) => {
          const active = currentStep === s.n;
          const done = currentStep > s.n;
          return (
            <li key={s.n} className="flex flex-1 items-center gap-3">
              <span
                className={`flex h-12 min-h-[48px] min-w-[48px] shrink-0 items-center justify-center rounded-full text-lg font-semibold ${
                  done
                    ? "bg-green-600 text-white"
                    : active
                      ? "bg-blue-600 text-white ring-2 ring-blue-300"
                      : "bg-zinc-200 text-zinc-600 dark:bg-slate-700 dark:text-slate-300"
                }`}
                aria-current={active ? "step" : undefined}
              >
                {done ? "✓" : s.n}
              </span>
              <span
                className={`text-base font-medium sm:text-lg ${
                  active ? "text-zinc-900 dark:text-white" : "text-zinc-500 dark:text-slate-400"
                }`}
              >
                {s.label}
              </span>
            </li>
          );
        })}
      </ol>
      <div className="mt-4 h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-slate-700">
        <div
          className="h-full rounded-full bg-blue-600 transition-all duration-300"
          style={{ width: `${Math.min(100, ((currentStep - 1) / 2) * 100)}%` }}
          role="progressbar"
          aria-valuenow={currentStep}
          aria-valuemin={1}
          aria-valuemax={3}
        />
      </div>
    </nav>
  );
}

export default function OtpAccess({
  token,
  currentStep = 1,
  otpSent = false,
  otpChannel = null,
  expiresAt,
  resendAvailableAt,
  maxAttempts = 5,
  attempts = 0,
  remainingAttempts = 5,
  alreadyVerified = false,
  pdfUrl = null,
  pdfDownloadUrl = null,
  maskedPhone = null,
  isSharedView = false,
  sharedByName = null,
  maskedEmail = null,
  phoneLast4 = null,
  availabilityHours = 24,
  resendSeconds = 30,
  shareUrl = null,
  canUseSms = true,
  canUseEmail = true,
  errorMessage = null,
}) {
  const { errors } = usePage().props;
  const pageErrors = errors ?? {};

  const sendForm = useForm({
    token: token ?? "",
    channel: "",
  });

  const resendForm = useForm({
    token: token ?? "",
    channel: otpChannel ?? "",
  });

  const [verifySubmitting, setVerifySubmitting] = useState(false);

  const [digits, setDigits] = useState(["", "", "", "", "", ""]);
  const inputsRef = useRef([]);
  const autoSubmitLock = useRef(false);
  /** Tras un error del servidor, no reenviar hasta que el usuario vuelva a escribir (evita bucles). */
  const digitsTouchedAfterErrorRef = useRef(true);

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

  useEffect(() => {
    setDigits(["", "", "", "", "", ""]);
    autoSubmitLock.current = false;
    if (otpSent && inputsRef.current[0]) {
      inputsRef.current[0].focus();
    }
  }, [otpSent, otpChannel, expiresAt]);

  useEffect(() => {
    if (otpChannel) {
      resendForm.setData("channel", otpChannel);
    }
  }, [otpChannel]);

  const canResend = resendSecondsLeft === 0 && !!token && !alreadyVerified && otpSent && !isSharedView;
  const otpExpired = otpSent && otpSecondsLeft === 0;
  const noAttemptsLeft = remainingAttempts <= 0 && otpSent && !alreadyVerified && !isSharedView;
  const verifyDisabled =
    verifySubmitting || otpExpired || noAttemptsLeft || !otpSent;

  const channelSelected = sendForm.data.channel === "sms" || sendForm.data.channel === "email";
  const canPickSms = canUseSms;
  const canPickEmail = canUseEmail;

  const setDigitAt = useCallback(
    (index, value) => {
      digitsTouchedAfterErrorRef.current = true;
      const v = value.replace(/\D/g, "").slice(-1);
      setDigits((prev) => {
        const next = [...prev];
        next[index] = v;
        return next;
      });
      if (v && index < 5) {
        inputsRef.current[index + 1]?.focus();
      }
    },
    []
  );

  const handleDigitKeyDown = useCallback((index, e) => {
    if (e.key === "Backspace" && !digits[index] && index > 0) {
      inputsRef.current[index - 1]?.focus();
    }
    if (e.key === "ArrowLeft" && index > 0) {
      e.preventDefault();
      inputsRef.current[index - 1]?.focus();
    }
    if (e.key === "ArrowRight" && index < 5) {
      e.preventDefault();
      inputsRef.current[index + 1]?.focus();
    }
  }, [digits]);

  const codeJoined = digits.join("");

  useEffect(() => {
    if (pageErrors.otp) {
      digitsTouchedAfterErrorRef.current = false;
      setDigits(["", "", "", "", "", ""]);
      autoSubmitLock.current = false;
    } else {
      digitsTouchedAfterErrorRef.current = true;
    }
  }, [pageErrors.otp]);

  useEffect(() => {
    if (pageErrors.otp && !digitsTouchedAfterErrorRef.current) return;
    if (codeJoined.length !== 6 || verifyDisabled || autoSubmitLock.current || !token) return;
    autoSubmitLock.current = true;
    router.post(
      route("lab-results.verify"),
      { token, code: codeJoined },
      {
        preserveScroll: true,
        onStart: () => setVerifySubmitting(true),
        onFinish: () => {
          setVerifySubmitting(false);
          autoSubmitLock.current = false;
        },
      }
    );
  }, [pageErrors.otp, codeJoined, verifyDisabled, token]);

  const submitSendOtp = (e) => {
    e.preventDefault();
    if (!channelSelected) return;
    sendForm.post(route("lab-results.send-otp"));
  };

  const submitResend = () => {
    if (!canResend || !otpChannel || !token) return;
    resendForm.setData("token", token);
    resendForm.setData("channel", otpChannel);
    resendForm.post(route("lab-results.resend"));
  };

  const copyShareLink = async () => {
    const url = shareUrl || window.location.href;
    try {
      await navigator.clipboard.writeText(url);
    } catch {
      window.prompt("Copia este enlace:", url);
    }
  };

  const shareResults = async () => {
    const url = shareUrl || window.location.href;
    if (navigator.share) {
      try {
        await navigator.share({
          title: "Resultados de laboratorio — Famedic",
          text: "Enlace para acceder a mis resultados",
          url,
        });
      } catch {
        copyShareLink();
      }
    } else {
      copyShareLink();
    }
  };

  const showChannelStep = !errorMessage && !alreadyVerified && !otpSent && !isSharedView;
  const showOtpStep = !errorMessage && !alreadyVerified && otpSent && !isSharedView;
  const showStepIndicator = !errorMessage && !isSharedView;
  const showResults = !errorMessage && (alreadyVerified || isSharedView) && pdfUrl;

  return (
    <>
      <Head title="Resultados de laboratorio | Famedic" />

      <div className="border-b bg-white dark:bg-slate-900 dark:border-slate-800">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-4">
          <a href="/" className="flex items-center gap-2" aria-label="Famedic — inicio">
            <ApplicationLogo className="h-9 w-auto sm:h-10" />
            <span className="text-xl font-bold text-zinc-900 dark:text-white sm:text-2xl">Famedic</span>
          </a>

          <a
            href="/dashboard"
            className="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-lg bg-blue-600 px-5 py-3 text-base font-semibold text-white hover:bg-blue-700"
          >
            Ir a mi cuenta
          </a>
        </div>
      </div>

      <div className="min-h-screen bg-zinc-50 px-4 py-8 dark:bg-slate-950 sm:py-10">
        <div className="mx-auto max-w-5xl space-y-6">
          <header>
            <h1 className="text-2xl font-bold text-zinc-900 dark:text-white sm:text-3xl">
              {isSharedView
                ? `${sharedByName || 'Alguien'} te compartió estos resultados de laboratorio`
                : 'Resultados de laboratorio'}
            </h1>
            <p className="mt-2 text-base text-zinc-600 dark:text-slate-400 sm:text-lg">
              {isSharedView
                ? 'Estos resultados son solo para visualización.'
                : 'Accede de forma segura a tus resultados médicos.'}
            </p>
            <p className="mt-3 text-base text-zinc-700 dark:text-slate-300 sm:text-lg">
              {expiresAt
                ? `Disponibles por: ${formatDuration(otpSecondsLeft)} Hrs`
                : `Tus resultados estarán disponibles durante ${availabilityHours} horas después de validar el código.`}
            </p>
          </header>

          {showStepIndicator && <StepIndicator currentStep={currentStep} />}

          {errorMessage && (
            <div
              className="rounded-xl border border-red-200 bg-red-50 p-5 text-base text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200"
              role="alert"
            >
              {errorMessage}
            </div>
          )}

          {pageErrors.channel && (
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-base text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
              {pageErrors.channel}
            </div>
          )}

          {pageErrors.otp && (
            <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-base text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
              {pageErrors.otp}
            </div>
          )}

          {/* Paso 1: canal */}
          {showChannelStep && (
            <div className="rounded-xl border bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8">
              <h2 className="text-xl font-semibold text-zinc-900 dark:text-white sm:text-2xl">
                ¿Cómo quieres recibir tu código?
              </h2>
              <p className="mt-3 text-base text-zinc-600 dark:text-slate-300 sm:text-lg">
                Elige una opción. Te enviaremos un código de 6 números para confirmar que eres tú.
              </p>

              <form className="mt-8 space-y-6" onSubmit={submitSendOtp}>
                <input type="hidden" name="token" value={sendForm.data.token} />

                <fieldset>
                  <legend className="sr-only">Canal de envío del código</legend>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <label
                      className={`flex min-h-[56px] cursor-pointer flex-col rounded-xl border-2 p-5 text-left transition ${
                        sendForm.data.channel === "sms"
                          ? "border-blue-600 bg-blue-50 dark:bg-blue-950/30"
                          : "border-zinc-200 hover:border-zinc-300 dark:border-slate-700"
                      } ${!canPickSms ? "cursor-not-allowed opacity-50" : ""}`}
                    >
                      <input
                        type="radio"
                        name="channel"
                        value="sms"
                        className="sr-only"
                        disabled={!canPickSms}
                        checked={sendForm.data.channel === "sms"}
                        onChange={() => sendForm.setData("channel", "sms")}
                      />
                      <span className="text-lg font-semibold text-zinc-900 dark:text-white">Mensaje de texto (SMS)</span>
                      <span className="mt-2 text-base text-zinc-600 dark:text-slate-300">
                        Al número que termina en <strong>{phoneLast4 || "****"}</strong>
                      </span>
                    </label>

                    <label
                      className={`flex min-h-[56px] cursor-pointer flex-col rounded-xl border-2 p-5 text-left transition ${
                        sendForm.data.channel === "email"
                          ? "border-blue-600 bg-blue-50 dark:bg-blue-950/30"
                          : "border-zinc-200 hover:border-zinc-300 dark:border-slate-700"
                      } ${!canPickEmail ? "cursor-not-allowed opacity-50" : ""}`}
                    >
                      <input
                        type="radio"
                        name="channel"
                        value="email"
                        className="sr-only"
                        disabled={!canPickEmail}
                        checked={sendForm.data.channel === "email"}
                        onChange={() => sendForm.setData("channel", "email")}
                      />
                      <span className="text-lg font-semibold text-zinc-900 dark:text-white">Correo electrónico</span>
                      <span className="mt-2 text-base text-zinc-600 dark:text-slate-300">
                        A <strong>{maskedEmail || "tu correo"}</strong>
                      </span>
                    </label>
                  </div>
                </fieldset>

                <button
                  type="submit"
                  disabled={sendForm.processing || !channelSelected}
                  className="w-full min-h-[56px] rounded-xl bg-blue-600 py-4 text-lg font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-zinc-300 dark:disabled:bg-slate-600"
                >
                  {sendForm.processing ? "Enviando…" : "Recibir código"}
                </button>
              </form>
            </div>
          )}

          {/* Paso 2: OTP */}
          {showOtpStep && (
            <div className="grid gap-6 lg:grid-cols-2">
              <div className="rounded-xl border bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8">
                <h2 className="text-xl font-semibold text-zinc-900 dark:text-white sm:text-2xl">
                  Escribe tu código
                </h2>
                <p className="mt-3 text-base text-zinc-600 dark:text-slate-300 sm:text-lg">
                  {otpChannel === "email"
                    ? "Revisa tu correo y escribe los 6 números."
                    : "Revisa el mensaje de texto y escribe los 6 números."}
                </p>

                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-base text-zinc-700 dark:text-slate-300">
                  <span>
                    Tiempo restante: <strong>{formatCountdown(otpSecondsLeft)}</strong>
                  </span>
                  <span>
                    Te quedan <strong>{remainingAttempts}</strong> intentos
                  </span>
                </div>

                <div className="mt-6 flex flex-wrap justify-center gap-2 sm:gap-3" role="group" aria-label="Código de 6 dígitos">
                  {digits.map((d, i) => (
                    <input
                      key={i}
                      ref={(el) => {
                        inputsRef.current[i] = el;
                      }}
                      type="text"
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      maxLength={1}
                      value={d}
                      disabled={verifyDisabled}
                      onChange={(e) => setDigitAt(i, e.target.value)}
                      onKeyDown={(e) => handleDigitKeyDown(i, e)}
                      onPaste={(e) => {
                        e.preventDefault();
                        digitsTouchedAfterErrorRef.current = true;
                        const paste = e.clipboardData.getData("text").replace(/\D/g, "").slice(0, 6);
                        if (!paste) return;
                        const arr = paste.split("");
                        setDigits((prev) => {
                          const next = [...prev];
                          for (let j = 0; j < 6; j++) next[j] = arr[j] ?? "";
                          return next;
                        });
                        const nextFocus = Math.min(paste.length, 5);
                        inputsRef.current[nextFocus]?.focus();
                      }}
                      className="h-14 w-11 rounded-lg border-2 border-zinc-300 text-center text-2xl font-semibold focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-300 disabled:bg-zinc-100 dark:border-slate-600 dark:bg-slate-800 dark:text-white sm:h-16 sm:w-12 sm:text-3xl"
                      aria-label={`Dígito ${i + 1} de 6`}
                    />
                  ))}
                </div>

                <p className="mt-4 text-center text-sm text-zinc-500 dark:text-slate-400">
                  {verifySubmitting
                    ? "Comprobando código…"
                    : otpExpired
                      ? "El código venció. Pide uno nuevo abajo."
                      : null}
                </p>

                <button
                  type="button"
                  onClick={submitResend}
                  disabled={!canResend || resendForm.processing || !otpChannel || noAttemptsLeft}
                  className="mt-6 w-full min-h-[48px] rounded-xl border-2 border-blue-600 py-3 text-base font-semibold text-blue-700 hover:bg-blue-50 disabled:cursor-not-allowed disabled:border-zinc-300 disabled:text-zinc-400 dark:border-blue-500 dark:text-blue-300 dark:hover:bg-blue-950/40 dark:disabled:border-slate-600"
                >
                  {resendForm.processing
                    ? "Enviando…"
                    : canResend
                      ? "Pedir otro código"
                      : `Podrás pedir otro en ${formatCountdown(resendSecondsLeft)}`}
                </button>
                <p className="mt-2 text-center text-sm text-zinc-500">
                  Reenvío disponible cada {resendSeconds} segundos.
                </p>
              </div>

              <div className="rounded-xl border bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8">
                <h3 className="text-lg font-semibold text-zinc-900 dark:text-white sm:text-xl">Tu información está protegida</h3>
                <ul className="mt-4 space-y-3 text-base text-zinc-600 dark:text-slate-300 sm:text-lg">
                  <li>• Solo tú puedes ver estos resultados con el código.</li>
                  <li>• El código caduca en unos minutos por seguridad.</li>
                  <li>• Si no pediste este acceso, ignora el mensaje.</li>
                </ul>
              </div>
            </div>
          )}

          {/* Paso 3: resultados */}
          {showResults && (
            <div className="space-y-6">
              {isSharedView ? (
                <>
                  <div
                    className="rounded-xl border border-blue-200 bg-blue-50 p-5 text-base text-blue-900 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-100"
                    role="status"
                  >
                    <p className="font-semibold">Estas vistas son solo de lectura.</p>                    
                    <p className="mt-2">
                      Disponibles por: <strong>{formatDuration(otpSecondsLeft)} Hrs.</strong>
                    </p>
                  </div>

                  <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <iframe
                      title="Resultados compartidos"
                      src={pdfUrl}
                      className="h-[70vh] min-h-[400px] w-full sm:h-[75vh]"
                    />
                  </div>
                </>
              ) : (
                <>
                  <div
                    className="rounded-xl border border-green-200 bg-green-50 p-5 text-base text-green-900 dark:border-green-800 dark:bg-green-950/40 dark:text-green-100"
                    role="status"
                  >
                    Listo. Aquí puedes ver y descargar tus resultados.
                  </div>

                  <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    {pdfDownloadUrl && (
                      <a
                        href={pdfDownloadUrl}
                        download="resultados.pdf"
                        className="inline-flex min-h-[52px] min-w-[48px] flex-1 items-center justify-center rounded-xl bg-blue-600 px-6 py-4 text-center text-lg font-semibold text-white hover:bg-blue-700"
                      >
                        Descargar PDF
                      </a>
                    )}
                    <button
                      type="button"
                      onClick={shareResults}
                      className="inline-flex min-h-[52px] min-w-[48px] flex-1 items-center justify-center rounded-xl border-2 border-blue-600 px-6 py-4 text-lg font-semibold text-blue-700 hover:bg-blue-50 dark:border-blue-500 dark:text-blue-300 dark:hover:bg-blue-950/40"
                    >
                      Compartir enlace
                    </button>
                  </div>

                  <p className="text-base text-zinc-600 dark:text-slate-400 sm:text-lg">{LEGAL_NOTICE}</p>

                  <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <iframe
                      title="Tus resultados de laboratorio"
                      src={pdfUrl}
                      className="h-[70vh] min-h-[400px] w-full sm:h-[75vh]"
                    />
                  </div>
                </>
              )}
            </div>
          )}
        </div>
      </div>

    </>
  );
}
