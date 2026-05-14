import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import { Dialog, DialogTitle, DialogBody, DialogActions } from "@/Components/Catalyst/dialog";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import formatMmSs from "@/Utils/formatMmSs";
import { maskEmail, maskPhone } from "@/Utils/sensitiveMask";

function getCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

function onlyDigits6(v) {
  return String(v ?? "").replace(/\D/g, "").slice(0, 6);
}

async function jsonFetch(url, { method = "GET", body } = {}) {
  const res = await fetch(url, {
    method,
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": getCsrf(),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const message = data?.message || "No se pudo completar la operación.";
    const error = new Error(message);
    error.status = res.status;
    error.data = data;
    throw error;
  }

  return data;
}

export default function OtpModal({
  isOpen,
  purchaseId,
  onSuccess,
  onClose,
}) {
  const { auth } = usePage().props;
  const userHasSms = Boolean(auth?.user?.phone);
  const userHasEmail = Boolean(auth?.user?.email);

  const maskedPhone = auth?.user?.masked_phone || maskPhone(auth?.user?.phone ?? auth?.user?.full_phone);
  const maskedEmail = auth?.user?.masked_email || maskEmail(auth?.user?.email);

  const [step, setStep] = useState("channel"); // channel | code
  const [channel, setChannel] = useState(userHasSms ? "sms" : "email");

  const [sending, setSending] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [error, setError] = useState("");

  const [otpSecondsLeft, setOtpSecondsLeft] = useState(0);
  const [resendSecondsLeft, setResendSecondsLeft] = useState(0);
  const [remainingAttempts, setRemainingAttempts] = useState(5);

  const [digits, setDigits] = useState(["", "", "", "", "", ""]);
  const inputsRef = useRef([]);
  const autoSubmitLock = useRef(false);

  const statusUrl = useMemo(() => route("otp.status", { laboratory_purchase: purchaseId }), [purchaseId]);
  const sendUrl = useMemo(() => route("otp.send", { laboratory_purchase: purchaseId }), [purchaseId]);
  const resendUrl = useMemo(() => route("otp.resend", { laboratory_purchase: purchaseId }), [purchaseId]);
  const verifyUrl = useMemo(() => route("otp.verify", { laboratory_purchase: purchaseId }), [purchaseId]);

  useEffect(() => {
    if (!isOpen) return;
    setError("");
    setStep("channel");
    setSending(false);
    setVerifying(false);
    setOtpSecondsLeft(0);
    setResendSecondsLeft(0);
    setRemainingAttempts(5);
    setDigits(["", "", "", "", "", ""]);
    autoSubmitLock.current = false;

    jsonFetch(statusUrl)
      .then((s) => {
        if (s?.verified) {
          onSuccess?.({ expires_in: s.expires_in });
        }
      })
      .catch(() => {
        // ignore
      });
  }, [isOpen, statusUrl]);

  useEffect(() => {
    if (!isOpen) return;
    const timer = setInterval(() => {
      setOtpSecondsLeft((p) => Math.max(0, Math.floor(Number(p)) - 1));
      setResendSecondsLeft((p) => Math.max(0, Math.floor(Number(p)) - 1));
    }, 1000);
    return () => clearInterval(timer);
  }, [isOpen]);

  useEffect(() => {
    if (step === "code" && inputsRef.current[0]) {
      inputsRef.current[0].focus();
    }
  }, [step]);

  const codeJoined = useMemo(() => digits.join(""), [digits]);
  const otpExpired = step === "code" && otpSecondsLeft === 0 && otpSecondsLeft !== null;
  const canResend = step === "code" && resendSecondsLeft === 0 && !verifying && !sending;
  const noAttemptsLeft = remainingAttempts <= 0;

  const setDigitAt = useCallback((index, value) => {
    setError("");
    autoSubmitLock.current = false;
    const v = String(value ?? "").replace(/\D/g, "").slice(-1);
    setDigits((prev) => {
      const next = [...prev];
      next[index] = v;
      return next;
    });
    if (v && index < 5) {
      inputsRef.current[index + 1]?.focus();
    }
  }, []);

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

  const submitSend = async () => {
    setError("");
    if (!channel) return;
    setSending(true);
    try {
      const r = await jsonFetch(sendUrl, { method: "POST", body: { channel } });
      setStep("code");
      setOtpSecondsLeft(Math.floor(Number(r.expires_in ?? 0)));
      setResendSecondsLeft(Math.floor(Number(r.resend_in ?? 0)));
      setRemainingAttempts(Number(r.max_attempts ?? 5));
      setDigits(["", "", "", "", "", ""]);
      autoSubmitLock.current = false;
    } catch (e) {
      setError(e.message || "No se pudo enviar el código.");
    } finally {
      setSending(false);
    }
  };

  const submitResend = async () => {
    setError("");
    setSending(true);
    try {
      const r = await jsonFetch(resendUrl, { method: "POST", body: { channel } });
      setStep("code");
      setOtpSecondsLeft(Math.floor(Number(r.expires_in ?? 0)));
      setResendSecondsLeft(Math.floor(Number(r.resend_in ?? 0)));
      setDigits(["", "", "", "", "", ""]);
      autoSubmitLock.current = false;
    } catch (e) {
      if (e.status === 429 && e.data?.resend_in) {
        setResendSecondsLeft(Math.floor(Number(e.data.resend_in)));
      }
      setError(e.message || "No se pudo reenviar el código.");
    } finally {
      setSending(false);
    }
  };

  const submitVerify = async () => {
    const joined = onlyDigits6(codeJoined);
    if (joined.length !== 6) return;
    if (otpExpired || noAttemptsLeft) return;

    setError("");
    setVerifying(true);
    try {
      const r = await jsonFetch(verifyUrl, { method: "POST", body: { code: joined } });
      onSuccess?.(r);
    } catch (e) {
      const remaining = e?.data?.remaining_attempts;
      if (typeof remaining === "number") setRemainingAttempts(remaining);
      setDigits(["", "", "", "", "", ""]);
      autoSubmitLock.current = false;
      inputsRef.current[0]?.focus();
      setError(e.message || "Código incorrecto.");
    } finally {
      setVerifying(false);
    }
  };

  useEffect(() => {
    if (step !== "code") return;
    if (codeJoined.length !== 6) return;
    if (verifying || sending) return;
    if (autoSubmitLock.current) return;
    autoSubmitLock.current = true;
    submitVerify();
  }, [step, codeJoined, verifying, sending]);

  return (
    <Dialog open={isOpen} onClose={onClose}>
      <DialogTitle>Verificación de seguridad</DialogTitle>
      <DialogBody className="space-y-4">
        <Text className="text-zinc-700 dark:text-slate-300">
          Para proteger tu información, confirma tu identidad antes de ver o descargar tus resultados.
        </Text>

        {error && (
          <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/20 dark:text-red-200">
            {error}
          </div>
        )}

        {step === "channel" && (
          <div className="space-y-3">
            <Text className="text-sm text-zinc-600 dark:text-slate-400">
              Elige dónde te enviamos el código de 6 dígitos.
            </Text>
            <div className="flex flex-col gap-2 sm:flex-row">
              <Button
                outline
                onClick={() => setChannel("sms")}
                className={`min-h-[48px] flex-1 justify-center ${channel === "sms" ? "ring-2 ring-famedic-300" : ""}`}
                disabled={!userHasSms}
              >
                <span className="flex flex-col items-center gap-0.5">
                  <span>SMS</span>
                  {userHasSms && maskedPhone && (
                    <span className="text-xs font-normal text-zinc-500 dark:text-slate-400">{maskedPhone}</span>
                  )}
                </span>
              </Button>
              <Button
                outline
                onClick={() => setChannel("email")}
                className={`min-h-[48px] flex-1 justify-center ${channel === "email" ? "ring-2 ring-famedic-300" : ""}`}
                disabled={!userHasEmail}
              >
                <span className="flex flex-col items-center gap-0.5">
                  <span>Correo</span>
                  {userHasEmail && maskedEmail && (
                    <span className="text-xs font-normal text-zinc-500 dark:text-slate-400 break-all px-1">{maskedEmail}</span>
                  )}
                </span>
              </Button>
            </div>
            <Text className="text-xs text-zinc-500 dark:text-slate-500">
              {channel === "sms" && userHasSms && maskedPhone && (
                <>El código se enviará por SMS a <Strong>{maskedPhone}</Strong>.</>
              )}
              {channel === "email" && userHasEmail && maskedEmail && (
                <>El código se enviará al correo <Strong>{maskedEmail}</Strong>.</>
              )}
            </Text>
            {!userHasSms && !userHasEmail && (
              <Text className="text-sm text-red-600 dark:text-red-300">
                No tienes teléfono ni correo registrados. Actualiza tus datos para poder validar.
              </Text>
            )}
          </div>
        )}

        {step === "code" && (
          <div className="space-y-4">
            <div className="flex items-center justify-between gap-3">
              <Text className="text-sm text-zinc-600 dark:text-slate-400">
                Código enviado a{" "}
                <Strong>
                  {channel === "sms" ? maskedPhone || "SMS" : maskedEmail || "correo"}
                </Strong>
              </Text>
              <Text className="text-sm text-zinc-600 dark:text-slate-400">
                Expira en <Strong>{formatMmSs(otpSecondsLeft)}</Strong>
              </Text>
            </div>

            <div className="flex justify-center gap-2">
              {digits.map((d, i) => (
                <input
                  key={i}
                  ref={(el) => (inputsRef.current[i] = el)}
                  value={d}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  className="h-12 w-10 rounded-lg border border-zinc-300 bg-white text-center text-lg font-semibold text-zinc-900 outline-none focus:ring-2 focus:ring-famedic-300 dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                  onChange={(e) => setDigitAt(i, e.target.value)}
                  onKeyDown={(e) => handleDigitKeyDown(i, e)}
                />
              ))}
            </div>

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <Text className="text-sm text-zinc-600 dark:text-slate-400">
                Intentos restantes: <Strong>{Math.max(0, remainingAttempts)}</Strong>
              </Text>
              <Button
                outline
                onClick={submitResend}
                disabled={!canResend}
                className="min-h-[48px] justify-center"
              >
                {resendSecondsLeft > 0 ? `Reenviar en ${resendSecondsLeft}s` : "Reenviar código"}
              </Button>
            </div>

            {otpExpired && (
              <Text className="text-sm text-red-600 dark:text-red-300">
                El código expiró. Reenvía uno nuevo.
              </Text>
            )}
          </div>
        )}
      </DialogBody>

      <DialogActions>
        <Button outline onClick={onClose} className="min-h-[48px] justify-center">
          Cancelar
        </Button>
        {step === "channel" ? (
          <Button onClick={submitSend} disabled={sending || (!userHasSms && !userHasEmail)} className="min-h-[48px] justify-center">
            {sending ? "Enviando..." : "Enviar código"}
          </Button>
        ) : (
          <Button onClick={submitVerify} disabled={verifying || otpExpired || noAttemptsLeft || codeJoined.length !== 6} className="min-h-[48px] justify-center">
            {verifying ? "Verificando..." : "Confirmar"}
          </Button>
        )}
      </DialogActions>
    </Dialog>
  );
}

