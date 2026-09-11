import { Link, useForm } from "@inertiajs/react";
import { ArrowLeftIcon } from "@heroicons/react/16/solid";

import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import { Textarea } from "@/Components/Catalyst/textarea";

export default function Show({
	preEnrollment,
	canGenerateCredit,
	creditGenerationEnabled,
	canRegisterMurguia,
	canVerifyMurguia,
	canRetryMurguia,
	murguiaEnabled,
	murguiaRetryEnabled,
	murguiaContractConfigured,
	murguiaEndpointLabel,
	successMessage,
	errors = {},
}) {
	const form = useForm({ reason: "", confirmation: "" });
	const registerForm = useForm({ confirmation: "" });
	const verifyForm = useForm({});
	const retryForm = useForm({ confirmation: "" });
	const retryStatusAllowed = ["FAILED", "INACTIVE", "PENDING"].includes(preEnrollment.murguia_status);
	const identityLabel = preEnrollment.identity?.full_name || preEnrollment.identity?.name_initials || "—";
	const identityEmail = preEnrollment.identity?.masked_email || "—";
	const endpointLabel = murguiaEndpointLabel || "No configurado";
	const submit = (event) => {
		event.preventDefault();
		form.post(route("admin.odessa.pre-enrollments.generate-credit", preEnrollment.uuid), { preserveScroll: true });
	};
	const register = (event) => {
		event.preventDefault();
		if (!window.confirm(`Se enviará un alta externa individual a Murguía con identidad minimizada autorizada. El identificador completo no se mostrará.\n\nMURGUIA_URL: ${endpointLabel}`)) {
			return;
		}
		registerForm.post(route("admin.odessa.pre-enrollments.murguia.register", preEnrollment.uuid), { preserveScroll: true });
	};
	const verify = (event) => {
		event.preventDefault();
		if (!window.confirm(`Se consultará el estado en Murguía sin mostrar el identificador completo.\n\nMURGUIA_URL: ${endpointLabel}`)) {
			return;
		}
		verifyForm.post(route("admin.odessa.pre-enrollments.murguia.verify", preEnrollment.uuid), { preserveScroll: true });
	};
	const retry = (event) => {
		event.preventDefault();
		if (!window.confirm(`Se verificará primero Murguía y sólo se reintentará el alta si el resultado es seguro.\n\nMURGUIA_URL: ${endpointLabel}`)) {
			return;
		}
		retryForm.post(route("admin.odessa.pre-enrollments.murguia.retry", preEnrollment.uuid), { preserveScroll: true });
	};

	return (
		<AdminLayout title="Preafiliación ODESSA">
			<div className="mx-auto max-w-6xl space-y-6 text-zinc-900 dark:text-zinc-100">
				<Link href={route("admin.odessa.pre-enrollments.index")} className="inline-flex items-center gap-1 text-sm text-famedic-dark hover:underline dark:text-famedic-lime">
					<ArrowLeftIcon className="size-4" />
					Volver
				</Link>
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
							<Heading>Preafiliación ODESSA</Heading>
							<Text className="mt-1 text-sm text-zinc-500">
								UUID {preEnrollment.uuid}
							</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						<Badge color={preEnrollment.status === "READY" ? "green" : preEnrollment.status === "BLOCKED" ? "red" : "zinc"}>{preEnrollment.status}</Badge>
						<Badge color="zinc">{preEnrollment.link_status}</Badge>
						<Badge color="zinc">{preEnrollment.murguia_status}</Badge>
					</div>
				</div>

				{successMessage ? <Notice>{successMessage}</Notice> : null}
				{errors.generate_credit ? <Notice color="red">{errors.generate_credit}</Notice> : null}
				{errors.murguia_register ? <Notice color="red">{errors.murguia_register}</Notice> : null}
				{errors.murguia_verify ? <Notice color="red">{errors.murguia_verify}</Notice> : null}
				{errors.murguia_retry ? <Notice color="red">{errors.murguia_retry}</Notice> : null}

					<CollaboratorIdentity identity={preEnrollment.identity} />

					<div className="grid gap-4 lg:grid-cols-2">
						<Panel title="Datos ODESSA" items={[
							["Acción", preEnrollment.source_action],
							["Fuente", `${preEnrollment.source_sheet || "—"} fila ${preEnrollment.source_row || "—"}`],
						]} />
						<Panel title="Membresía preparada" items={[
							["noCredito", preEnrollment.medical_attention_identifier || (preEnrollment.has_medical_attention_identifier ? "Reservado" : "Pendiente")],
							["Tipo", preEnrollment.membership_type],
							["Inicio", preEnrollment.membership_start_date],
							["Fin", preEnrollment.membership_end_date],
					]} />
					<Panel title="Murguía" items={[
						["Status", preEnrollment.murguia_status],
						["Sync", preEnrollment.murguia_synced_at],
						["Intentos", preEnrollment.murguia_attempts],
						["Pendiente desde", preEnrollment.murguia_pending_since],
						["Alta aceptada", preEnrollment.murguia_registration_acknowledged_at],
						["Última verificación", preEnrollment.murguia_checked_at],
						["HTTP", preEnrollment.murguia_last_http_status],
						["Resultado técnico", preEnrollment.murguia_last_event_label || preEnrollment.murguia_last_event_code],
						["Error registrado", preEnrollment.matching?.murguia_error_available ? "Sí" : "No"],
					]} />
						<Panel title="FAMEDIC" items={[
							["User", preEnrollment.linked_user ? `Detectado (#${preEnrollment.linked_user.id})` : "—"],
							["Customer", preEnrollment.linked_customer ? `Detectado (#${preEnrollment.linked_customer.id})` : "—"],
							["Cuenta ODESSA", preEnrollment.linked_odessa_account ? `Detectada (#${preEnrollment.linked_odessa_account.id})` : "—"],
							["Vinculado", preEnrollment.linked_at],
						]} />
				</div>

				<Panel title="Matching" items={[
					["Alertas", (preEnrollment.matching?.flags || []).join("; ") || "—"],
					["Bloqueo", preEnrollment.matching?.blocked_reason],
					["Otro correo detectado", preEnrollment.matching?.other_famedic_email_available ? "Sí" : "No"],
				]} wide />

				{canGenerateCredit ? (
					<section className="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
						<Subheading>Generar noCredito</Subheading>
						<Text className="mt-1 text-sm text-zinc-500">
							Feature flag: {creditGenerationEnabled ? "habilitado" : "deshabilitado"}. Esta acción no crea Customer, ODESSA account, membresía ni Murguía.
						</Text>
						<form onSubmit={submit} className="mt-4 grid gap-3 md:grid-cols-[1fr_14rem_auto]">
							<Textarea value={form.data.reason} onChange={(e) => form.setData("reason", e.target.value)} placeholder="Motivo obligatorio" />
							<Input value={form.data.confirmation} onChange={(e) => form.setData("confirmation", e.target.value)} placeholder="CONFIRMAR" />
							<Button type="submit" disabled={form.processing || !creditGenerationEnabled}>
								Generar noCredito
							</Button>
						</form>
					</section>
				) : null}

				{canRegisterMurguia || canVerifyMurguia || canRetryMurguia ? (
					<section className="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
						<Subheading>Acciones Murguía individuales</Subheading>
						<Text className="mt-1 text-sm text-zinc-500">
							Feature flag alta/verificación: {murguiaEnabled ? "habilitado" : "deshabilitado"}. Reintento: {murguiaRetryEnabled ? "habilitado" : "deshabilitado"}.
						</Text>
						{!murguiaContractConfigured ? (
							<Text className="mt-2 text-sm text-amber-700 dark:text-amber-300">Configuración contractual Murguía pendiente.</Text>
						) : null}
						<div className="mt-4 grid gap-3 lg:grid-cols-3">
							{canRegisterMurguia ? (
								<form onSubmit={register} className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
									<Text className="text-sm">Alta individual externa. Identidad: {identityLabel} · {identityEmail}.</Text>
									<Input className="mt-3" value={registerForm.data.confirmation} onChange={(e) => registerForm.setData("confirmation", e.target.value)} placeholder="REGISTRAR" />
									<Button className="mt-3" type="submit" disabled={registerForm.processing || !murguiaEnabled || !murguiaContractConfigured || !preEnrollment.has_medical_attention_identifier || registerForm.data.confirmation !== "REGISTRAR"}>
										Dar de alta en Murguía
									</Button>
								</form>
							) : null}
							{canVerifyMurguia ? (
								<form onSubmit={verify} className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
									<Text className="text-sm">Consulta read-back por identificador reservado sin mostrar su valor.</Text>
									<Button className="mt-3" type="submit" disabled={verifyForm.processing || !murguiaEnabled || !preEnrollment.has_medical_attention_identifier}>
										Verificar estado
									</Button>
								</form>
							) : null}
							{canRetryMurguia ? (
								<form onSubmit={retry} className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
									<Text className="text-sm">Reintento seguro: primero verifica Murguía y conserva la misma reserva.</Text>
									<Input className="mt-3" value={retryForm.data.confirmation} onChange={(e) => retryForm.setData("confirmation", e.target.value)} placeholder="REINTENTAR" />
									<Button className="mt-3" type="submit" disabled={retryForm.processing || !murguiaEnabled || !murguiaRetryEnabled || !retryStatusAllowed || !preEnrollment.has_medical_attention_identifier || retryForm.data.confirmation !== "REINTENTAR"}>
										Reintentar
									</Button>
								</form>
							) : null}
						</div>
					</section>
				) : null}

				<section className="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Auditoría</Subheading>
					<div className="mt-3 space-y-2 text-sm">
						{preEnrollment.audits.length === 0 ? <Text className="text-sm text-zinc-500">Sin acciones registradas.</Text> : preEnrollment.audits.map((audit, index) => (
							<div key={index} className="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
									<div className="font-medium">{audit.action_type} · {audit.performed_at || "—"} · {audit.performed_by ? `Usuario #${audit.performed_by.id}` : "—"}</div>
								<div className="mt-1 text-zinc-500">{audit.reason || "Sin motivo"}</div>
								<div className="mt-1 text-xs text-zinc-500">
									noCredito: {audit.summary?.credit_was_present ? "existía" : "no existía"} → {audit.summary?.credit_is_present ? "reservado" : "sin reserva"}
									{audit.summary?.murguia_last_event_code ? ` · Murguía: ${audit.summary.murguia_last_event_code}` : ""}
									{audit.summary?.http_status ? ` · HTTP ${audit.summary.http_status}` : ""}
								</div>
							</div>
						))}
					</div>
				</section>
			</div>
		</AdminLayout>
	);
}

