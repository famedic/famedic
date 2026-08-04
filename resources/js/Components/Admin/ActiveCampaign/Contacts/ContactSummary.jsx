import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";

const UNAVAILABLE = "No disponible";

function Field({ label, value, full = false }) {
	const display =
		value === null || value === undefined || value === "" || value === "—"
			? UNAVAILABLE
			: value;

	const unavailable = display === UNAVAILABLE;

	return (
		<div
			className={
				full
					? "sm:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50/80 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-950/40"
					: "rounded-lg border border-zinc-200 bg-zinc-50/80 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-950/40"
			}
		>
			<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
				{label}
			</p>
			<p
				className={
					unavailable
						? "mt-1 text-sm text-zinc-400 dark:text-zinc-500"
						: "mt-1 text-sm font-medium text-zinc-900 dark:text-zinc-50"
				}
			>
				{display}
			</p>
		</div>
	);
}

function SummarySkeleton() {
	return (
		<div className="space-y-4" aria-busy="true" aria-label="Cargando resumen">
			<div className="flex items-center gap-3">
				<div className="size-14 animate-pulse rounded-full bg-zinc-200 dark:bg-zinc-800" />
				<div className="space-y-2">
					<div className="h-4 w-40 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
					<div className="h-3 w-28 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800" />
				</div>
			</div>
			<div className="grid gap-3 sm:grid-cols-2">
				{Array.from({ length: 8 }).map((_, i) => (
					<div
						key={i}
						className="h-14 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800"
					/>
				))}
			</div>
		</div>
	);
}

/**
 * Summary 360 — datos reales bajo demanda.
 */
export default function ContactSummary({ summary = null, loading = false }) {
	return (
		<ContactDrawerSection
			title="Resumen"
			description="Identidad del paciente y señales clave de CRM."
			truth="disponible"
		>
			{loading || !summary ? (
				<SummarySkeleton />
			) : (
				<div className="space-y-4">
					<div className="flex items-center gap-3">
						<Avatar
							initials={summary.initials || "?"}
							alt={summary.name || "Contacto"}
							className="size-14 bg-famedic-light/15 text-famedic-dark dark:bg-famedic-lime/10 dark:text-famedic-lime"
						/>
						<div className="min-w-0">
							<p className="truncate text-base font-semibold text-zinc-950 dark:text-white">
								{summary.name || UNAVAILABLE}
							</p>
							<div className="mt-1 flex flex-wrap items-center gap-2">
								{summary.status && summary.status !== UNAVAILABLE ? (
									<Badge color="emerald">{summary.status}</Badge>
								) : (
									<Badge color="zinc">{UNAVAILABLE}</Badge>
								)}
								<Text className="text-xs text-zinc-500 dark:text-zinc-400">
									Registro {summary.registered_at || UNAVAILABLE}
								</Text>
							</div>
						</div>
					</div>

					<div className="grid gap-3 sm:grid-cols-2">
						<Field label="Nombre" value={summary.name} />
						<Field label="Correo" value={summary.email} />
						<Field label="Teléfono" value={summary.phone} />
						<Field label="Ciudad" value={summary.city} />
						<Field label="Estado" value={summary.status} />
						<Field label="Fecha registro" value={summary.registered_at} />
						<Field label="Última actividad" value={summary.last_activity} />
						<Field label="Última compra" value={summary.last_purchase} full />
						<Field label="Membresía" value={summary.membership} full />
						<Field label="Laboratorio" value={summary.laboratory} />
						<Field
							label="Beneficiarios"
							value={
								summary.beneficiaries_count === null ||
								summary.beneficiaries_count === undefined
									? UNAVAILABLE
									: String(summary.beneficiaries_count)
							}
						/>
						<Field
							label="Tags"
							value={
								summary.tags_count === null ||
								summary.tags_count === undefined
									? UNAVAILABLE
									: String(summary.tags_count)
							}
						/>
						<Field label="Automatizaciones" value={summary.automations} />
					</div>
				</div>
			)}
		</ContactDrawerSection>
	);
}
