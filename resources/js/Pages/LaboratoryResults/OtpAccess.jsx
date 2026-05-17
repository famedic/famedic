import ApplicationLogo from "@/Components/ApplicationLogo";
import DownloadStarted from "@/Components/LaboratoryResults/DownloadStarted";
import OtpVerification from "@/Components/LaboratoryResults/OtpVerification";
import OtpVerificationPage from "@/Components/LaboratoryResults/OtpVerificationPage";
import ResultsDownload from "@/Components/LaboratoryResults/ResultsDownload";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useRef, useState } from "react";

export default function OtpAccess({
  token,
  currentStep = 2,
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
  isSharedView = false,
  availabilityMinutes = 15,
  resendSeconds = 30,
  canUseSms = true,
  canUseEmail = true,
  maskedPhone = null,
  maskedEmail = null,
  patientDisplayName = "",
  orderNumber = "",
  studyDateLabel = null,
  otpExpiryMinutes = 10,
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
  const verifyStartAtRef = useRef(null);
  const [otpResetKey, setOtpResetKey] = useState(0);
  const [downloadStarted, setDownloadStarted] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const downloadUrl = pdfDownloadUrl || pdfUrl;

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
    setOtpResetKey((prev) => prev + 1);
  }, [otpSent, otpChannel, expiresAt]);

  useEffect(() => {
    if (otpChannel) {
      resendForm.setData("channel", otpChannel);
    }
  }, [otpChannel]);

  const canResend = resendSecondsLeft === 0 && !!token && !alreadyVerified && otpSent && !isSharedView;
  const otpExpired = otpSecondsLeft === 0;
  const noAttemptsLeft = remainingAttempts <= 0 && otpSent && !alreadyVerified && !isSharedView;
  const verifyDisabled = verifySubmitting || otpExpired || noAttemptsLeft || !otpSent;

  const channelSelected = sendForm.data.channel === "sms" || sendForm.data.channel === "email";
  const canPickSms = canUseSms;
  const canPickEmail = canUseEmail;

  const submitVerifyOtp = (code) => {
    if (!token || verifyDisabled || code.length !== 6) return;

    router.post(
      route("lab-results.verify"),
      { token, code },
      {
        preserveScroll: true,
        onStart: () => {
          verifyStartAtRef.current = Date.now();
          setVerifySubmitting(true);
        },
        onFinish: () => {
          const elapsed = verifyStartAtRef.current ? Date.now() - verifyStartAtRef.current : 0;
          const minVisibleMs = 1200;
          const waitMs = Math.max(0, minVisibleMs - elapsed);

          setTimeout(() => {
            setVerifySubmitting(false);
            setOtpResetKey((prev) => prev + 1);
          }, waitMs);
        },
      }
    );
  };

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

  const submitSwitchChannel = (newChannel) => {
    if (!token || !otpChannel || newChannel === otpChannel) return;
    if (newChannel !== "sms" && newChannel !== "email") return;
    if (newChannel === "sms" && !canPickSms) return;
    if (newChannel === "email" && !canPickEmail) return;
    if (resendForm.processing) return;

    resendForm.setData("token", token);
    resendForm.setData("channel", newChannel);
    resendForm.post(route("lab-results.resend"), { preserveScroll: true });
  };

  const handleDownload = () => {
    if (!downloadUrl) return;
    setDownloading(true);
    window.location.href = downloadUrl;
    setDownloadStarted(true);
    setTimeout(() => setDownloading(false), 1200);
  };

  const showChannelStep = !errorMessage && !alreadyVerified && !otpSent && !isSharedView;
  const showOtpStep = !errorMessage && !alreadyVerified && otpSent && !isSharedView;
  const showResults =
    !errorMessage &&
    ((alreadyVerified && !isSharedView && !!downloadUrl) || (isSharedView && !!downloadUrl));

  return (
    <>
      <Head title="Resultados de laboratorio | Famedic" />

      <div className="border-b bg-white dark:bg-slate-900 dark:border-slate-800">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-4">
          <a href="/" className="flex items-center gap-2" aria-label="Famedic — inicio">
            <ApplicationLogo className="h-9 w-auto sm:h-10" />
            <span className="text-xl font-bold text-zinc-900 dark:text-white sm:text-2xl">Famedic</span>
          </a>

          <a
            href="/"
            className="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-lg bg-blue-600 px-5 py-3 text-base font-semibold text-white hover:bg-blue-700"
          >
            Ir a mi cuenta
          </a>
        </div>
      </div>

      <div className="min-h-screen bg-gradient-to-b from-slate-950 via-slate-900 to-blue-950/80 px-4 py-8 sm:py-10">
        <div className="mx-auto max-w-6xl space-y-6">
          {errorMessage && (
            <div
              className="rounded-xl border border-red-700 bg-red-900/30 p-5 text-base text-red-100"
              role="alert"
            >
              {errorMessage}
            </div>
          )}

          {pageErrors.channel && (
            <div className="rounded-xl border border-amber-700 bg-amber-900/30 p-4 text-base text-amber-100">
              {pageErrors.channel}
            </div>
          )}

          {pageErrors.otp && !showOtpStep ? (
            <div className="rounded-xl border border-red-700 bg-red-900/30 p-4 text-base text-red-100">
              {pageErrors.otp}
            </div>
          ) : null}

          {showChannelStep && (
            <OtpVerificationPage
              patientDisplayName={patientDisplayName}
              orderNumber={orderNumber}
              studyDateLabel={studyDateLabel}
              maskedPhone={maskedPhone}
              maskedEmail={maskedEmail}
              canUseSms={canPickSms}
              canUseEmail={canPickEmail}
              selectedChannel={sendForm.data.channel}
              onChannelChange={(ch) => sendForm.setData("channel", ch)}
              onSubmit={submitSendOtp}
              submitting={sendForm.processing}
              channelSelected={channelSelected}
              otpExpiryMinutes={otpExpiryMinutes}
            />
          )}

          {showOtpStep && (
            <OtpVerification
              title="Verificación"
              subtitle="Introduce el código de 6 dígitos que recibiste para descargar tu PDF de resultados."
              otpSecondsLeft={otpSecondsLeft}
              resendSecondsLeft={resendSecondsLeft}
              remainingAttempts={remainingAttempts}
              maxAttempts={maxAttempts}
              verifySubmitting={verifySubmitting}
              verifyDisabled={verifyDisabled}
              canResend={canResend && !noAttemptsLeft}
              resendProcessing={resendForm.processing}
              errorMessage={pageErrors.otp}
              otpResetKey={otpResetKey}
              onCodeComplete={submitVerifyOtp}
              onResend={submitResend}
              otpChannel={otpChannel}
              maskedPhone={maskedPhone}
              maskedEmail={maskedEmail}
              canUseSms={canPickSms}
              canUseEmail={canPickEmail}
              onSwitchChannel={submitSwitchChannel}
              patientDisplayName={patientDisplayName}
              orderNumber={orderNumber}
              studyDateLabel={studyDateLabel}
              otpExpiryMinutes={otpExpiryMinutes}
            />
          )}

          {showResults && (
            <>
              <ResultsDownload
                secondsLeft={otpSecondsLeft}
                downloading={downloading}
                onDownload={handleDownload}
                previewUrl={pdfUrl || downloadUrl}
              />

              {downloadStarted ? <DownloadStarted secondsLeft={otpSecondsLeft} /> : null}
            </>
          )}

          {!errorMessage && !showChannelStep && !showOtpStep && !showResults ? (
            <div className="rounded-xl border border-slate-700 bg-slate-800 p-6 text-slate-300">
              Esta sesion expiro. Vuelve a solicitar tu codigo para continuar.
              <div className="mt-3">
                <button
                  type="button"
                  onClick={() => router.get(route("lab-results.show", { token }))}
                  className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                >
                  Reintentar acceso
                </button>
              </div>
            </div>
          ) : null}
        </div>
      </div>
    </>
  );
}
