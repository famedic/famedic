import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong, Code } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { HelpPanel } from "@/Pages/Admin/PaymentAuthenticationAttempts";

function badgeColor(tone) {
	return {
		success: "famedic-lime",
		declined: "red",
		cancelled: "amber",
		expired: "orange",
		technical: "rose",
		unknown: "slate",
		active: "sky",
	}[tone] || "zinc";
}

function kindClasses(kind) {
	return {
		call_started: "border-sky-300 bg-sky-50 dark:border-sky-800 dark:bg-sky-950/40",
		response_received: "border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/40",
		transition: "border-zinc-300 bg-white dark:border-zinc-700 dark:bg-zinc-900",
		error: "border-rose-300 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/40",
		duplicate: "border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40",
		retry: "border-violet-300 bg-violet-50 dark:border-violet-800 dark:bg-violet-950/40",
		recovery: "border-indigo-300 bg-indigo-50 dark:border-indigo-800 dark:bg-indigo-950/40",
		intention: "border-cyan-300 bg-cyan-50 dark:border-cyan-800 dark:bg-cyan-950/40",
		operation: "border-blue-300 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/40",
		pending: "border-orange-300 bg-orange-50 dark:border-orange-800 dark:bg-orange-950/40",
		verified: "border-lime-300 bg-lime-50 dark:border-lime-800 dark:bg-lime-950/40",
		confirmed: "border-green-400 bg-green-50 dark:border-green-800 dark:bg-green-950/40",
	}[kind] || "border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900";
}

function operationResultLabel(result, operation = "default") {
	if (!result) return "—";

	const labels = {
		not_called: "No ejecutado",
		called_once: "Ejecutado una vez",
		multiple_calls: "Múltiples ejecuciones",
		succeeded: "Exitoso",
		confirmation_pending: "Confirmación pendiente",
		failed: "Fallo técnico",
		attempted: "Intentado",
		completed: "Consulta completada - completada",
		declined: "Consulta completada - autenticación rechazada",
		cancelled: "Consulta completada - cancelada",
		expired: "Consulta completada - expirada",
		pending: "Consulta completada - pendiente",
		provider_confirmation_pending: "Confirmación pendiente del proveedor",
		tokenization_confirmation_pending: "TokenCard en confirmación",
	};

	if (operation === "get_status" && labels[result]) {
		return labels[result];
	}

	return labels[result] || result;
}

function operationAmount(operation) {
	if (!operation || Number(operation.call_count ?? 0) === 0) {
		return "No ejecutado";
	}

	return `$${Number(operation.amount ?? 0).toFixed(2)} ${operation.currency || ""}`.trim();
}

