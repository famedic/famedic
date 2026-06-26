import Card from "@/Components/Card";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { CreditCardIcon } from "@heroicons/react/24/outline";
import clsx from "clsx";

function SummaryCard({ title, description, children, className }) {
	return (
		<Card
			className={clsx(
				"flex h-full min-w-[min(100%,280px)] snap-start flex-col rounded-2xl p-5 shadow-sm ring-1 ring-slate-100 dark:ring-slate-700/80 sm:min-w-[300px] lg:min-w-0",
				className,
			)}
		>
			<div className="mb-4 shrink-0 border-b border-slate-100 pb-4 dark:border-slate-800">
				<h4 className="font-poppins font-semibold text-famedic-dark dark:text-white">
					{title}
				</h4>
				{description && (
					<p className="mt-1 text-sm text-zinc-500 dark:text-slate-400">
						{description}
					</p>
				)}
			</div>
			<div className="flex min-h-0 flex-1 flex-col">{children}</div>
		</Card>
	);
}

function SummaryRow({ label, children, value }) {
	return (
		<div className="flex items-start justify-between gap-3 border-b border-slate-100 py-2.5 text-sm last:border-b-0 dark:border-slate-800">
			<dt className="shrink-0 text-zinc-500 dark:text-slate-400">{label}</dt>
			<dd className="text-right font-medium text-zinc-900 dark:text-slate-100">
				{children ?? value ?? "—"}
			</dd>
		</div>
	);
}

function HolderSummary({ holder }) {
	if (!holder) {
		return (
			<Text className="text-sm text-zinc-500 dark:text-slate-400">
				No hay información del titular disponible.
			</Text>
		);
	}

	return (
		<>
			<div className="flex items-start gap-3">
				<Avatar
					src={holder.avatarUrl}
					initials={holder.initials}
					className="size-12 bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200"
				/>
				<div className="min-w-0 flex-1">
					<p className="truncate font-poppins font-semibold text-zinc-900 dark:text-white">
						{holder.name}
					</p>
					<p className="text-sm text-zinc-500 dark:text-slate-400">
						{holder.userType}
					</p>
					<div className="mt-2">
						<Badge color={holder.statusKey === "active" ? "emerald" : "zinc"}>
							{holder.status}
						</Badge>
					</div>
				</div>
			</div>

			<dl className="mt-4 flex-1 space-y-0">
				<SummaryRow label="Correo" value={holder.email} />
				<SummaryRow
					label="Teléfono"
					value={holder.formattedPhone ?? holder.phone}
				/>
				<SummaryRow label="Nacimiento" value={holder.birthDate} />
			</dl>

			<div className="mt-4 shrink-0 pt-2">
				<Button outline href={holder.editUrl} className="w-full">
					Editar información
				</Button>
			</div>
		</>
	);
}

function PlanSummary({ plan }) {
	if (!plan) {
		return (
			<Text className="text-sm text-zinc-500 dark:text-slate-400">
				No hay información del plan disponible.
			</Text>
		);
	}

	const statusColor =
		plan.status === "Pagado"
			? "emerald"
			: plan.status === "Pendiente"
				? "amber"
				: "sky";

	return (
		<dl className="flex-1 space-y-0">
			<SummaryRow label="Plan" value={plan.name} />
			<SummaryRow label="Precio" value={plan.price} />
			<SummaryRow label="Renovación" value={plan.renewalDate ?? "—"} />
			<SummaryRow label="Estado">
				<Badge color={statusColor}>{plan.status}</Badge>
			</SummaryRow>
		</dl>
	);
}

function PaymentSummary({ payment }) {
	if (!payment) {
		return (
			<Text className="text-sm text-zinc-500 dark:text-slate-400">
				No hay pagos registrados.
			</Text>
		);
	}

	return (
		<>
			<div className="mb-3 flex items-center gap-3">
				<div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300">
					<CreditCardIcon className="size-5" />
				</div>
				<div className="min-w-0">
					<p className="font-medium text-zinc-900 dark:text-white">
						Último pago
					</p>
					<p className="text-xs text-zinc-500 dark:text-slate-400">
						{payment.date} · {payment.time}
					</p>
				</div>
			</div>
			<dl className="flex-1 space-y-0">
				<SummaryRow label="Monto" value={payment.amount} />
				<SummaryRow label="Método" value={payment.method} />
				<SummaryRow label="Estado">
					<Badge
						color={payment.statusKey === "success" ? "emerald" : "amber"}
					>
						{payment.status}
					</Badge>
				</SummaryRow>
			</dl>
		</>
	);
}

function UsageSummary() {
	return (
		<p className="flex-1 text-sm leading-relaxed text-zinc-600 dark:text-slate-300">
			Las estadísticas de uso estarán disponibles pronto. Mientras tanto,
			accede a telemedicina y asistencias desde Atención médica.
		</p>
	);
}

export default function MembershipSummary({ holder, plan, payment }) {
	return (
		<div className="flex snap-x snap-mandatory gap-4 overflow-x-auto overscroll-x-contain pb-1 [-webkit-overflow-scrolling:touch] lg:grid lg:snap-none lg:grid-cols-2 lg:overflow-visible xl:grid-cols-4">
			<SummaryCard
				title="Información del titular"
				description="Datos de contacto y perfil."
			>
				<HolderSummary holder={holder} />
			</SummaryCard>

			<SummaryCard
				title="Resumen del plan"
				description="Detalles de tu membresía actual."
			>
				<PlanSummary plan={plan} />
			</SummaryCard>

			<SummaryCard
				title="Pago más reciente"
				description="Tu última transacción."
			>
				<PaymentSummary payment={payment} />
			</SummaryCard>

			<SummaryCard title="Uso rápido" description="Actividad reciente.">
				<UsageSummary />
			</SummaryCard>
		</div>
	);
}
