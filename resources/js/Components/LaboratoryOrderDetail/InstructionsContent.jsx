import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { MapPinIcon } from "@heroicons/react/24/outline";
import {
	formatStoreHours,
	formatStoreLocationLines,
	hasConfirmedLaboratoryStore,
} from "@/lib/laboratoryOrderStoreUi";

const BRAND_LABELS = {
	swisslab: "Swisslab",
	olab: "Olab",
	jenner: "Jenner",
	liacsa: "Liacsa",
	azteca: "Azteca",
};

function brandLabel(brand) {
	if (!brand) return "Laboratorio";
	const key = String(brand).toLowerCase();
	return BRAND_LABELS[key] || String(brand).replace(/_/g, " ");
}

function patientDisplayName(purchase) {
	if (purchase?.temporarly_hide_gda_order_id) return "Nombre de paciente pendiente";
	return purchase?.full_name || "—";
}

function InstructionCard({ title, emoji, children, id }) {
	return (
		<Card
			id={id}
			className="min-w-0 max-w-full scroll-mt-24 overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6"
		>
			<h3 className="mb-4 flex items-center gap-2 text-base font-semibold text-zinc-900 dark:text-white">
				{emoji && <span aria-hidden>{emoji}</span>}
				{title}
			</h3>
			<div className="space-y-3 text-sm leading-relaxed text-zinc-700 dark:text-slate-200">
				{children}
			</div>
		</Card>
	);
}