export default function PaymentAuthenticationAttemptPage({ attempt }) {
	return (
		<AdminLayout title={`Intento 3DS ${attempt.support_reference}`}>
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-1">
						<Heading>Intento 3DS</Heading>
						<Text>
							<Strong>Referencia de soporte:</Strong>{" "}
							<Code>{attempt.support_reference}</Code>
						</Text>
					</div>
					<Button
						outline
						href={route("admin.payment-authentication-attempts.index")}
					>
						Volver al listado
					</Button>
				</div>

				<div className="grid gap-4 md:grid-cols-2">
					<div className="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Subheading>Cliente y estado</Subheading>
						<Text><Strong>Cliente:</Strong> {attempt.customer?.name || "—"}</Text>
						<Text><Strong>Correo:</Strong> {attempt.email || "—"}</Text>
						<Text>
							<Strong>Estado:</Strong>{" "}
							<Badge color={badgeColor(attempt.badge?.tone)}>
								{attempt.badge?.label}
							</Badge>
						</Text>
						<Text><Strong>Resultado:</Strong> {attempt.result_category || "—"}</Text>
						<Text><Strong>Origen:</Strong> {attempt.origin_label}</Text>
						<Text><Strong>Certeza:</Strong> {attempt.failure_certainty || "—"}</Text>
						<Text><Strong>Intento:</Strong> #{attempt.attempt_number}</Text>
					</div>
					<div className="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Subheading>Tiempos y llamadas</Subheading>
						<Text><Strong>Inicio:</Strong> {attempt.started_at_local || "—"}</Text>
						<Text><Strong>Fin:</Strong> {attempt.finished_at_local || "—"}</Text>
						<Text><Strong>Duración:</Strong> {attempt.duration_seconds !== null ? `${attempt.duration_seconds}s` : "—"}</Text>
						<Text><Strong>Llamadas:</Strong> {attempt.external_call_count} tot · {attempt.provider_link_call_count} GetLink · {attempt.status_poll_call_count} poll · {attempt.tokenization_call_count} token</Text>
						<Text><Strong>Duplicados bloqueados:</Strong> {attempt.duplicate_request_count}</Text>
						<Text><Strong>Provider order ID:</Strong> {attempt.provider_order_id || "—"}</Text>
						<Text><Strong>Código:</Strong> {attempt.provider_code || "—"}</Text>
						<Text><Strong>Mensaje:</Strong> {attempt.provider_message || "—"}</Text>
					</div>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-3">
					<Subheading>Operaciones EfevooPay</Subheading>
					{attempt.efevoopay_operations ? (
						<>
							<div className="grid gap-3 md:grid-cols-3 text-sm">
								<div className="space-y-1">
									<Text><Strong>GetLink</Strong></Text>
									<Text>Llamadas: {attempt.efevoopay_operations.get_link?.call_count ?? 0}</Text>
									<Text>Importe: ${Number(attempt.efevoopay_operations.get_link?.amount ?? 0).toFixed(2)} {attempt.efevoopay_operations.get_link?.currency}</Text>
									<Text>Order ID: {attempt.efevoopay_operations.get_link?.order_id_masked || "—"}</Text>
									<Text>Resultado: {operationResultLabel(attempt.efevoopay_operations.get_link?.result)}</Text>
								</div>
								<div className="space-y-1">
									<Text><Strong>GetStatus</Strong></Text>
									<Text>Llamadas: {attempt.efevoopay_operations.get_status?.call_count ?? 0}</Text>
									<Text>Ultimo resultado: {operationResultLabel(attempt.efevoopay_operations.get_status?.last_result, "get_status")}</Text>
									{attempt.efevoopay_operations.get_status?.excessive && (
										<Badge color="amber">Excesivo</Badge>
									)}
								</div>
								<div className="space-y-1">
									<Text><Strong>TokenCard</Strong></Text>
									<Text>Llamadas: {attempt.efevoopay_operations.token_card?.call_count ?? 0}</Text>
									<Text>Importe: {operationAmount(attempt.efevoopay_operations.token_card)}</Text>
									<Text>Transaction ID: {attempt.efevoopay_operations.token_card?.transaction_id_masked || "—"}</Text>
									<Text>Resultado: {operationResultLabel(attempt.efevoopay_operations.token_card?.result)}</Text>
									{attempt.efevoopay_operations.token_card?.confirmation_pending && (
										<Badge color="orange">En confirmación</Badge>
									)}
								</div>
							</div>
							{attempt.efevoopay_operations.possible_duplicate_verification_operation && (
								<Badge color="amber">Posible operación de verificación duplicada</Badge>
							)}
							<Text className="text-xs text-zinc-500">
								{attempt.efevoopay_operations.disclaimer}
							</Text>
						</>
					) : (
						<Text>Sin datos de operaciones.</Text>
					)}
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-3">
					<Subheading>Recuperación</Subheading>
					{attempt.recovery_detail ? (
						<div className="grid gap-1 sm:grid-cols-2 text-sm">
							<Text><Strong>UUID:</Strong> {attempt.recovery_detail.context_uuid_masked}</Text>
							<Text><Strong>Tipo:</Strong> {attempt.recovery_detail.context_type}</Text>
							<Text><Strong>Estado:</Strong> {attempt.recovery_detail.context_status}</Text>
							<Text><Strong>Inicio:</Strong> {attempt.recovery_detail.started_at_local || "—"}</Text>
							<Text><Strong>Expira:</Strong> {attempt.recovery_detail.expires_at_local || "—"}</Text>
							<Text><Strong>Intentos en contexto:</Strong> {attempt.recovery_detail.chain_attempt_count}</Text>
							<Text><Strong>Root attempt:</Strong> {attempt.recovery_detail.root_support_reference || "—"}</Text>
							<Text><Strong>Intención:</Strong> {attempt.recovery_detail.selected_intention || "—"}</Text>
							<Text><Strong>Transacción pendiente:</Strong> {attempt.recovery_detail.recovery_transaction_id || "—"}</Text>
							<Text><Strong>Transacción final:</Strong> {attempt.recovery_detail.recovered_transaction_id || "—"}</Text>
							<Text><Strong>Auth recuperada:</Strong> {attempt.recovery_detail.authentication_recovered ? "Sí" : "No"}</Text>
							<Text><Strong>Pago recuperado:</Strong> {attempt.recovery_detail.payment_recovered ? "Sí" : "No"}</Text>
							<Text><Strong>Método comprobado:</Strong> {attempt.recovery_detail.confirmed_method || "—"}</Text>
							<Text><Strong>Card verified:</Strong> {attempt.recovery_detail.card_verified_at_local || "—"}</Text>
							<Text><Strong>Recovered at:</Strong> {attempt.recovery_detail.recovered_at_local || "—"}</Text>
							<Text className="sm:col-span-2 text-xs text-zinc-500">{attempt.recovery_detail.help}</Text>
						</div>
					) : attempt.recovery_context ? (
						<div className="grid gap-1 sm:grid-cols-2">
							<Text><Strong>Tipo:</Strong> {attempt.recovery_context.context_type}</Text>
							<Text><Strong>Estado:</Strong> {attempt.recovery_context.status}</Text>
							<Text><Strong>Método:</Strong> {attempt.recovery_context.recovery_method || "—"}</Text>
							<Text><Strong>Transacción pendiente:</Strong> {attempt.recovery_context.recovery_transaction_id || "—"}</Text>
							<Text><Strong>Transacción final:</Strong> {attempt.recovery_context.recovered_transaction_id || "—"}</Text>
							<Text><Strong>Recuperado:</Strong> {attempt.recovery_context.recovered_at_local || "—"}</Text>
						</div>
					) : (
						<Text>Sin contexto de recuperación vinculado.</Text>
					)}
					{attempt.recovery_intention && (
						<Text><Strong>Intención:</Strong> {attempt.recovery_intention}</Text>
					)}
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-3">
					<Subheading>Cadena de reintentos</Subheading>
					<Text className="text-xs text-zinc-500">
						Solo se relacionan intentos del mismo cliente mediante{" "}
						<Code>retry_of_attempt_id</Code>.
					</Text>
					{attempt.previous_attempt ? (
						<Text>
							<Strong>Anterior:</Strong>{" "}
							<Button
								plain
								href={route("admin.payment-authentication-attempts.show", attempt.previous_attempt.id)}
							>
								{attempt.previous_attempt.support_reference} · #{attempt.previous_attempt.attempt_number} · {attempt.previous_attempt.status}
							</Button>
						</Text>
					) : (
						<Text>No hay intento anterior.</Text>
					)}
					<div className="flex flex-wrap gap-2">
						{attempt.retry_chain?.map((item) => (
							<Button
								key={item.id}
								outline
								href={route("admin.payment-authentication-attempts.show", item.id)}
							>
								#{item.attempt_number} {item.support_reference} · {item.status}
							</Button>
						))}
					</div>
					<Text>
						<Strong>Resultado final de la cadena:</Strong>{" "}
						{attempt.chain_final_status || "—"}
					</Text>
					<Text>
						<Strong>Recuperado en reintento:</Strong>{" "}
						{attempt.chain_recovered ? "Sí" : "No"}
					</Text>
				</div>

				<div className="space-y-3">
					<Subheading>Línea de tiempo</Subheading>
					<div className="space-y-3">
						{attempt.events?.map((event) => (
							<div
								key={event.id}
								className={`rounded-xl border p-4 text-sm ${kindClasses(event.kind)}`}
							>
								<div className="flex flex-wrap items-center justify-between gap-2">
									<Strong>{event.label}</Strong>
									<Text className="text-xs">{event.occurred_at_local}</Text>
								</div>
								<div className="mt-2 grid gap-1 sm:grid-cols-2">
									<Text>Source: {event.source}</Text>
									{(event.status_from || event.status_to) && (
										<Text>
											Estado: {event.status_from || "—"} → {event.status_to || "—"}
										</Text>
									)}
									{event.result_category && <Text>Categoría: {event.result_category}</Text>}
									{event.failure_origin && <Text>Origen: {event.origin_label}</Text>}
									{event.failure_certainty && <Text>Certeza: {event.failure_certainty}</Text>}
									{event.external_operation && (
										<Text>
											Operación: {event.external_operation}
											{event.external_call_number ? ` #${event.external_call_number}` : ""}
										</Text>
									)}
									{event.http_status && <Text>HTTP: {event.http_status}</Text>}
									{event.duration_ms !== null && event.duration_ms !== undefined && (
										<Text>Duración: {event.duration_ms} ms</Text>
									)}
									{event.provider_status && <Text>Provider status: {event.provider_status}</Text>}
									{event.provider_code && <Text>Código: {event.provider_code}</Text>}
									{event.provider_message && <Text>Mensaje: {event.provider_message}</Text>}
								</div>
								{event.metadata && Object.keys(event.metadata).length > 0 && (
									<div className="mt-2 flex flex-wrap gap-2">
										{Object.entries(event.metadata).map(([key, value]) => (
											<Badge key={key} color="zinc">
												{key}: {String(value)}
											</Badge>
										))}
									</div>
								)}
							</div>
						))}
					</div>
				</div>

				<HelpPanel />
			</div>
		</AdminLayout>
	);
}
