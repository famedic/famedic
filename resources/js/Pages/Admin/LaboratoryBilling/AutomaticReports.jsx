import { useEffect, useMemo, useRef, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import {
	Dropdown,
	DropdownButton,
	DropdownItem,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Textarea } from "@/Components/Catalyst/textarea";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import BillingNav from "@/Components/Admin/LaboratoryBilling/BillingNav";
import BillingPanel from "@/Components/Admin/LaboratoryBilling/BillingPanel";
import {
	billingMutedTextClass,
	billingSecondaryTextClass,
} from "@/Components/Admin/LaboratoryBilling/billingUi";
import {
	ArrowPathIcon,
	CalendarDaysIcon,
	CheckCircleIcon,
	ClockIcon,
	DocumentArrowDownIcon,
	EllipsisHorizontalIcon,
	EnvelopeIcon,
	EyeIcon,
	ExclamationTriangleIcon,
	PlusIcon,
	QueueListIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";
import clsx from "clsx";

const EMPTY_FORM = {
	name: "",
	is_active: false,
	weekdays: [],
	send_time: "08:00",
	timezone: "America/Monterrey",
	period_type: "previous_day",
	recipients: "",
	included_sections: [
		"activity",
		"backlog",
		"overdue",
		"completed",
		"aging",
		"missing_files",
	],
	include_excel: true,
	brand: "",
	laboratory_store_id: "",
	status: "",
};

const STATUS_LABELS = {
	pending: "Pendiente",
	processing: "Procesando",
	sent: "Enviado",
	failed: "Fallido",
	skipped: "Omitido",
	active: "Activo",
	paused: "Pausado",
};

const TYPE_LABELS = {
	scheduled: "Programado",
	manual: "Manual",
	test: "Prueba",
	manual_test: "Prueba",
};

function toTextRecipients(value) {
	return Array.isArray(value) ? value.join("\n") : value || "";
}

function recipientsFromText(value) {
	return String(value || "")
		.split(/[\n,;]/)
		.map((item) => item.trim().toLowerCase())
		.filter(Boolean)
		.filter((item, index, all) => all.indexOf(item) === index);
}

function amPm(time) {
	if (!time) return "Sin hora";
	const [h, m] = time.split(":").map(Number);
	const suffix = h >= 12 ? "PM" : "AM";
	const hour = h % 12 || 12;
	return `${hour}:${String(m || 0).padStart(2, "0")} ${suffix}`;
}

function formatWeekdays(values = [], options = []) {
	if (!values.length) return "Sin días";
	const labels = values
		.map((value) => options.find((day) => day.value === value)?.label)
		.filter(Boolean);
	if (values.join(",") === "1,2,3,4,5") return "Lunes a viernes";
	return labels.join(", ");
}

function groupedOptions(options = []) {
	return options.reduce((groups, option) => {
		const group = option.group || "Otros";
		return { ...groups, [group]: [...(groups[group] || []), option] };
	}, {});
}

function optionFor(value, options = []) {
	return options.find((option) => option.value === value);
}

function dateInputToday() {
	return new Date().toISOString().slice(0, 10);
}

function inclusiveDayCount(from, to) {
	if (!from || !to) return null;
	const start = new Date(`${from}T00:00:00`);
	const end = new Date(`${to}T00:00:00`);
	if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;

	return Math.floor((end - start) / 86400000) + 1;
}

function manualToken() {
	if (window.crypto?.randomUUID) return window.crypto.randomUUID();

	return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function statusTone(status) {
	return {
		pending: "bg-sky-50 text-sky-800 ring-sky-600/20",
		processing: "bg-indigo-50 text-indigo-800 ring-indigo-600/20",
		sent: "bg-lime-50 text-lime-800 ring-lime-600/20",
		failed: "bg-red-50 text-red-800 ring-red-600/20",
		skipped: "bg-zinc-50 text-zinc-700 ring-zinc-600/20",
		active: "bg-lime-50 text-lime-800 ring-lime-600/20",
		paused: "bg-zinc-50 text-zinc-700 ring-zinc-600/20",
	}[status] || "bg-zinc-50 text-zinc-700 ring-zinc-600/20";
}

function StatusPill({ status }) {
	return (
		<span
			className={clsx(
				"inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset",
				statusTone(status),
			)}
		>
			{STATUS_LABELS[status] || status || "-"}
		</span>
	);
}

function MetricCard({ label, value, helper, icon: Icon, tone = "slate" }) {
	const tones = {
		slate: "bg-slate-50 text-slate-700 ring-slate-200",
		lime: "bg-lime-50 text-lime-800 ring-lime-200",
		cyan: "bg-cyan-50 text-cyan-800 ring-cyan-200",
		amber: "bg-amber-50 text-amber-800 ring-amber-200",
	};

	return (
		<div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-zinc-950/5 dark:bg-zinc-900 dark:ring-white/10">
			<div className="flex items-start justify-between gap-3">
				<div>
					<p className="text-sm font-medium text-zinc-600 dark:text-zinc-300">
						{label}
					</p>
					<p className="mt-2 text-3xl font-semibold text-zinc-950 dark:text-white">
						{value ?? 0}
					</p>
					{helper ? (
						<p className={`mt-1 text-sm ${billingMutedTextClass}`}>{helper}</p>
					) : null}
				</div>
				<div className={clsx("rounded-lg p-2 ring-1", tones[tone])}>
					<Icon className="size-5" aria-hidden="true" />
				</div>
			</div>
		</div>
	);
}

function RecipientsList({ recipients = [] }) {
	if (!recipients.length) {
		return <span className="text-zinc-500">Sin destinatarios</span>;
	}

	return (
		<div className="flex min-w-56 max-w-sm flex-wrap gap-1.5">
			{recipients.map((recipient) => (
				<span
					key={recipient}
					className="rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700"
				>
					{recipient}
				</span>
			))}
		</div>
	);
}

function FieldError({ children }) {
	if (!children) return null;
	return (
		<Text className="mt-1 text-sm text-red-600">
			{Array.isArray(children) ? children[0] : children}
		</Text>
	);
}

function ToggleCard({ checked, label, description, onChange }) {
	return (
		<button
			type="button"
			onClick={() => onChange(!checked)}
			className={clsx(
				"rounded-lg border p-3 text-left text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime",
				checked
					? "border-famedic-light bg-cyan-50 text-zinc-950"
					: "border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200",
			)}
			aria-pressed={checked}
		>
			<span className="flex items-center gap-2 font-medium">
				<span
					className={clsx(
						"grid size-4 place-items-center rounded border",
						checked ? "border-famedic-dark bg-famedic-dark" : "border-zinc-300",
					)}
				>
					{checked ? <CheckCircleIcon className="size-3 text-white" /> : null}
				</span>
				{label}
			</span>
			{description ? (
				<span className="mt-1 block text-xs text-zinc-500">{description}</span>
			) : null}
		</button>
	);
}

function makeSchedulePayload(schedule, overrides = {}) {
	return {
		name: schedule.name || "",
		is_active: Boolean(schedule.is_active),
		weekdays: schedule.weekdays || [],
		send_time: schedule.send_time?.slice(0, 5) || "08:00",
		timezone: schedule.timezone || "America/Monterrey",
		period_type: schedule.period_type || "previous_day",
		recipients: toTextRecipients(schedule.recipients),
		included_sections: schedule.included_sections || EMPTY_FORM.included_sections,
		include_excel: Boolean(schedule.include_excel),
		brand: schedule.filters?.brand || "",
		laboratory_store_id: schedule.filters?.laboratory_store_id || "",
		status: schedule.filters?.status || "",
		...overrides,
	};
}

function FormDrawer({
	open,
	editing,
	form,
	options,
	config,
	storesByBrand,
	recipientList,
	onClose,
	onSubmit,
	onToggleArray,
	onRemoveRecipient,
}) {
	return (
		<Dialog open={open} onClose={onClose} size="6xl">
			<div className="flex items-start justify-between gap-4">
				<div>
					<DialogTitle>
						{editing ? "Editar reporte automático" : "Nuevo reporte automático"}
					</DialogTitle>
					<DialogDescription>
						Configura destinatarios, programación, filtros y contenido del correo.
					</DialogDescription>
				</div>
				<Button type="button" plain onClick={onClose} aria-label="Cerrar formulario">
					<XMarkIcon data-slot="icon" />
					Cerrar
				</Button>
			</div>

			<DialogBody className="max-h-[70vh] space-y-6 overflow-y-auto pr-1">
				<section className="grid gap-4 lg:grid-cols-[1fr,1.25fr]">
					<div>
						<Subheading>Información general</Subheading>
						<Text className={`mt-1 ${billingMutedTextClass}`}>
							Usa un nombre reconocible y agrega uno o más correos.
						</Text>
					</div>
					<div className="space-y-4">
						<Field>
							<Label htmlFor="report-name">Nombre</Label>
							<Input
								id="report-name"
								value={form.data.name}
								onChange={(event) => form.setData("name", event.target.value)}
								invalid={Boolean(form.errors.name)}
							/>
							<FieldError>{form.errors.name}</FieldError>
						</Field>
						<Field>
							<Label htmlFor="report-recipients">Destinatarios</Label>
							<Textarea
								id="report-recipients"
								rows={4}
								value={form.data.recipients}
								onChange={(event) =>
									form.setData("recipients", event.target.value)
								}
								placeholder="correo@dominio.com"
								invalid={Boolean(
									form.errors.recipients || form.errors["recipients.0"],
								)}
							/>
							<Text className={`mt-1 text-sm ${billingMutedTextClass}`}>
								Separa correos con salto de línea, coma o punto y coma.
							</Text>
							<FieldError>
								{form.errors.recipients || form.errors["recipients.0"]}
							</FieldError>
							{recipientList.length ? (
								<div className="mt-2 flex flex-wrap gap-2">
									{recipientList.map((recipient) => (
										<span
											key={recipient}
											className="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-2 py-1 text-xs text-zinc-700 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700"
										>
											{recipient}
											<button
												type="button"
												className="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime"
												onClick={() => onRemoveRecipient(recipient)}
												aria-label={`Eliminar ${recipient}`}
											>
												<XMarkIcon className="size-3" />
											</button>
										</span>
									))}
								</div>
							) : null}
						</Field>
					</div>
				</section>

				<section className="grid gap-4 lg:grid-cols-[1fr,1.25fr]">
					<div>
						<Subheading>Programación</Subheading>
						<Text className={`mt-1 ${billingMutedTextClass}`}>
							La ejecución automática se calcula en America/Monterrey.
						</Text>
					</div>
					<div className="grid gap-4 md:grid-cols-2">
						<Field>
							<Label htmlFor="report-period">Periodo</Label>
							<Select
								id="report-period"
								value={form.data.period_type}
								onChange={(event) =>
									form.setData("period_type", event.target.value)
								}
							>
								{Object.entries(groupedOptions(options.periods || [])).map(
									([group, periods]) => (
										<optgroup key={group} label={group}>
											{periods.map((period) => (
												<option key={period.value} value={period.value}>
													{period.label}
												</option>
											))}
										</optgroup>
									),
								)}
							</Select>
							{optionFor(form.data.period_type, options.periods || []) ? (
								<Text className={`mt-1 text-sm ${billingSecondaryTextClass}`}>
									{optionFor(form.data.period_type, options.periods || []).example}
								</Text>
							) : null}
							<FieldError>{form.errors.period_type}</FieldError>
						</Field>
						<Field>
							<Label htmlFor="report-time">Hora de envío</Label>
							<Input
								id="report-time"
								type="time"
								value={form.data.send_time}
								onChange={(event) =>
									form.setData("send_time", event.target.value)
								}
								invalid={Boolean(form.errors.send_time)}
							/>
							<Text className={`mt-1 text-sm ${billingSecondaryTextClass}`}>
								{amPm(form.data.send_time)} · America/Monterrey
							</Text>
							<FieldError>{form.errors.send_time}</FieldError>
						</Field>
						<Field className="md:col-span-2">
							<Label>Días de envío</Label>
							<div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
								{(options.weekdays || []).map((day) => (
									<ToggleCard
										key={day.value}
										checked={(form.data.weekdays || []).includes(day.value)}
										label={day.label}
										onChange={() => onToggleArray("weekdays", day.value)}
									/>
								))}
							</div>
							<FieldError>{form.errors.weekdays}</FieldError>
						</Field>
						<Field className="md:col-span-2">
							<Label>Zona horaria</Label>
							<div className="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
								America/Monterrey
							</div>
						</Field>
					</div>
				</section>

				<section className="grid gap-4 lg:grid-cols-[1fr,1.25fr]">
					<div>
						<Subheading>Alcance del reporte</Subheading>
						<Text className={`mt-1 ${billingMutedTextClass}`}>
							Estos filtros se aplican a actividad y backlog.
						</Text>
					</div>
					<div className="grid gap-4 md:grid-cols-3">
						<Field>
							<Label htmlFor="report-brand">Marca</Label>
							<Select
								id="report-brand"
								value={form.data.brand}
								onChange={(event) => {
									form.setData("brand", event.target.value);
									form.setData("laboratory_store_id", "");
								}}
							>
								<option value="">Todas</option>
								{(options.brands || []).map((brand) => (
									<option key={brand.value} value={brand.value}>
										{brand.label}
									</option>
								))}
							</Select>
						</Field>
						<Field>
							<Label htmlFor="report-store">Sucursal</Label>
							<Select
								id="report-store"
								value={form.data.laboratory_store_id}
								onChange={(event) =>
									form.setData("laboratory_store_id", event.target.value)
								}
							>
								<option value="">Todas</option>
								{storesByBrand.map((store) => (
									<option key={store.value} value={store.value}>
										{store.label}
									</option>
								))}
							</Select>
							<FieldError>{form.errors.laboratory_store_id}</FieldError>
						</Field>
						<Field>
							<Label htmlFor="report-status">Estado de facturación</Label>
							<Select
								id="report-status"
								value={form.data.status}
								onChange={(event) => form.setData("status", event.target.value)}
							>
								<option value="">Todos</option>
								{(options.statuses || []).map((status) => (
									<option key={status.value} value={status.value}>
										{status.label}
									</option>
								))}
							</Select>
						</Field>
					</div>
				</section>

				<section className="grid gap-4 lg:grid-cols-[1fr,1.25fr]">
					<div>
						<Subheading>Contenido incluido</Subheading>
						<Text className={`mt-1 ${billingMutedTextClass}`}>
							El correo y el Excel usan las mismas secciones.
						</Text>
					</div>
					<div className="grid gap-2 sm:grid-cols-2">
						{(options.sections || []).map((section) => (
							<ToggleCard
								key={section.value}
								checked={(form.data.included_sections || []).includes(
									section.value,
								)}
								label={section.label}
								onChange={() => onToggleArray("included_sections", section.value)}
							/>
						))}
						<ToggleCard
							checked={form.data.include_excel}
							label="Generar Excel"
							description={`Adjunto hasta ${config.maxAttachmentMb} MB; si excede, enlace por ${config.linkTtlHours} h.`}
							onChange={(checked) => form.setData("include_excel", checked)}
						/>
					</div>
				</section>

				<section className="grid gap-4 lg:grid-cols-[1fr,1.25fr]">
					<div>
						<Subheading>Estado</Subheading>
						<Text className={`mt-1 ${billingMutedTextClass}`}>
							Una configuración pausada conserva historial y reglas.
						</Text>
					</div>
					<ToggleCard
						checked={form.data.is_active}
						label={
							form.data.is_active
								? "Configuración activa"
								: "Configuración pausada"
						}
						description={
							form.data.is_active
								? "Se ejecutará automáticamente cuando coincidan día y hora."
								: "No tendrá próxima ejecución automática."
						}
						onChange={(checked) => form.setData("is_active", checked)}
					/>
				</section>
			</DialogBody>

			<DialogActions>
				<Button type="button" plain onClick={onClose} disabled={form.processing}>
					Cancelar
				</Button>
				<Button type="button" onClick={onSubmit} disabled={form.processing}>
					{form.processing ? "Guardando..." : "Guardar configuración"}
				</Button>
			</DialogActions>
		</Dialog>
	);
}

function PreviewDrawer({
	open,
	preview,
	loading,
	error,
	schedule,
	onClose,
	onRetry,
	onSendTest,
	testProcessing,
}) {
	const closeButtonRef = useRef(null);

	useEffect(() => {
		if (!open) return undefined;

		const handleKeyDown = (event) => {
			if (event.key === "Escape") onClose();
		};

		document.addEventListener("keydown", handleKeyDown);
		closeButtonRef.current?.focus();

		return () => document.removeEventListener("keydown", handleKeyDown);
	}, [open, onClose]);

	if (!open) return null;

	const metrics = preview?.metrics || {};
	const metricCards = [
		["Solicitudes recibidas", metrics.received ?? 0],
		["Completadas", metrics.completed ?? 0],
		["Pendientes actuales", metrics.pending_backlog ?? 0],
		["Atrasadas", metrics.overdue_backlog ?? 0],
	];

	return (
		<div className="fixed inset-0 z-50" role="dialog" aria-modal="true">
			<button
				type="button"
				className="absolute inset-0 bg-slate-950/30"
				onClick={onClose}
				aria-label="Cerrar vista previa"
			/>
			<aside className="absolute right-0 top-0 flex h-full w-full flex-col bg-zinc-50 shadow-2xl ring-1 ring-zinc-950/10 sm:max-w-2xl dark:bg-zinc-950 dark:ring-white/10">
				<header className="border-b border-zinc-200 bg-white px-5 py-4 dark:border-zinc-800 dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3">
						<div>
							<h2 className="text-lg font-semibold text-zinc-950 dark:text-white">
								Vista previa del correo
							</h2>
							<p className={`mt-1 text-sm ${billingMutedTextClass}`}>
								Así se verá el reporte en la bandeja de entrada.
							</p>
						</div>
						<Button
							type="button"
							plain
							onClick={onClose}
							aria-label="Cerrar"
							ref={closeButtonRef}
						>
							<XMarkIcon data-slot="icon" />
							Cerrar
						</Button>
					</div>
				</header>

				<div className="flex-1 overflow-y-auto p-5">
					{loading ? (
						<div className="space-y-4" aria-live="polite">
							<div className="h-24 animate-pulse rounded-lg bg-zinc-200 dark:bg-zinc-800" />
							<div className="grid gap-3 sm:grid-cols-2">
								{[1, 2, 3, 4].map((item) => (
									<div
										key={item}
										className="h-24 animate-pulse rounded-lg bg-zinc-200 dark:bg-zinc-800"
									/>
								))}
							</div>
						</div>
					) : error ? (
						<div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
							<p className="font-medium">No se pudo cargar la vista previa.</p>
							<p className="mt-1">{error}</p>
							<Button type="button" outline className="mt-3" onClick={onRetry}>
								Volver a intentar
							</Button>
						</div>
					) : (
						<div className="mx-auto max-w-xl overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-zinc-950/10 dark:bg-zinc-900 dark:ring-white/10">
							<div className="bg-famedic-dark px-5 py-4 text-white">
								<p className="text-sm font-semibold tracking-wide">FAMEDIC</p>
								<p className="mt-3 text-xs text-cyan-100">Asunto</p>
								<h3 className="text-base font-semibold">{preview?.subject}</h3>
							</div>
							<div className="space-y-5 p-5">
								{preview?.is_test ? (
									<span className="inline-flex rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-600/20">
										[PRUEBA]
									</span>
								) : null}
								<div>
									<h3 className="text-xl font-semibold text-zinc-950 dark:text-white">
										{preview?.copy?.headline || "Resumen de facturación"}
									</h3>
									<p className={`mt-2 text-sm ${billingSecondaryTextClass}`}>
										{preview?.copy?.intro}
									</p>
								</div>
								<div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
									<p className="font-medium text-zinc-950 dark:text-white">
										{preview?.schedule?.name || schedule?.name}
									</p>
									<dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
										<div>
											<dt className={billingMutedTextClass}>Periodo</dt>
											<dd className="font-medium text-zinc-900 dark:text-zinc-100">
												{preview?.period?.label}
											</dd>
										</div>
										<div>
											<dt className={billingMutedTextClass}>Generado</dt>
											<dd className="font-medium text-zinc-900 dark:text-zinc-100">
												{preview?.generated_at}
											</dd>
										</div>
										<div>
											<dt className={billingMutedTextClass}>Zona horaria</dt>
											<dd className="font-medium text-zinc-900 dark:text-zinc-100">
												{preview?.period?.timezone}
											</dd>
										</div>
										<div>
											<dt className={billingMutedTextClass}>Destinatarios</dt>
											<dd className="font-medium text-zinc-900 dark:text-zinc-100">
												{preview?.schedule?.recipients_count ?? 0}
											</dd>
										</div>
									</dl>
								</div>
								<div className="grid gap-3 sm:grid-cols-2">
									{metricCards.map(([label, value]) => (
										<div
											key={label}
											className="rounded-lg bg-zinc-50 p-4 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700"
										>
											<p className="text-sm text-zinc-600 dark:text-zinc-300">
												{label}
											</p>
											<p className="mt-1 text-2xl font-semibold text-zinc-950 dark:text-white">
												{value}
											</p>
										</div>
									))}
								</div>
								{(metrics.overdue_backlog ?? 0) > 0 ? (
									<div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
										<p className="flex items-center gap-2 font-semibold">
											<ExclamationTriangleIcon className="size-4" />
											{metrics.overdue_backlog} solicitudes requieren atención
										</p>
									</div>
								) : null}
								<div className="grid gap-3 text-sm sm:grid-cols-2">
									<div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
										<p className="font-medium text-zinc-950 dark:text-white">
											Cumplimiento
										</p>
										<p className={billingSecondaryTextClass}>
											{metrics.compliance_percent ?? 0}% · Promedio{" "}
											{metrics.average_response_hours === null
												? "sin datos"
												: `${metrics.average_response_hours} h`}
										</p>
									</div>
									<div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
										<p className="font-medium text-zinc-950 dark:text-white">
											Archivo
										</p>
										<p className={billingSecondaryTextClass}>
											{preview?.excel?.enabled
												? preview?.excel?.filename
												: "Excel desactivado"}
										</p>
										<p className={`mt-1 text-xs ${billingMutedTextClass}`}>
											{preview?.excel?.delivery_hint}
										</p>
									</div>
								</div>
								<p className={`text-sm ${billingSecondaryTextClass}`}>
									{preview?.copy?.closing}
								</p>
							</div>
						</div>
					)}
				</div>

				<footer className="flex flex-col-reverse gap-3 border-t border-zinc-200 bg-white px-5 py-4 sm:flex-row sm:justify-end dark:border-zinc-800 dark:bg-zinc-900">
					<Button type="button" plain onClick={onClose}>
						Cerrar
					</Button>
					<Button
						type="button"
						onClick={onSendTest}
						disabled={!schedule || testProcessing}
					>
						{testProcessing ? "Encolando..." : "Enviar prueba"}
					</Button>
				</footer>
			</aside>
		</div>
	);
}

function ManualRunDialog({
	open,
	schedule,
	options,
	state,
	preview,
	loading,
	errors,
	processing,
	onClose,
	onChange,
	onQuickRange,
	onPreview,
	onSubmit,
}) {
	if (!open || !schedule) return null;

	const selectedConfigured = optionFor(schedule.period_type, options.periods || []);
	const selectedManual = optionFor(state.period_type, options.manualPeriods || []);
	const dayCount = inclusiveDayCount(state.custom_from, state.custom_to);
	const customEnabled = state.period_type === "custom_range";
	const tooLong = customEnabled && dayCount !== null && dayCount > 366;
	const inverted = customEnabled && dayCount !== null && dayCount < 1;
	const future = customEnabled && state.custom_to > dateInputToday();

	return (
		<Dialog open={open} onClose={onClose} size="3xl">
			<DialogTitle>Ejecutar reporte manual</DialogTitle>
			<DialogDescription>
				{schedule.name} conservará su programación y próxima ejecución.
			</DialogDescription>

			<DialogBody className="space-y-5">
				<div className="grid gap-3 sm:grid-cols-2">
					<ToggleCard
						checked={!customEnabled}
						label="Usar periodo configurado"
						description={
							selectedConfigured?.label
								? `${selectedConfigured.label} · ${selectedConfigured.example}`
								: "Usa el periodo recurrente guardado."
						}
						onChange={() =>
							onChange({
								period_type: schedule.period_type || "previous_day",
								custom_from: "",
								custom_to: "",
							})
						}
					/>
					<ToggleCard
						checked={customEnabled}
						label="Utilizar un rango personalizado"
						description="Disponible solo para esta ejecución manual."
						onChange={() =>
							onChange({
								period_type: "custom_range",
								custom_from: state.custom_from,
								custom_to: state.custom_to,
							})
						}
					/>
				</div>

				{customEnabled ? (
					<div className="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
						<div className="flex flex-wrap gap-2">
							{(options.quickRanges || []).map((range) => (
								<Button
									key={range.value}
									type="button"
									outline
									onClick={() => onQuickRange(range)}
								>
									{range.label}
								</Button>
							))}
						</div>
						<div className="grid gap-4 sm:grid-cols-2">
							<Field>
								<Label htmlFor="manual-custom-from">Fecha inicial</Label>
								<Input
									id="manual-custom-from"
									type="date"
									max={dateInputToday()}
									value={state.custom_from}
									onChange={(event) =>
										onChange({ custom_from: event.target.value })
									}
									invalid={Boolean(errors.custom_from || inverted)}
								/>
								<FieldError>
									{errors.custom_from ||
										(inverted ? "La fecha inicial no puede ser posterior." : null)}
								</FieldError>
							</Field>
							<Field>
								<Label htmlFor="manual-custom-to">Fecha final</Label>
								<Input
									id="manual-custom-to"
									type="date"
									max={dateInputToday()}
									value={state.custom_to}
									onChange={(event) =>
										onChange({ custom_to: event.target.value })
									}
									invalid={Boolean(errors.custom_to || tooLong || future)}
								/>
								<FieldError>
									{errors.custom_to ||
										(tooLong
											? "Máximo 366 días por ejecución."
											: future
												? "No se permiten fechas futuras."
												: null)}
								</FieldError>
							</Field>
						</div>
						<div className="rounded-lg bg-zinc-50 px-3 py-2 text-sm text-zinc-700 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700">
							{state.custom_from && state.custom_to ? (
								<>
									Periodo personalizado: {state.custom_from}–{state.custom_to}
									{dayCount && dayCount > 0 ? ` · ${dayCount} días` : ""}
								</>
							) : (
								"Selecciona una fecha inicial y final."
							)}
						</div>
					</div>
				) : (
					<div className="rounded-lg bg-zinc-50 px-3 py-2 text-sm text-zinc-700 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700">
						{selectedManual?.label || selectedConfigured?.label} ·{" "}
						{selectedManual?.example || selectedConfigured?.example}
					</div>
				)}

				<div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
					<div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
						<div>
							<p className="font-medium text-zinc-950 dark:text-white">
								Vista previa de esta ejecución
							</p>
							<p className={`text-sm ${billingMutedTextClass}`}>
								Se actualiza solo cuando lo solicitas.
							</p>
						</div>
						<Button
							type="button"
							outline
							onClick={onPreview}
							disabled={loading || processing}
						>
							<EyeIcon data-slot="icon" />
							{loading ? "Actualizando..." : "Actualizar vista previa"}
						</Button>
					</div>
					{preview ? (
						<div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
							<div>
								<p className={billingMutedTextClass}>
									{preview.period?.is_custom
										? "Periodo personalizado"
										: preview.period?.type_label}
								</p>
								<p className="font-medium text-zinc-900 dark:text-zinc-100">
									{preview.period?.date_label || preview.period?.label}
								</p>
							</div>
							<div>
								<p className={billingMutedTextClass}>Métricas</p>
								<p className="font-medium text-zinc-900 dark:text-zinc-100">
									{preview.metrics?.received ?? 0} recibidas ·{" "}
									{preview.metrics?.pending_backlog ?? 0} pendientes
								</p>
							</div>
						</div>
					) : null}
					<FieldError>{errors.preview}</FieldError>
				</div>
			</DialogBody>

			<DialogActions>
				<Button type="button" plain onClick={onClose} disabled={processing}>
					Cancelar
				</Button>
				<Button
					type="button"
					onClick={onSubmit}
					disabled={processing || loading || tooLong || inverted || future}
				>
					{processing ? "Encolando..." : "Generar reporte"}
				</Button>
			</DialogActions>
		</Dialog>
	);
}

export default function AutomaticReports({
	summary = {},
	schedules,
	runs,
	filters = {},
	options = {},
	config = {},
	canManageAutomaticReports = true,
}) {
	const [activeTab, setActiveTab] = useState(filters.tab || "configurations");
	const [editing, setEditing] = useState(null);
	const [isFormOpen, setIsFormOpen] = useState(false);
	const [manualProcessing, setManualProcessing] = useState({});
	const [manualDialog, setManualDialog] = useState({
		open: false,
		schedule: null,
		state: {
			period_type: "previous_day",
			custom_from: "",
			custom_to: "",
			idempotency_key: "",
		},
		preview: null,
		loading: false,
		errors: {},
	});
	const [previewState, setPreviewState] = useState({
		open: false,
		schedule: null,
		data: null,
		loading: false,
		error: null,
	});
	const [runFilters, setRunFilters] = useState({
		run_status: filters.run_status || "",
		run_type: filters.run_type || "",
		run_from: filters.run_from || "",
		run_to: filters.run_to || "",
	});
	const form = useForm(EMPTY_FORM);

	const recipientList = useMemo(
		() => recipientsFromText(form.data.recipients),
		[form.data.recipients],
	);
	const storesByBrand = useMemo(() => {
		if (!form.data.brand) return options.stores || [];
		return (options.stores || []).filter(
			(store) => store.brand === form.data.brand || store.value === "__none__",
		);
	}, [form.data.brand, options.stores]);

	const startCreate = () => {
		setEditing(null);
		form.setData(EMPTY_FORM);
		form.clearErrors();
		setIsFormOpen(true);
	};

	const startEdit = (schedule) => {
		setEditing(schedule);
		form.setData(makeSchedulePayload(schedule));
		form.clearErrors();
		setIsFormOpen(true);
	};

	const closeForm = () => {
		if (!form.processing) setIsFormOpen(false);
	};

	const toggleArray = (field, value) => {
		const current = form.data[field] || [];
		form.setData(
			field,
			current.includes(value)
				? current.filter((item) => item !== value)
				: [...current, value],
		);
	};

	const removeRecipient = (recipient) => {
		form.setData(
			"recipients",
			recipientList.filter((item) => item !== recipient).join("\n"),
		);
	};

	const submit = () => {
		const options = {
			preserveScroll: true,
			onSuccess: () => setIsFormOpen(false),
		};
		if (editing) {
			form.put(
				route("admin.laboratory-billing.automatic-reports.update", editing.id),
				options,
			);
			return;
		}
		form.post(route("admin.laboratory-billing.automatic-reports.store"), options);
	};

	const updateScheduleState = (schedule, nextActive) => {
		const message = nextActive
			? "¿Reactivar esta configuración?"
			: "¿Pausar esta configuración?";
		if (!window.confirm(message)) return;

		const key = `${schedule.id}:state`;
		setManualProcessing((current) => ({ ...current, [key]: true }));
		router.put(
			route("admin.laboratory-billing.automatic-reports.update", schedule.id),
			makeSchedulePayload(schedule, { is_active: nextActive }),
			{
				preserveScroll: true,
				onFinish: () =>
					setManualProcessing((current) => {
						const next = { ...current };
						delete next[key];
						return next;
					}),
			},
		);
	};

	const dispatchRun = (schedule, type, payload = null) => {
		const period = payload || {};
		const message =
			type === "test"
				? "¿Enviar prueba del reporte a los destinatarios configurados?"
				: "¿Ejecutar ahora este reporte y enviar correo?";
		if (!window.confirm(message)) return false;

		const key = `${schedule.id}:${type}`;
		setManualProcessing((current) => ({ ...current, [key]: true }));
		router.post(
			route(
				type === "test"
					? "admin.laboratory-billing.automatic-reports.test"
					: "admin.laboratory-billing.automatic-reports.run",
				schedule.id,
			),
			period,
			{
				preserveScroll: true,
				onFinish: () =>
					setManualProcessing((current) => {
						const next = { ...current };
						delete next[key];
						return next;
					}),
			},
		);

		return true;
	};

	const openManualDialog = (schedule) => {
		setManualDialog({
			open: true,
			schedule,
			state: {
				period_type: schedule.period_type || "previous_day",
				custom_from: "",
				custom_to: "",
				idempotency_key: manualToken(),
			},
			preview: null,
			loading: false,
			errors: {},
		});
	};

	const closeManualDialog = () => {
		if (manualProcessing[`${manualDialog.schedule?.id}:manual`]) return;
		setManualDialog((current) => ({ ...current, open: false }));
	};

	const updateManualState = (patch) => {
		setManualDialog((current) => ({
			...current,
			state: { ...current.state, ...patch },
			preview: null,
			errors: {},
		}));
	};

	const applyQuickRange = (range) => {
		updateManualState({
			period_type: "custom_range",
			custom_from: range.from,
			custom_to: range.to,
		});
	};

	const validateManualState = () => {
		const state = manualDialog.state;
		const errors = {};

		if (state.period_type === "custom_range") {
			const days = inclusiveDayCount(state.custom_from, state.custom_to);
			if (!state.custom_from) errors.custom_from = "Selecciona la fecha inicial.";
			if (!state.custom_to) errors.custom_to = "Selecciona la fecha final.";
			if (days !== null && days < 1) {
				errors.custom_from = "La fecha inicial no puede ser posterior.";
			}
			if (state.custom_to && state.custom_to > dateInputToday()) {
				errors.custom_to = "No se permiten fechas futuras.";
			}
			if (days !== null && days > 366) {
				errors.custom_to = "Máximo 366 días por ejecución.";
			}
		}

		setManualDialog((current) => ({ ...current, errors }));

		return Object.keys(errors).length === 0;
	};

	const manualPayload = () => {
		const state = manualDialog.state;
		return Object.fromEntries(
			Object.entries({
				period_type: state.period_type,
				custom_from:
					state.period_type === "custom_range" ? state.custom_from : undefined,
				custom_to:
					state.period_type === "custom_range" ? state.custom_to : undefined,
				idempotency_key: state.idempotency_key,
			}).filter(([, value]) => value !== undefined && value !== ""),
		);
	};

	const previewManualRun = async () => {
		if (!manualDialog.schedule || manualDialog.loading || !validateManualState()) {
			return;
		}

		setManualDialog((current) => ({ ...current, loading: true, errors: {} }));

		try {
			const response = await window.axios.get(
				route(
					"admin.laboratory-billing.automatic-reports.preview",
					manualDialog.schedule.id,
				),
				{ params: manualPayload() },
			);
			setManualDialog((current) => ({
				...current,
				loading: false,
				preview: response.data,
			}));
		} catch (error) {
			setManualDialog((current) => ({
				...current,
				loading: false,
				errors: {
					...(error?.response?.data?.errors || {}),
					preview:
						error?.response?.data?.message ||
						"No fue posible preparar la vista previa.",
				},
			}));
		}
	};

	const submitManualRun = () => {
		if (!manualDialog.schedule || !validateManualState()) return;

		if (dispatchRun(manualDialog.schedule, "manual", manualPayload())) {
			setManualDialog((current) => ({ ...current, open: false }));
		}
	};

	const loadPreview = async (schedule, asTest = false) => {
		setPreviewState({
			open: true,
			schedule,
			data: null,
			loading: true,
			error: null,
		});

		try {
			const response = await window.axios.get(
				route("admin.laboratory-billing.automatic-reports.preview", schedule.id),
				{ params: { test: asTest ? 1 : 0 } },
			);
			setPreviewState({
				open: true,
				schedule,
				data: response.data,
				loading: false,
				error: null,
			});
		} catch (error) {
			setPreviewState((current) => ({
				...current,
				loading: false,
				error:
					error?.response?.data?.message ||
					"No fue posible preparar la vista previa.",
			}));
		}
	};

	const applyRunFilters = () => {
		router.get(
			route("admin.laboratory-billing.automatic-reports.index"),
			Object.fromEntries(
				Object.entries({ ...runFilters, tab: "history" }).filter(
					([, value]) => value !== "",
				),
			),
			{ preserveState: true, preserveScroll: true },
		);
	};

	const schedulesData = schedules?.data || [];
	const runsData = runs?.data || [];

	return (
		<AdminLayout title="Reportes automáticos de facturación">
			<div className="space-y-6">
				<div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
					<div>
						<Heading>Reportes automáticos de facturación</Heading>
						<Text className={`mt-1 ${billingMutedTextClass}`}>
							Programa, consulta y envía reportes sin trabajo manual.
						</Text>
					</div>
					<Button type="button" onClick={startCreate}>
						<PlusIcon data-slot="icon" />
						Nuevo reporte
					</Button>
				</div>

				<BillingNav
					active="automatic-reports"
					canManageAutomaticReports={canManageAutomaticReports}
				/>

				<section className="grid gap-3 md:grid-cols-3">
					<MetricCard
						label="Configuraciones"
						value={summary.total}
						icon={QueueListIcon}
					/>
					<MetricCard
						label="Activas"
						value={summary.active}
						helper="En ejecución automática"
						icon={CheckCircleIcon}
						tone="lime"
					/>
					<MetricCard
						label="Envíos recientes"
						value={summary.recentRuns}
						helper="Últimos 7 días"
						icon={EnvelopeIcon}
						tone="cyan"
					/>
				</section>

				<div
					className="flex border-b border-zinc-200 dark:border-zinc-700"
					role="tablist"
					aria-label="Reportes automáticos"
				>
					{[
						["configurations", "Configuraciones"],
						["history", "Historial de envíos"],
					].map(([key, label]) => (
						<button
							key={key}
							type="button"
							role="tab"
							aria-selected={activeTab === key}
							onClick={() => setActiveTab(key)}
							className={clsx(
								"-mb-px border-b-2 px-4 py-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime",
								activeTab === key
									? "border-famedic-light text-famedic-dark dark:text-famedic-lime"
									: "border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100",
							)}
						>
							{label}
						</button>
					))}
				</div>

				{activeTab === "configurations" ? (
					<section className="space-y-4">
						{schedulesData.length === 0 ? (
							<BillingPanel>
								<div className="flex flex-col items-start gap-3 py-6 sm:flex-row sm:items-center sm:justify-between">
									<div>
										<Subheading>Aún no tienes reportes automáticos</Subheading>
										<Text className={`mt-1 ${billingMutedTextClass}`}>
											Crea una configuración para recibir periódicamente el resumen
											de facturación.
										</Text>
									</div>
									<Button type="button" onClick={startCreate}>
										<PlusIcon data-slot="icon" />
										Crear primer reporte
									</Button>
								</div>
							</BillingPanel>
						) : (
							<PaginatedTable paginatedData={schedules}>
								<div className="space-y-3">
									{schedulesData.map((schedule) => {
										const manualKey = `${schedule.id}:manual`;
										const testKey = `${schedule.id}:test`;
										const stateKey = `${schedule.id}:state`;
										return (
											<article
												key={schedule.id}
												className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-zinc-950/5 dark:bg-zinc-900 dark:ring-white/10"
											>
												<div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
													<div className="min-w-0 flex-1">
														<div className="flex flex-wrap items-center gap-2">
															<h3 className="truncate text-base font-semibold text-zinc-950 dark:text-white">
																{schedule.name}
															</h3>
															<StatusPill
																status={schedule.is_active ? "active" : "paused"}
															/>
														</div>
														<p className={`mt-1 text-sm ${billingMutedTextClass}`}>
															{schedule.period_type
																? `Periodo: ${
																		(options.periods || []).find(
																			(period) =>
																				period.value === schedule.period_type,
																		)?.label || schedule.period_type
																	}`
																: "Sin descripción configurada"}
														</p>
														<div className="mt-4 grid gap-3 text-sm text-zinc-700 sm:grid-cols-2 dark:text-zinc-200">
															<div className="flex gap-2">
																<CalendarDaysIcon className="mt-0.5 size-4 text-famedic-light" />
																<span>
																	{formatWeekdays(
																		schedule.weekdays || [],
																		options.weekdays || [],
																	)}{" "}
																	· {amPm(schedule.send_time)}
																</span>
															</div>
															<div className="flex gap-2">
																<ClockIcon className="mt-0.5 size-4 text-famedic-light" />
																<span>{schedule.timezone}</span>
															</div>
															<div className="flex gap-2">
																<EnvelopeIcon className="mt-0.5 size-4 text-famedic-light" />
																<span>
																	{schedule.recipients?.length || 0}{" "}
																	{(schedule.recipients?.length || 0) === 1
																		? "destinatario"
																		: "destinatarios"}
																</span>
															</div>
															<div className="flex gap-2">
																<ArrowPathIcon className="mt-0.5 size-4 text-famedic-light" />
																<span>
																	{schedule.next_run_at || "Sin programar"}
																</span>
															</div>
														</div>
													</div>
													<div className="flex flex-wrap items-center gap-2">
														<Button
															type="button"
															outline
															onClick={() => loadPreview(schedule)}
														>
															<EyeIcon data-slot="icon" />
															Vista previa
														</Button>
														<Button
															type="button"
															outline
															disabled={manualProcessing[testKey]}
															onClick={() => dispatchRun(schedule, "test")}
														>
															<EnvelopeIcon data-slot="icon" />
															{manualProcessing[testKey]
																? "Encolando..."
																: "Enviar prueba"}
														</Button>
														<Dropdown>
															<DropdownButton
																outline
																aria-label={`Más acciones para ${schedule.name}`}
															>
																<EllipsisHorizontalIcon data-slot="icon" />
																Más
															</DropdownButton>
															<DropdownMenu anchor="bottom end">
																<DropdownItem onClick={() => startEdit(schedule)}>
																	Editar
																</DropdownItem>
																	<DropdownItem
																		disabled={manualProcessing[manualKey]}
																		onClick={() => openManualDialog(schedule)}
																	>
																		Ejecutar ahora
																	</DropdownItem>
																<DropdownItem
																	disabled={manualProcessing[stateKey]}
																	onClick={() =>
																		updateScheduleState(
																			schedule,
																			!schedule.is_active,
																		)
																	}
																>
																	{schedule.is_active ? "Pausar" : "Activar"}
																</DropdownItem>
															</DropdownMenu>
														</Dropdown>
													</div>
												</div>
											</article>
										);
									})}
								</div>
							</PaginatedTable>
						)}
					</section>
				) : (
					<BillingPanel>
						<div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
							<div>
								<Subheading>Historial de envíos</Subheading>
								<Text className={`mt-1 ${billingMutedTextClass}`}>
									Consulta cada ejecución y los correos usados como destinatarios.
								</Text>
							</div>
							<div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
								<Select
									aria-label="Filtrar por estado"
									value={runFilters.run_status}
									onChange={(event) =>
										setRunFilters((current) => ({
											...current,
											run_status: event.target.value,
										}))
									}
								>
									<option value="">Estado</option>
									<option value="pending">Pendiente</option>
									<option value="processing">Procesando</option>
									<option value="sent">Enviado</option>
									<option value="failed">Fallido</option>
									<option value="skipped">Omitido</option>
								</Select>
								<Select
									aria-label="Filtrar por tipo"
									value={runFilters.run_type}
									onChange={(event) =>
										setRunFilters((current) => ({
											...current,
											run_type: event.target.value,
										}))
									}
								>
									<option value="">Tipo</option>
									<option value="scheduled">Programado</option>
									<option value="manual">Manual</option>
									<option value="test">Prueba</option>
								</Select>
								<Input
									aria-label="Fecha inicial"
									type="date"
									value={runFilters.run_from}
									onChange={(event) =>
										setRunFilters((current) => ({
											...current,
											run_from: event.target.value,
										}))
									}
								/>
								<Input
									aria-label="Fecha final"
									type="date"
									value={runFilters.run_to}
									onChange={(event) =>
										setRunFilters((current) => ({
											...current,
											run_to: event.target.value,
										}))
									}
								/>
								<Button type="button" outline onClick={applyRunFilters}>
									Filtrar
								</Button>
							</div>
						</div>
						{runsData.length === 0 ? (
							<div className="mt-6 rounded-lg border border-dashed border-zinc-300 p-6 dark:border-zinc-700">
								<Subheading>Todavía no hay envíos</Subheading>
								<Text className={`mt-1 ${billingMutedTextClass}`}>
									Los reportes enviados, omitidos o fallidos aparecerán aquí.
								</Text>
							</div>
						) : (
							<PaginatedTable paginatedData={runs}>
								<div className="mt-4 overflow-x-auto">
									<Table bleed>
										<TableHead>
											<TableRow>
												<TableHeader>Reporte</TableHeader>
												<TableHeader>Destinatarios</TableHeader>
												<TableHeader>Tipo</TableHeader>
												<TableHeader>Estado</TableHeader>
												<TableHeader>Periodo</TableHeader>
												<TableHeader>Totales</TableHeader>
												<TableHeader>Archivo</TableHeader>
												<TableHeader>Fecha de ejecución</TableHeader>
												<TableHeader>Error o detalle</TableHeader>
											</TableRow>
										</TableHead>
										<TableBody>
											{runsData.map((run) => (
												<TableRow key={run.id}>
													<TableCell>{run.schedule_name || "-"}</TableCell>
													<TableCell>
														<RecipientsList recipients={run.recipients || []} />
													</TableCell>
													<TableCell>
														{TYPE_LABELS[run.run_type] || run.run_type || "-"}
													</TableCell>
													<TableCell>
														<StatusPill status={run.status} />
													</TableCell>
													<TableCell>{run.period_label || run.period || "-"}</TableCell>
													<TableCell>
														<div className="grid min-w-48 grid-cols-2 gap-2 text-xs">
															<span>Recibidas: {run.metrics?.received ?? 0}</span>
															<span>Completadas: {run.metrics?.completed ?? 0}</span>
															<span>
																Pendientes: {run.metrics?.pending_backlog ?? 0}
															</span>
															<span>
																Atrasadas: {run.metrics?.overdue_backlog ?? 0}
															</span>
														</div>
													</TableCell>
													<TableCell>
														{run.download_url ? (
															<Button href={run.download_url} plain>
																<DocumentArrowDownIcon data-slot="icon" />
																Descargar
															</Button>
														) : (
															run.delivery_method || "-"
														)}
													</TableCell>
													<TableCell>
														{run.started_at || run.sent_at || run.finished_at || "-"}
													</TableCell>
													<TableCell className="max-w-xs">
														<span
															className="line-clamp-2"
															title={run.error_message || ""}
														>
															{run.error_message || "-"}
														</span>
													</TableCell>
												</TableRow>
											))}
										</TableBody>
									</Table>
								</div>
							</PaginatedTable>
						)}
					</BillingPanel>
				)}
			</div>

			<FormDrawer
				open={isFormOpen}
				editing={editing}
				form={form}
				options={options}
				config={config}
				storesByBrand={storesByBrand}
				recipientList={recipientList}
				onClose={closeForm}
				onSubmit={submit}
				onToggleArray={toggleArray}
				onRemoveRecipient={removeRecipient}
			/>

			<ManualRunDialog
				open={manualDialog.open}
				schedule={manualDialog.schedule}
				options={options}
				state={manualDialog.state}
				preview={manualDialog.preview}
				loading={manualDialog.loading}
				errors={manualDialog.errors}
				processing={
					manualDialog.schedule
						? Boolean(manualProcessing[`${manualDialog.schedule.id}:manual`])
						: false
				}
				onClose={closeManualDialog}
				onChange={updateManualState}
				onQuickRange={applyQuickRange}
				onPreview={previewManualRun}
				onSubmit={submitManualRun}
			/>

			<PreviewDrawer
				open={previewState.open}
				preview={previewState.data}
				loading={previewState.loading}
				error={previewState.error}
				schedule={previewState.schedule}
				onClose={() =>
					setPreviewState({
						open: false,
						schedule: null,
						data: null,
						loading: false,
						error: null,
					})
				}
				onRetry={() => previewState.schedule && loadPreview(previewState.schedule)}
				onSendTest={() =>
					previewState.schedule && dispatchRun(previewState.schedule, "test")
				}
				testProcessing={
					previewState.schedule
						? Boolean(manualProcessing[`${previewState.schedule.id}:test`])
						: false
				}
			/>
		</AdminLayout>
	);
}
