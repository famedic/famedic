import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import { Dialog, DialogTitle, DialogBody, DialogActions } from "@/Components/Catalyst/dialog";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import formatMmSs from "@/Utils/formatMmSs";
import { maskEmail, maskPhone } from "@/Utils/sensitiveMask";
import {
	ChatBubbleLeftRightIcon,
	EnvelopeIcon,
	LockClosedIcon,
	PaperAirplaneIcon,
	ShieldCheckIcon,
} from "@heroicons/react/24/outline";

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

function ChannelCard({ id, icon: Icon, title, subtitle, checked, disabled, recommended = false, onSelect }) {
	return (
		<button
			type="button"
			onClick={onSelect}
			disabled={disabled}
			className={`w-full rounded-2xl border p-4 text-left transition ${
				checked
					? "border-famedic-light bg-famedic-light/10 ring-2 ring-famedic-light/30"
					: "border-zinc-200 bg-white hover:bg-zinc-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800"
			} ${disabled ? "cursor-not-allowed opacity-50" : ""}`}
			aria-pressed={checked}
		>
			<div className="flex items-start gap-3">
				<div className="rounded-xl bg-zinc-100 p-2 dark:bg-slate-800">
					<Icon className="size-5 text-zinc-700 dark:text-slate-200" />
				</div>
				<div className="min-w-0 flex-1">
					<div className="flex items-center gap-2">
						<p className="text-sm font-semibold text-zinc-900 dark:text-white">{title}</p>
						{recommended && (
							<span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
								Recomendado
							</span>
						)}
					</div>
					<p className="mt-1 truncate text-xs text-zinc-600 dark:text-slate-400">{subtitle}</p>
				</div>
				<span
					className={`mt-1 size-4 shrink-0 rounded-full border ${
						checked ? "border-famedic-light bg-famedic-light" : "border-zinc-300 dark:border-slate-600"
					}`}
				/>
			</div>
		</button>
	);
}

