import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Link } from "@inertiajs/react";

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
}) {
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
