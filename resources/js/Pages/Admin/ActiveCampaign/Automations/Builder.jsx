import { useMemo, useState } from "react";
import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import { AutomationNav } from "@/Components/Admin/ActiveCampaign/Automations/AutomationMetrics";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	proximamente: { label: "Próximamente", color: "zinc" },
};

function StepColumn({ title, subtitle, children }) {
	return (
		<div className="flex min-h-[280px] flex-col rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="mb-3 space-y-1">
				<h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					{title}
				</h3>
				<p className="text-[11px] text-zinc-400">{subtitle}</p>
			</div>
			<div className="flex-1 space-y-2">{children}</div>
		</div>
	);
}

function SelectableChip({
	active,
	label,
	meta,
	truth,
	onClick,
	disabled = false,
}) {
	const t = TRUTH[truth] || TRUTH.proximamente;
	return (
		<button
			type="button"
			disabled={disabled}
			onClick={onClick}
			className={`w-full rounded-xl border px-3 py-2 text-left transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-famedic-dark dark:focus-visible:outline-famedic-light ${
				active
					? "border-famedic-light bg-sky-50 dark:border-sky-700 dark:bg-sky-950/40"
					: "border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
			} ${disabled ? "opacity-50" : ""}`}
		>
			<div className="flex items-start justify-between gap-2">
				<div className="min-w-0">
					<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
						{label}
					</p>
					{meta ? (
						<p className="text-[11px] text-zinc-400">{meta}</p>
					) : null}
				</div>
				<Badge color={t.color}>{t.label}</Badge>
			</div>
		</button>
	);
}

export default function AutomationBuilder({
	events = [],
	conditionTemplates = [],
	actionTemplates = [],
	preset = null,
	save = {},
	links = {},
	meta = {},
}) {
	const [eventId, setEventId] = useState(preset?.event || "");
	const [conditionIds, setConditionIds] = useState(
		preset?.conditions?.length ? preset.conditions : ["always"],
	);
	const [actionIds, setActionIds] = useState(preset?.actions || []);

	const selectedEvent = useMemo(
		() => events.find((e) => e.id === eventId) || null,
		[events, eventId],
	);

	const toggle = (list, id, setter) => {
		setter(
			list.includes(id) ? list.filter((x) => x !== id) : [...list, id],
		);
	};

	return (
		<AdminLayout title="Marketing Intelligence · Automation Builder">
			<div className="space-y-6 pb-6">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<Link
						href={route("admin.activecampaign.automations")}
						className="font-medium text-zinc-400 transition hover:text-famedic-light"
					>
						Automation Center
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300 dark:text-zinc-600" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Builder
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Builder visual</Heading>
							<Badge color="sky">Sin drag & drop</Badge>
							{preset?.name ? (
								<Badge color="famedic">{preset.name}</Badge>
							) : null}
						</div>
						<Text className="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
							{meta?.note ||
								"Estructura Evento → Condiciones → Acciones. Persistencia próximamente."}
						</Text>
					</div>
					<AutomationNav active="builder" links={links} />
				</div>

				{/* Flujo visual */}
				<div className="grid gap-3 lg:grid-cols-[1fr_auto_1fr_auto_1fr] lg:items-stretch">
					<StepColumn
						title="1 · Evento"
						subtitle="Triggers Timeline / Event Center / Scheduler"
					>
						{events.map((event) => (
							<SelectableChip
								key={event.id}
								active={eventId === event.id}
								label={event.label}
								meta={event.family}
								truth={event.truth}
								onClick={() => setEventId(event.id)}
							/>
						))}
					</StepColumn>

					<div className="hidden items-center justify-center lg:flex">
						<div className="text-2xl text-zinc-300 dark:text-zinc-600">↓</div>
					</div>

					<StepColumn
						title="2 · Condiciones"
						subtitle="Filtros previos a la acción"
					>
						{conditionTemplates.map((c) => (
							<SelectableChip
								key={c.id}
								active={conditionIds.includes(c.id)}
								label={c.label}
								truth={c.truth}
								onClick={() =>
									toggle(conditionIds, c.id, setConditionIds)
								}
							/>
						))}
					</StepColumn>

					<div className="hidden items-center justify-center lg:flex">
						<div className="text-2xl text-zinc-300 dark:text-zinc-600">↓</div>
					</div>

					<StepColumn
						title="3 · Acciones"
						subtitle="Canales Integrations Hub / Famedic"
					>
						{actionTemplates.map((a) => (
							<SelectableChip
								key={a.id}
								active={actionIds.includes(a.id)}
								label={a.label}
								meta={a.channel}
								truth={a.truth}
								onClick={() => toggle(actionIds, a.id, setActionIds)}
							/>
						))}
					</StepColumn>
				</div>

				<ChartCard
					title="Resumen del flujo"
					description="Vista previa — no se persiste en esta versión."
				>
					<div className="grid gap-3 sm:grid-cols-3 text-sm">
						<div>
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								Evento
							</p>
							<p className="mt-1 font-medium">
								{selectedEvent?.label || "Sin seleccionar"}
							</p>
						</div>
						<div>
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								Condiciones
							</p>
							<p className="mt-1 font-medium">
								{conditionIds.length
									? conditionIds.join(", ")
									: "Ninguna"}
							</p>
						</div>
						<div>
							<p className="text-[11px] uppercase tracking-wide text-zinc-400">
								Acciones
							</p>
							<p className="mt-1 font-medium">
								{actionIds.length ? actionIds.join(", ") : "Ninguna"}
							</p>
						</div>
					</div>

					<div className="mt-4 flex flex-wrap gap-2">
						<Button disabled title={save?.hint || "Próximamente"}>
							Guardar automatización
						</Button>
						<p className="self-center text-[11px] text-zinc-400">
							{save?.hint || "Próximamente"}
						</p>
					</div>
				</ChartCard>
			</div>
		</AdminLayout>
	);
}