export default function CouponCreationOtpModal({ isOpen, assignPayload, onSuccess, onClose }) {
	const { auth } = usePage().props;
	const userHasSms = Boolean(auth?.user?.phone);
	const userHasEmail = Boolean(auth?.user?.email);

	const maskedPhone = auth?.user?.masked_phone || maskPhone(auth?.user?.phone ?? auth?.user?.full_phone);
	const maskedEmail = auth?.user?.masked_email || maskEmail(auth?.user?.email);

	const [step, setStep] = useState("channel");
	const [channel, setChannel] = useState(userHasEmail ? "email" : "sms");
	const [challengeId, setChallengeId] = useState(null);

	const [sending, setSending] = useState(false);
	const [verifying, setVerifying] = useState(false);
	const [error, setError] = useState("");

	const [otpSecondsLeft, setOtpSecondsLeft] = useState(0);
	const [resendSecondsLeft, setResendSecondsLeft] = useState(0);
	const [remainingAttempts, setRemainingAttempts] = useState(5);

	const [digits, setDigits] = useState(["", "", "", "", "", ""]);
	const inputsRef = useRef([]);
	const autoSubmitLock = useRef(false);

	const sendUrl = route("admin.coupons.assign.creation-otp.send");
	const resendUrl = route("admin.coupons.assign.creation-otp.resend");
	const verifyUrl = route("admin.coupons.assign.creation-otp.verify");

	useEffect(() => {
		if (!isOpen) return;
		setError("");
		setStep("channel");
		setChannel(userHasEmail ? "email" : "sms");
		setChallengeId(null);
		setSending(false);
		setVerifying(false);
		setOtpSecondsLeft(0);
		setResendSecondsLeft(0);
		setRemainingAttempts(5);
		setDigits(["", "", "", "", "", ""]);
		autoSubmitLock.current = false;
	}, [isOpen, userHasEmail]);

	useEffect(() => {
		if (!isOpen) return;
		const timer = setInterval(() => {
			setOtpSecondsLeft((p) => Math.max(0, Math.floor(Number(p)) - 1));
			setResendSecondsLeft((p) => Math.max(0, Math.floor(Number(p)) - 1));
		}, 1000);
		return () => clearInterval(timer);
	}, [isOpen]);

	useEffect(() => {
		if (step === "code" && inputsRef.current[0]) inputsRef.current[0].focus();
	}, [step]);

	const codeJoined = useMemo(() => digits.join(""), [digits]);
	const otpExpired = step === "code" && otpSecondsLeft === 0;
	const canResend = step === "code" && resendSecondsLeft === 0 && !verifying && !sending && challengeId;
	const noAttemptsLeft = remainingAttempts <= 0;
	const channelTarget = channel === "sms" ? maskedPhone || "SMS" : maskedEmail || "correo";

	const setDigitAt = useCallback((index, value) => {
		setError("");
		autoSubmitLock.current = false;
		const v = String(value ?? "").replace(/\D/g, "").slice(-1);
		setDigits((prev) => {
			const next = [...prev];
			next[index] = v;
			return next;
		});
		if (v && index < 5) inputsRef.current[index + 1]?.focus();
	}, []);

	const handleDigitKeyDown = useCallback(
		(index, e) => {
			if (e.key === "Backspace" && !digits[index] && index > 0) inputsRef.current[index - 1]?.focus();
		},
		[digits],
	);

	const applyOtpSendResponse = (r) => {
		const expiresIn = Math.floor(Number(r?.expires_in ?? 0));
		if (expiresIn <= 0) {
			throw new Error("El servidor no devolvió tiempo de vigencia para el código.");
		}
		if (r?.challenge_id) setChallengeId(r.challenge_id);
		setStep("code");
		setOtpSecondsLeft(expiresIn);
		setResendSecondsLeft(Math.floor(Number(r?.resend_in ?? 0)));
		setRemainingAttempts(Number(r?.max_attempts ?? 5));
		setDigits(["", "", "", "", "", ""]);
		autoSubmitLock.current = false;
	};

	const submitSend = async () => {
		setError("");
		if (!channel || !assignPayload) return;
		const payloadForOtp = { ...assignPayload };
		delete payloadForOtp.file;
		setSending(true);
		try {
			const r = await jsonFetch(sendUrl, {
				method: "POST",
				body: { channel, assign_payload: payloadForOtp },
			});
			applyOtpSendResponse(r);
		} catch (e) {
			setError(e.message || "No se pudo enviar el código.");
		} finally {
			setSending(false);
		}
	};

	const submitResend = async () => {
		setError("");
		if (!challengeId) return;
		const payloadForOtp = { ...assignPayload };
		delete payloadForOtp.file;
		setSending(true);
		try {
			const r = await jsonFetch(resendUrl, {
				method: "POST",
				body: { challenge_id: challengeId, channel, assign_payload: payloadForOtp },
			});
			applyOtpSendResponse(r);
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
		if (joined.length !== 6 || otpExpired || noAttemptsLeft || !challengeId) return;
		setError("");
		setVerifying(true);
		try {
			const r = await jsonFetch(verifyUrl, {
				method: "POST",
				body: { challenge_id: challengeId, code: joined },
			});
			onSuccess?.(r);
		} catch (e) {
			setDigits(["", "", "", "", "", ""]);
			autoSubmitLock.current = false;
			inputsRef.current[0]?.focus();
			setError(e.message || "Código incorrecto.");
		} finally {
			setVerifying(false);
		}
	};

	useEffect(() => {
		if (step !== "code" || codeJoined.length !== 6 || verifying || sending || autoSubmitLock.current) return;
		autoSubmitLock.current = true;
		submitVerify();
	}, [step, codeJoined, verifying, sending]);

	return (
		<Dialog open={isOpen} onClose={onClose} size="xl">
			<div className="space-y-4">
				<div className="flex items-start gap-3">
					<div className="rounded-2xl bg-emerald-100 p-2.5 dark:bg-emerald-950/40">
						<ShieldCheckIcon className="size-6 text-emerald-700 dark:text-emerald-300" />
					</div>
					<div className="min-w-0">
						<DialogTitle>Verificación para crear cupón</DialogTitle>
						<div className="mt-1 inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-slate-800 dark:text-slate-200">
							<LockClosedIcon className="size-3.5" />
							Seguridad adicional
						</div>
					</div>
				</div>

				<DialogBody className="space-y-4">
					<Text className="text-sm text-zinc-600 dark:text-slate-400">
						Por seguridad, enviamos un código de verificación a tu correo o SMS. El cupón no se guardará
						hasta que valides el código.
					</Text>

					{error && (
						<div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/20 dark:text-red-200">
							{error}
						</div>
					)}

					{step === "channel" && (
						<div className="space-y-3">
							<ChannelCard
								id="email"
								icon={EnvelopeIcon}
								title="Correo electrónico"
								subtitle={userHasEmail && maskedEmail ? maskedEmail : "No disponible"}
								checked={channel === "email"}
								disabled={!userHasEmail}
								recommended={userHasEmail}
								onSelect={() => setChannel("email")}
							/>
							<ChannelCard
								id="sms"
								icon={ChatBubbleLeftRightIcon}
								title="SMS"
								subtitle={userHasSms && maskedPhone ? `Enviado a ${maskedPhone}` : "No disponible"}
								checked={channel === "sms"}
								disabled={!userHasSms}
								recommended={userHasSms && !userHasEmail}
								onSelect={() => setChannel("sms")}
							/>
							<div className="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-700 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-200">
								El código se enviará a <Strong>{channelTarget}</Strong>
							</div>
						</div>
					)}

					{step === "code" && (
						<div className="space-y-4">
							<Text className="text-sm text-zinc-700 dark:text-slate-300">
								Ingresa el código enviado a <Strong>{channelTarget}</Strong>.
							</Text>
							<div className="flex justify-center gap-2">
								{digits.map((d, i) => (
									<input
										key={i}
										ref={(el) => (inputsRef.current[i] = el)}
										value={d}
										inputMode="numeric"
										autoComplete="one-time-code"
										className="h-12 w-10 rounded-xl border border-zinc-300 bg-white text-center text-lg font-semibold text-zinc-900 outline-none focus:ring-2 focus:ring-famedic-light dark:border-slate-600 dark:bg-slate-900 dark:text-white"
										onChange={(e) => setDigitAt(i, e.target.value)}
										onKeyDown={(e) => handleDigitKeyDown(i, e)}
									/>
								))}
							</div>
							<div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
								<Text className="text-sm text-zinc-600 dark:text-slate-400">
									Expira en <Strong>{formatMmSs(otpSecondsLeft)}</Strong> · Intentos:{" "}
									<Strong>{Math.max(0, remainingAttempts)}</Strong>
								</Text>
								<Button outline onClick={submitResend} disabled={!canResend} className="min-h-11 justify-center">
									{resendSecondsLeft > 0 ? `Reenviar en ${resendSecondsLeft}s` : "Reenviar código"}
								</Button>
							</div>
							{otpExpired && (
								<Text className="text-sm text-red-600 dark:text-red-300">El código expiró. Reenvía uno nuevo.</Text>
							)}
						</div>
					)}
				</DialogBody>

				<DialogActions>
					<Button outline onClick={onClose} className="min-h-11 justify-center">
						Cancelar
					</Button>
					{step === "channel" ? (
						<Button
							onClick={submitSend}
							disabled={sending || (!userHasSms && !userHasEmail)}
							color="famedic-lime"
							className="min-h-11 justify-center"
						>
							<PaperAirplaneIcon className="size-4" />
							{sending ? "Enviando..." : "Enviar código"}
						</Button>
					) : (
						<Button
							onClick={submitVerify}
							disabled={verifying || otpExpired || noAttemptsLeft || codeJoined.length !== 6}
							color="famedic-lime"
							className="min-h-11 justify-center"
						>
							{verifying ? "Verificando..." : "Confirmar y guardar"}
						</Button>
					)}
				</DialogActions>
			</div>
		</Dialog>
	);
}
