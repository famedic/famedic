import Card from "@/Components/Card";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { CreditCardIcon } from "@heroicons/react/24/outline";
import { ChevronDownIcon } from "@heroicons/react/20/solid";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import clsx from "clsx";

function SummaryCard({ title, description, children, className }) {
	return (
		<Card
			className={clsx(
				"flex h-full flex-col rounded-2xl p-5 shadow-sm ring-1 ring-slate-100 sm:p-6 dark:ring-slate-700/80",
				className,
			)}
		>
			<div className="mb-5 shrink-0">
				<h4 className="font-poppins text-base font-semibold text-famedic-dark sm:text-lg dark:text-white">
					{title}
				</h4>
				{description && (
					<p className="mt-1 text-sm leading-relaxed text-zinc-500 dark:text-slate-400">
						{description}
					</p>
				)}
			</div>
			<div className="flex min-h-0 flex-1 flex-col">{children}</div>
		</Card>
	);
}

function SummaryRow({ label, children, value, breakAll = false, compact = false }) {
	return (
		<div
			className={clsx(
				!compact && "border-b border-slate-100 py-3.5 last:border-b-0 dark:border-slate-800",
			)}
		>
			<dt className="text-xs font-medium uppercase tracking-wide text-zinc-400 dark:text-slate-500">
				{label}
			</dt>
			<dd
				className={clsx(
					"mt-1 text-sm font-medium leading-snug text-zinc-900 dark:text-slate-100",
					breakAll ? "break-all" : "break-words",
					!compact && "mt-1",
				)}
			>
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
			<div className="flex flex-col gap-4 sm:flex-row sm:items-start">
				<Avatar
					src={holder.avatarUrl}
					initials={holder.initials}
					className="size-14 shrink-0 bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200"
				/>
				<div className="min-w-0 flex-1">
					<p className="font-poppins text-lg font-semibold leading-snug text-zinc-900 dark:text-white">
						{holder.name}
					</p>
					<p className="mt-1 text-sm text-zinc-500 dark:text-slate-400">
						{holder.userType}
					</p>
					<div className="mt-3">
						<Badge color={holder.statusKey === "active" ? "emerald" : "zinc"}>
							{holder.status}
						</Badge>
					</div>
				</div>
			</div>

			<Disclosure as="div" className="mt-3">
				{({ open }) => (
					<>
						<DisclosureButton className="flex w-full items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2.5 text-left text-sm font-medium text-zinc-700 transition hover:bg-slate-100 dark:bg-slate-800/50 dark:text-slate-200 dark:hover:bg-slate-800">
							<span>Ver datos de contacto</span>
							<ChevronDownIcon
								className={clsx(
									"size-4 shrink-0 text-zinc-400 transition-transform",
									open && "rotate-180",
								)}
							/>
						</DisclosureButton>
						<DisclosurePanel className="mt-2 overflow-hidden rounded-xl ring-1 ring-slate-100 dark:ring-slate-800">
							<dl className="divide-y divide-slate-100 dark:divide-slate-800">
								<div className="px-3 py-3">
									<SummaryRow
										label="Correo"
										value={holder.email}
										breakAll
										compact
									/>
								</div>
								<div className="px-3 py-3">
									<SummaryRow
										label="Teléfono"
										value={holder.formattedPhone ?? holder.phone}
										compact
									/>
								</div>
								<div className="px-3 py-3">
									<SummaryRow
										label="Nacimiento"
										value={holder.birthDate}
										compact
									/>
								</div>
							</dl>
						</DisclosurePanel>
					</>
				)}
			</Disclosure>

			<div className="mt-5 shrink-0 border-t border-slate-100 pt-5 dark:border-slate-800">
				<Button outline href={holder.editUrl} className="w-full sm:w-auto">
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
			<div className="mb-4 flex items-start gap-3 rounded-xl bg-slate-50 p-3.5 dark:bg-slate-800/50">
				<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300">
					<CreditCardIcon className="size-5" />
				</div>
				<div className="min-w-0">
					<p className="font-medium text-zinc-900 dark:text-white">
						Último pago
					</p>
					<p className="mt-0.5 text-sm text-zinc-500 dark:text-slate-400">
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
		<div className="flex flex-1 items-center rounded-xl bg-slate-50 p-4 dark:bg-slate-800/50">
			<p className="text-sm leading-relaxed text-zinc-600 dark:text-slate-300">
				Las estadísticas de uso estarán disponibles pronto. Mientras tanto,
				accede a telemedicina y asistencias desde Atención médica.
			</p>
		</div>
	);
}

export default function MembershipSummary({ holder, plan, payment }) {
	return (
		<div className="grid grid-cols-1 gap-5 sm:gap-6 md:grid-cols-2 min-[1400px]:grid-cols-4">
			<SummaryCard
				title="Información del titular"
				description="Datos de contacto y perfil."
				className="md:col-span-2 min-[1400px]:col-span-1"
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

			<SummaryCard
				title="Uso rápido"
				description="Actividad reciente."
				className="md:col-span-2 min-[1400px]:col-span-1"
			>
				<UsageSummary />
			</SummaryCard>
		</div>
	);
}