function CollaboratorIdentity({ identity }) {
	const fullAccess = identity?.access === "full";
	const title = fullAccess ? identity?.full_name : identity?.name_initials;
	const items = fullAccess ? [
		["Empresa", identity?.company],
		["Número de empleado", identity?.employee_identifier],
		["ID ODESSA", identity?.odessa_identifier],
		["Correo ODESSA", identity?.masked_email],
		["Año de nacimiento", identity?.birth_year],
		["Acción", identity?.source_action],
		["Hoja/fila de origen", `${identity?.source_sheet || "—"} fila ${identity?.source_row || "—"}`],
	] : [
		["Empresa", identity?.company_masked],
		["Empleado", identity?.employee_identifier_masked],
		["ID ODESSA", identity?.odessa_identifier_masked],
		["Correo ODESSA", identity?.masked_email],
	];

	return (
		<section className="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Identificación del colaborador</Subheading>
			<div className="mt-3 text-lg font-semibold text-zinc-900 dark:text-zinc-100">{title || "—"}</div>
			<dl className="mt-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[11rem_1fr]">
				{items.map(([label, value]) => (
					<div key={label} className="contents">
						<dt className="text-zinc-500">{label}</dt>
						<dd className="break-words">{value || "—"}</dd>
					</div>
				))}
			</dl>
		</section>
	);
}

function Panel({ title, items, wide = false }) {
	return (
		<section className={`${wide ? "lg:col-span-2" : ""} rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900`}>
			<Subheading>{title}</Subheading>
			<dl className="mt-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[9rem_1fr]">
				{items.map(([label, value]) => (
					<div key={label} className="contents">
						<dt className="text-zinc-500">{label}</dt>
						<dd className="break-words">{value || "—"}</dd>
					</div>
				))}
			</dl>
		</section>
	);
}

function Notice({ color, children }) {
	return <div className={`rounded-lg border px-4 py-3 text-sm ${color === "red" ? "border-red-200 bg-red-50 text-red-900" : "border-emerald-200 bg-emerald-50 text-emerald-900"}`}>{children}</div>;
}
