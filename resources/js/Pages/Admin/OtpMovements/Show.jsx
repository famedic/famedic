import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Link } from "@inertiajs/react";
import axios from "axios";
import { useState } from "react";

function copyToClipboard(value) {
	if (!value) {
		return;
	}
	navigator.clipboard?.writeText(value);
}

function severityColor(severity) {
	return (
		{
			success: "lime",
			warning: "amber",
			error: "red",
			info: "sky",
			neutral: "zinc",
		}[severity] || "zinc"
	);
}

export default function OtpMovementsShow({
	movement_key,
	partial_traceability,
	diagnosis,
	timeline,
	identifiers,
	subject,
	staging_otp_reveal,
}) {
	const [revealState, setRevealState] = useState({
		loading: false,
		code: null,
		error: null,
		expiresAt: null,
	});

	const canShowRevealPanel =
		staging_otp_reveal?.feature_enabled &&
		staging_otp_reveal?.can_reveal &&
		staging_otp_reveal?.challenge_id;

	async function handleRevealOtp() {
		if (!staging_otp_reveal?.challenge_id) {
			return;
		}
		setRevealState({ loading: true, code: null, error: null, expiresAt: null });
		try {
			const response = await axios.post(
				route("admin.otp-movements-monitor.reveal-login-otp"),
				{
					challenge_id: staging_otp_reveal.challenge_id,
					correlation_id: staging_otp_reveal.correlation_id || undefined,
				},
			);
			setRevealState({
				loading: false,
				code: response.data?.data?.code ?? null,
				error: null,
				expiresAt: response.data?.data?.expires_at ?? null,
			});
		} catch (err) {
			const message =
				err.response?.data?.message ||
				err.response?.statusText ||
				"No se pudo revelar el OTP.";
			setRevealState({
				loading: false,
				code: null,
				error: message,
				expiresAt: null,
			});
		}
	}

	return (
		<AdminLayout title="Detalle movimiento OTP">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>Detalle de movimiento OTP</Heading>
						<Text className="mt-1 text-sm text-zinc-500">
							Línea de tiempo reconstruida para diagnóstico interno.
						</Text>
					</div>
					<Link href={route("admin.otp-movements-monitor.index")}>
						<Button outline>Volver al listado</Button>
					</Link>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<div className="flex flex-wrap items-center gap-2">
						<Badge color={severityColor(diagnosis.severity)}>
							{diagnosis.summary}
						</Badge>
						{partial_traceability && (
							<Badge color="amber">Trazabilidad parcial</Badge>
						)}
					</div>
					<Text className="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
						{diagnosis.detail}
					</Text>
				</div>

				<div className="grid gap-4 md:grid-cols-2">
					<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm font-medium">correlation_id</Text>
						<div className="mt-2 flex items-center gap-2">
							<code className="break-all text-xs">
								{identifiers.correlation_id || "—"}
							</code>
							{identifiers.correlation_id && (
								<Button
									outline
									className="text-xs"
									onClick={() => copyToClipboard(identifiers.correlation_id)}
								>
									Copiar
								</Button>
							)}
						</div>
					</div>
					<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm font-medium">challenge_id</Text>
						<div className="mt-2 flex items-center gap-2">
							<code className="break-all text-xs">
								{identifiers.challenge_id || "—"}
							</code>
							{identifiers.challenge_id && (
								<Button
									outline
									className="text-xs"
									onClick={() => copyToClipboard(identifiers.challenge_id)}
								>
									Copiar
								</Button>
							)}
						</div>
					</div>
				</div>

				{subject && (
					<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm font-medium">Sujeto</Text>
						<Text className="mt-1 text-sm">
							{subject.name || "Usuario"} · ID {subject.user_id || "—"}
							{subject.customer_id ? ` · Customer ${subject.customer_id}` : ""}
						</Text>
					</div>
				)}

				{canShowRevealPanel && (
					<div className="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/40">
						<Text className="text-sm font-medium text-amber-900 dark:text-amber-100">
							Diagnóstico staging RC1 — OTP login (un solo uso)
						</Text>
						<Text className="mt-2 text-sm text-amber-800 dark:text-amber-200">
							Solo ambiente staging. No copies el código en reportes Newman ni en Git.
							Desactiva{" "}
							<code className="text-xs">OTP_STAGING_DIAGNOSTIC_CODE_REVEAL_ENABLED</code>{" "}
							tras las pruebas.
						</Text>
						<div className="mt-3 flex flex-wrap items-center gap-3">
							<Button
								outline
								disabled={revealState.loading || revealState.code}
								onClick={handleRevealOtp}
							>
								{revealState.loading
									? "Revelando…"
									: revealState.code
										? "OTP ya revelado"
										: "Revelar OTP"}
							</Button>
							{revealState.code && (
								<Text className="text-lg font-mono font-semibold tracking-widest">
									{revealState.code}
								</Text>
							)}
						</div>
						{revealState.expiresAt && (
							<Text className="mt-2 text-xs text-amber-700 dark:text-amber-300">
								Expira: {revealState.expiresAt}
							</Text>
						)}
						{revealState.error && (
							<Text className="mt-2 text-sm text-red-700 dark:text-red-300">
								{revealState.error}
							</Text>
						)}
					</div>
				)}

				<div className="space-y-4">
					<Heading level={2}>Línea de tiempo</Heading>
					{timeline?.length ? (
						<ol className="relative space-y-4 border-l border-zinc-200 pl-6 dark:border-zinc-700">
							{timeline.map((entry) => (
								<li key={entry.id} className="relative">
									<span className="absolute -left-[9px] top-2 h-3 w-3 rounded-full bg-lime-500" />
									<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
										<div className="flex flex-wrap items-center gap-2">
											<Text className="text-sm font-medium">
												{entry.stage_label}
											</Text>
											<Badge color={entry.status_color || "zinc"}>
												{entry.status_label}
											</Badge>
											{entry.is_historical && (
												<Badge color="amber">Histórico inferido</Badge>
											)}
										</div>
										<Text className="mt-1 text-xs text-zinc-500">
											{entry.occurred_at
												? new Date(entry.occurred_at).toLocaleString("es-MX")
												: "—"}
										</Text>
										{entry.channel && (
											<Text className="mt-2 text-sm">
												Canal: {entry.channel}
												{entry.provider ? ` · Proveedor: ${entry.provider}` : ""}
												{entry.provider_result_class
													? ` · Resultado: ${entry.provider_result_class}`
													: ""}
												{entry.http_status
													? ` · HTTP ${entry.http_status}`
													: ""}
											</Text>
										)}
										{entry.technical_message && (
											<Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
												{entry.technical_message}
											</Text>
										)}
										{entry.decoy_reason && (
											<Text className="mt-2 text-xs text-zinc-500">
												Razón decoy interna: {entry.decoy_reason}
											</Text>
										)}
									</div>
								</li>
							))}
						</ol>
					) : (
						<div className="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
							<Text>No hay eventos para reconstruir este movimiento.</Text>
						</div>
					)}
				</div>
			</div>
		</AdminLayout>
	);
}