export default function InstructionsContent({
	purchase,
	orderType,
	hasAppointment,
	appointment,
	studiesWithIndications,
	hasConfirmedStore = hasConfirmedLaboratoryStore(appointment),
}) {
	const brand = brandLabel(purchase?.brand);
	const store = hasConfirmedStore ? appointment?.laboratory_store : null;
	const appointmentDate =
		appointment?.formatted_appointment_date || appointment?.appointment_date || "—";
	const appointmentHour = appointment?.formatted_appointment_hour || null;
	const gdaOrder = purchase?.gda_order_id ?? "—";
	const gdaConsecutivo = purchase?.gda_consecutivo ?? "—";
	const birth = purchase?.formatted_birth_date || "—";
	const patient = patientDisplayName(purchase);
	const showSinCita = orderType === "without_appointment" || orderType === "mixed";
	const showConCita = (orderType === "with_appointment" || orderType === "mixed") && hasAppointment;
	const storeLocationLines = formatStoreLocationLines(store);
	const storeHours = formatStoreHours(store);

	return (
		<div className="min-w-0 max-w-full space-y-6">
			<InstructionCard title="Qué hacer en sucursal" emoji="🪪">
				<p className="font-medium text-zinc-900 dark:text-white">
					Presenta tu identificación en sucursal (muéstrala tal cual).
				</p>
				<ul className="mt-3 space-y-2 border-t border-zinc-100 pt-3 dark:border-slate-700">
					<li className="flex gap-2">
						<span className="text-famedic-600 dark:text-famedic-400">🔹</span>
						<span>
							<strong>Consecutivo:</strong> {gdaConsecutivo}
						</span>
					</li>
					<li className="flex gap-2">
						<span className="text-famedic-600 dark:text-famedic-400">🔹</span>
						<span>
							<strong>Folio de orden:</strong> {gdaOrder}
						</span>
					</li>
					<li className="flex gap-2">
						<span className="text-famedic-600 dark:text-famedic-400">🔹</span>
						<span>
							<strong>Paciente:</strong> {patient}
						</span>
					</li>
					<li className="flex gap-2">
						<span className="text-famedic-600 dark:text-famedic-400">🔹</span>
						<span>
							<strong>Fecha de nacimiento:</strong> {birth}
						</span>
					</li>
				</ul>
			</InstructionCard>

			{hasConfirmedStore && store && (
				<InstructionCard title="Información de tu sucursal" emoji="📍">
					<ul className="space-y-2">
						{store.name && (
							<li>
								<strong>{store.name}</strong>
							</li>
						)}
						{storeLocationLines.map((line) => (
							<li key={line}>{line}</li>
						))}
						{storeHours && (
							<li>
								<span className="font-medium">Horarios: </span>
								{storeHours}
							</li>
						)}
					</ul>
					{store.google_maps_url && (
						<Button
							outline
							href={store.google_maps_url}
							target="_blank"
							rel="noopener noreferrer"
							className="mt-3 w-full max-w-full justify-center sm:w-auto"
						>
							<MapPinIcon className="size-4" />
							Ver ubicación
						</Button>
					)}
				</InstructionCard>
			)}

			{!hasConfirmedStore && hasAppointment && (
				<InstructionCard title="Sucursal de tu cita" emoji="📍">
					<p className="font-medium text-zinc-900 dark:text-white">
						Tu sucursal todavía no está confirmada.
					</p>
					<p className="text-zinc-600 dark:text-slate-400">
						Nuestro equipo de Concierge puede confirmar la sucursal y el horario de tu cita
						contigo.
					</p>
				</InstructionCard>
			)}

			{showConCita && (
				<InstructionCard title="Datos de tu cita" emoji="📅">
					<ul className="space-y-2">
						<li className="flex gap-2">
							<span>🏥</span>
							<span>
								<strong>Laboratorio / marca:</strong> {brand}
							</span>
						</li>
						<li className="flex gap-2">
							<span>📆</span>
							<span>
								<strong>Fecha de la cita:</strong> {appointmentDate}
							</span>
						</li>
						{appointmentHour && (
							<li className="flex gap-2">
								<span>⏰</span>
								<span>
									<strong>Hora de la cita:</strong> {appointmentHour}
								</span>
							</li>
						)}
					</ul>
				</InstructionCard>
			)}

			{showSinCita && !hasConfirmedStore && (
				<InstructionCard title="Sin cita" emoji="🔎">
					<p className="font-medium">¿A dónde puedes ir?</p>
					<p>
						Tus estudios no requieren cita. Puedes acudir en cualquier momento dentro del horario de
						atención de la sucursal que elijas.
					</p>
					<p className="text-zinc-600 dark:text-slate-400">
						Consulta direcciones, horarios y teléfono en la pestaña Sucursales.
					</p>
					<Button
						outline
						href={route("laboratory-stores.index", { brand: purchase?.brand })}
						className="mt-2 w-full max-w-full justify-center sm:w-auto"
					>
						<MapPinIcon className="size-4" />
						Consultar sucursales
					</Button>
				</InstructionCard>
			)}

			<InstructionCard title="Antes de ir" emoji="🧠">
				<ul className="list-disc space-y-2 pl-5">
					<li>Llega 10 minutos antes.</li>
					<li>Lleva identificación oficial del paciente.</li>
					<li>Ten a la mano tu folio y los datos del paciente (arriba).</li>
				</ul>
			</InstructionCard>

			<InstructionCard title="Al llegar (paso a paso)" emoji="🚶‍♂️">
				<ol className="list-decimal space-y-3 pl-5">
					<li>
						Comparte tus identificadores: consecutivo, folio de orden, nombre del paciente y fecha de
						nacimiento.
					</li>
					<li>
						{hasAppointment && appointmentDate !== "—" ? (
							<>
								Confirma tu cita: <strong>{appointmentDate}</strong>
								{appointmentHour ? (
									<>
										{" "}
										<strong>{appointmentHour}</strong>
									</>
								) : null}{" "}
								en <strong>{brand}</strong>
								{store?.name ? (
									<>
										{" "}
										sucursal <strong>{store.name}</strong>
									</>
								) : null}
								.
							</>
						) : (
							<>Indica en recepción que vienes por tus estudios y muestra tu identificación.</>
						)}
					</li>
				</ol>
			</InstructionCard>

			<InstructionCard
				id="indicaciones-preparacion-estudios"
				title="Preparación para tus estudios"
				emoji="🧪"
			>
				{studiesWithIndications.length > 0 ? (
					<div className="space-y-6">
						{studiesWithIndications.map((study) => (
							<div
								key={study.id}
								className="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-slate-700 dark:bg-slate-800/50"
							>
								<p className="mb-2 flex items-center gap-2 font-semibold text-zinc-900 dark:text-white">
									<span>🔬</span> {study.name}
								</p>
								<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-slate-400">
									Preparación / indicaciones
								</p>
								<div className="mt-2 whitespace-pre-wrap text-zinc-700 dark:text-slate-200">
									{study.indications?.trim() ? study.indications : "Sin indicaciones registradas."}
								</div>
							</div>
						))}
					</div>
				) : (
					<p className="text-sm text-zinc-500 dark:text-slate-400">
						No hay estudios con indicaciones de preparación para esta orden.
					</p>
				)}
			</InstructionCard>
		</div>
	);
}
