import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import { Dialog, DialogTitle, DialogBody, DialogActions } from "@/Components/Catalyst/dialog";
import { Text, Strong, TextLink } from "@/Components/Catalyst/text";
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

function ChannelCard({
	id,
	icon: Icon,
	title,
	subtitle,
	checked,
	disabled,
	recommended = false,
	onSelect,
}) {
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
					aria-label={checked ? `${id} seleccionado` : `${id} no seleccionado`}
				/>
			</div>
		</button>
	);
}

export default function SecurityVerificationModal({ isOpen, purchaseId, onSuccess, onClose }) {
	const { auth } = usePage().props;
	const userHasSms = Boolean(auth?.user?.phone);
	const userHasEmail = Boolean(auth?.user?.email);

	const maskedPhone = auth?.user?.masked_phone || maskPhone(auth?.user?.phone ?? auth?.user?.full_phone);
	const maskedEmail = auth?.user?.masked_email || maskEmail(auth?.user?.email);

	const [step, setStep] = useState("channel");
	const [channel, setChannel] = useState(userHasSms ? "sms" : "email");
	const [showWhy, setShowWhy] = useState(false);

	const [sending, setSending] = useState(false);
	const [verifying, setVerifying] = useState(false);
	const [error, setError] = useState("");
	const [trustMinutes, setTrustMinutes] = useState(15);

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
		setChannel(userHasSms ? "sms" : "email");
		setShowWhy(false);
		setSending(false);
		setVerifying(false);
		setTrustMinutes(15);
		setOtpSecondsLeft(0);
		setResendSecondsLeft(0);
		setRemainingAttempts(5);
		setDigits(["", "", "", "", "", ""]);
		autoSubmitLock.current = false;

		jsonFetch(statusUrl)
			.then((s) => {
				if (typeof s?.trust_minutes === "number") setTrustMinutes(s.trust_minutes);
				if (s?.verified) onSuccess?.({ expires_in: s.expires_in });
			})
			.catch(() => {});
	}, [isOpen, statusUrl, userHasSms]);

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
	const otpExpired = step === "code" && otpSecondsLeft === 0 && otpSecondsLeft !== null;
	const canResend = step === "code" && resendSecondsLeft === 0 && !verifying && !sending;
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
			if (e.key === "ArrowLeft" && index > 0) {
				e.preventDefault();
				inputsRef.current[index - 1]?.focus();
			}
			if (e.key === "ArrowRight" && index < 5) {
				e.preventDefault();
				inputsRef.current[index + 1]?.focus();
			}
		},
		[digits],
	);

	const submitSend = async () => {
		setError("");
		if (!channel) return;
		setSending(true);
		try {
			const r = await jsonFetch(sendUrl, { method: "POST", body: { channel } });
			if (typeof r?.trust_minutes === "number") setTrustMinutes(r.trust_minutes);
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
			if (typeof r?.trust_minutes === "number") setTrustMinutes(r.trust_minutes);
			setOtpSecondsLeft(Math.floor(Number(r.expires_in ?? 0)));
			setResendSecondsLeft(Math.floor(Number(r.resend_in ?? 0)));
			setDigits(["", "", "", "", "", ""]);
			autoSubmitLock.current = false;
		} catch (e) {
			if (e.status === 429 && e.data?.resend_in) setResendSecondsLeft(Math.floor(Number(e.data.resend_in)));
			setError(e.message || "No se pudo reenviar el código.");
		} finally {
			setSending(false);
		}
	};

	const submitVerify = async () => {
		const joined = onlyDigits6(codeJoined);
		if (joined.length !== 6 || otpExpired || noAttemptsLeft) return;
		setError("");
		setVerifying(true);
		try {
			const r = await jsonFetch(verifyUrl, { method: "POST", body: { code: joined } });
			if (typeof r?.trust_minutes === "number") setTrustMinutes(r.trust_minutes);
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
						<DialogTitle>Verificación de seguridad</DialogTitle>
						<div className="mt-1 inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-slate-800 dark:text-slate-200">
							<LockClosedIcon className="size-3.5" />
							🔒 Tus resultados están protegidos
						</div>
					</div>
				</div>

				<DialogBody className="space-y-4">
					<Text className="text-zinc-700 dark:text-slate-300">
						Para proteger tu información médica, confirma tu identidad antes de ver o descargar tus resultados.
					</Text>
					<Text className="text-sm text-zinc-600 dark:text-slate-400">
						Te enviaremos un código de 6 dígitos para verificar tu identidad.
					</Text>
					<Text className="text-xs text-emerald-700 dark:text-emerald-300">
						Después de verificar, tendrás <Strong>{trustMinutes} minutos</Strong> para consultar resultados sin volver a validar OTP.
					</Text>
					<TextLink
						href="#"
						className="inline-flex text-xs"
						onClick={(e) => {
							e.preventDefault();
							setShowWhy((p) => !p);
						}}
					>
						¿Por qué es necesario?
					</TextLink>
					{showWhy && (
						<div className="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-300">
							Aplicamos verificación OTP para impedir accesos no autorizados a resultados médicos sensibles.
						</div>
					)}

					{error && (
						<div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/20 dark:text-red-200">
							{error}
						</div>
					)}

					{step === "channel" && (
						<div className="space-y-3">
							<ChannelCard
								id="sms"
								icon={ChatBubbleLeftRightIcon}
								title="SMS"
								subtitle={userHasSms && maskedPhone ? `Enviado a ${maskedPhone}` : "No disponible"}
								checked={channel === "sms"}
								disabled={!userHasSms}
								recommended={userHasSms}
								onSelect={() => setChannel("sms")}
							/>
							<ChannelCard
								id="email"
								icon={EnvelopeIcon}
								title="Correo electrónico"
								subtitle={userHasEmail && maskedEmail ? maskedEmail : "No disponible"}
								checked={channel === "email"}
								disabled={!userHasEmail}
								onSelect={() => setChannel("email")}
							/>

							<div
								className="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-700 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-200"
								title="Tus resultados están protegidos. Te pediremos un código OTP."
							>
								El código se enviará a <Strong>{channelTarget}</Strong>
							</div>

							{!userHasSms && !userHasEmail && (
								<Text className="text-sm text-red-600 dark:text-red-300">
									No tienes teléfono ni correo registrados. Actualiza tus datos para validar identidad.
								</Text>
							)}
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
							{verifying ? "Verificando..." : "Confirmar"}
						</Button>
					)}
				</DialogActions>

				<Text className="text-center text-xs text-zinc-500 dark:text-slate-500">
					Tu información está protegida con estándares de seguridad médica.
				</Text>
			</div>
		</Dialog>
	);
}
