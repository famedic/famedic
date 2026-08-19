import { Link, useForm } from "@inertiajs/react";
import { ArrowLeftIcon } from "@heroicons/react/16/solid";

import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import { Textarea } from "@/Components/Catalyst/textarea";

export default function Show({ preEnrollment, canGenerateCredit, generateCreditEnabled, successMessage, errors = {} }) {
	const form = useForm({ reason: "", confirmation: "" });
	const submit = (event) => {
		event.preventDefault();
		form.post(route("admin.odessa.pre-enrollments.generate-credit", preEnrollment.id), { preserveScroll: true });
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

					<div className="grid gap-4 lg:grid-cols-2">
						<Panel title="Datos ODESSA" items={[
							["Acción", preEnrollment.source_action],
							["Fuente", `${preEnrollment.source_sheet || "—"} fila ${preEnrollment.source_row || "—"}`],
							["Empresa", preEnrollment.identity?.company_external_identifier_masked],
							["Empleado", preEnrollment.identity?.employee_identifier_masked],
							["ID ODESSA", preEnrollment.identity?.odessa_identifier_masked],
							["Iniciales", preEnrollment.identity?.name_initials],
							["Año nacimiento", preEnrollment.identity?.birth_year],
							["Correo ODESSA", preEnrollment.identity?.source_email_masked],
						]} />
						<Panel title="Membresía preparada" items={[
							["noCredito", preEnrollment.has_medical_attention_identifier ? "Reservado" : "Pendiente"],
							["Tipo", preEnrollment.membership_type],
							["Inicio", preEnrollment.membership_start_date],
							["Fin", preEnrollment.membership_end_date],
					]} />
					<Panel title="Murguía" items={[
						["Status", preEnrollment.murguia_status],
						["Sync", preEnrollment.murguia_synced_at],
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
							Feature flag: {generateCreditEnabled ? "habilitado" : "deshabilitado"}. Esta acción no crea Customer, ODESSA account, membresía ni Murguía.
						</Text>
						<form onSubmit={submit} className="mt-4 grid gap-3 md:grid-cols-[1fr_14rem_auto]">
							<Textarea value={form.data.reason} onChange={(e) => form.setData("reason", e.target.value)} placeholder="Motivo obligatorio" />
							<Input value={form.data.confirmation} onChange={(e) => form.setData("confirmation", e.target.value)} placeholder="CONFIRMAR" />
							<Button type="submit" disabled={form.processing || !generateCreditEnabled}>
								Generar noCredito
							</Button>
						</form>
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
								</div>
							</div>
						))}
					</div>
				</section>
			</div>
		</AdminLayout>
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
